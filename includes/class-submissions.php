<?php
/**
 * Stored submissions (lf_submission post type) and their admin screens.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Submission storage and viewing.
 */
class Submissions {

	const POST_TYPE    = 'lf_submission';
	const META_FORM    = '_lf_form_id';
	const META_ENTRIES = '_lf_entries';
	const META_LOG     = '_lf_log';
	const META_PAGE    = '_lf_page_url';

	/** @var Uploads */
	private $uploads;

	/**
	 * Constructor.
	 *
	 * @param Uploads $uploads Uploads.
	 */
	public function __construct( Uploads $uploads ) {
		$this->uploads = $uploads;
	}

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'before_delete_post', array( $this, 'delete_files' ) );
		add_action( 'admin_post_launch_forms_download', array( $this, 'handle_download' ) );
		add_action( 'admin_post_nopriv_launch_forms_download', array( $this, 'handle_logged_out_download' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'form_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_form_filter' ) );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'meta_boxes' ) );
	}

	/**
	 * Register the post type. All capabilities map to the plugin's manage capability.
	 */
	public function register() {
		$cap = Plugin::manage_capability();
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Submissions', 'launch-forms' ),
					'singular_name' => __( 'Submission', 'launch-forms' ),
					'edit_item'     => __( 'View Submission', 'launch-forms' ),
					'search_items'  => __( 'Search Submissions', 'launch-forms' ),
					'not_found'     => __( 'No submissions yet.', 'launch-forms' ),
					'all_items'     => __( 'Submissions', 'launch-forms' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . Forms::POST_TYPE,
				'show_in_rest'        => false,
				'supports'            => false,
				'rewrite'             => false,
				'query_var'           => false,
				'capabilities'        => array(
					'edit_post'              => $cap,
					'read_post'              => $cap,
					'delete_post'            => $cap,
					'edit_posts'             => $cap,
					'edit_others_posts'      => $cap,
					'delete_posts'           => $cap,
					'delete_others_posts'    => $cap,
					'delete_published_posts' => $cap,
					'publish_posts'          => $cap,
					'read_private_posts'     => $cap,
					'create_posts'           => 'do_not_allow',
				),
				'map_meta_cap'        => false,
			)
		);
	}

	/**
	 * Store a submission.
	 *
	 * @param Form  $form    Form.
	 * @param array $entries Entries.
	 * @param array $context Context.
	 * @return int Submission ID (0 on failure).
	 */
	public function create( Form $form, array $entries, array $context = array() ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_parent' => $form->id,
				'post_author' => (int) ( $context['user_id'] ?? 0 ),
				/* translators: %s: form title. */
				'post_title'  => sprintf( __( '%s submission', 'launch-forms' ), $form->title ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_post_meta( $id, self::META_FORM, $form->id );
		update_post_meta( $id, self::META_ENTRIES, wp_slash( wp_json_encode( $entries ) ) );
		if ( ! empty( $context['page_url'] ) ) {
			update_post_meta( $id, self::META_PAGE, esc_url_raw( $context['page_url'] ) );
		}
		return (int) $id;
	}

	/**
	 * Entries for a submission.
	 *
	 * @param int $id Submission ID.
	 * @return array[]
	 */
	public function get_entries( $id ) {
		$data = json_decode( (string) get_post_meta( $id, self::META_ENTRIES, true ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Append to the action log.
	 *
	 * @param int   $id  Submission ID.
	 * @param array $log Log rows.
	 */
	public function add_log( $id, array $log ) {
		$existing = $this->get_log( $id );
		update_post_meta( $id, self::META_LOG, wp_slash( wp_json_encode( array_merge( $existing, $log ) ) ) );
	}

	/**
	 * Action log.
	 *
	 * @param int $id Submission ID.
	 * @return array[]
	 */
	public function get_log( $id ) {
		$data = json_decode( (string) get_post_meta( $id, self::META_LOG, true ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Number of stored submissions for a form.
	 *
	 * @param int $form_id Form ID.
	 * @return int
	 */
	public function count_for_form( $form_id ) {
		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'post_parent'    => (int) $form_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return (int) $query->found_posts;
	}

	/**
	 * Delete private files when a submission is permanently deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_files( $post_id ) {
		if ( self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		foreach ( $this->get_entries( $post_id ) as $entry ) {
			if ( 'file' === $entry['type'] && ! empty( $entry['value']['file'] ) ) {
				$this->uploads->delete( $entry['value']['file'] );
			}
		}
	}

	/**
	 * Admin download URL for a stored file.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $file          Stored key.
	 * @return string
	 */
	public function download_url( $submission_id, $file ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'launch_forms_download',
					'submission' => (int) $submission_id,
					'file'       => $file,
				),
				admin_url( 'admin-post.php' )
			),
			'launch_forms_download_' . (int) $submission_id
		);
	}

	/**
	 * Check permissions and resolve a download to a path.
	 *
	 * @param int    $submission_id Submission ID.
	 * @param string $file          Stored key.
	 * @return array{path:string,name:string}|\WP_Error
	 */
	public function resolve_download( $submission_id, $file ) {
		if ( ! current_user_can( Plugin::manage_capability() ) ) {
			return new \WP_Error( 'lf_forbidden', __( 'You are not allowed to download this file.', 'launch-forms' ), array( 'status' => 403 ) );
		}
		if ( self::POST_TYPE !== get_post_type( $submission_id ) ) {
			return new \WP_Error( 'lf_not_found', __( 'Submission not found.', 'launch-forms' ), array( 'status' => 404 ) );
		}
		foreach ( $this->get_entries( $submission_id ) as $entry ) {
			if ( 'file' === $entry['type'] && isset( $entry['value']['file'] ) && hash_equals( (string) $entry['value']['file'], (string) $file ) ) {
				$path = $this->uploads->path( $entry['value']['file'] );
				if ( ! $path ) {
					break;
				}
				return array(
					'path' => $path,
					'name' => sanitize_file_name( (string) $entry['value']['name'] ),
				);
			}
		}
		return new \WP_Error( 'lf_not_found', __( 'File not found.', 'launch-forms' ), array( 'status' => 404 ) );
	}

	/**
	 * Logged-out visitors following a download link are sent to the login screen, then back.
	 *
	 * Without this, admin-post.php answers with a blank 400 error page.
	 */
	public function handle_logged_out_download() {
		auth_redirect();
	}

	/**
	 * Stop a download with a readable explanation and a way back.
	 *
	 * @param string $message  Explanation.
	 * @param int    $status   HTTP status.
	 * @param string $link_url Where to go next.
	 * @param string $link     Link text.
	 */
	private function download_error( $message, $status, $link_url = '', $link = '' ) {
		$args = array( 'response' => $status );
		if ( $link_url ) {
			$args['link_url']  = $link_url;
			$args['link_text'] = $link;
		}
		wp_die( esc_html( $message ), esc_html__( 'Download unavailable', 'launch-forms' ), $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_die() escapes link_url and link_text.
	}

	/**
	 * admin-post handler that streams a private file to an authorised user.
	 */
	public function handle_download() {
		$submission_id = isset( $_GET['submission'] ) ? absint( $_GET['submission'] ) : 0;
		$file          = isset( $_GET['file'] ) ? sanitize_key( wp_unslash( $_GET['file'] ) ) : '';

		if ( ! current_user_can( Plugin::manage_capability() ) ) {
			$this->download_error(
				__( 'Your account does not have permission to download files from form submissions. Ask a site administrator if you need this file.', 'launch-forms' ),
				403,
				admin_url(),
				__( 'Go to the dashboard', 'launch-forms' )
			);
			return;
		}

		$is_submission = self::POST_TYPE === get_post_type( $submission_id );
		$back_url      = $is_submission ? (string) get_edit_post_link( $submission_id, 'raw' ) : admin_url( 'edit.php?post_type=' . self::POST_TYPE );
		$back_text     = $is_submission ? __( 'Open the submission', 'launch-forms' ) : __( 'View all submissions', 'launch-forms' );

		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'launch_forms_download_' . $submission_id ) ) {
			$this->download_error(
				__( 'This download link has expired. Download links only work for the person who opened them and for a limited time. Open the submission again to get a fresh link.', 'launch-forms' ),
				403,
				$back_url,
				$back_text
			);
			return;
		}

		$download = $this->resolve_download( $submission_id, $file );
		if ( is_wp_error( $download ) ) {
			$this->download_error(
				__( 'This file is no longer available. It may have been deleted, or the submission it belonged to was removed.', 'launch-forms' ),
				(int) ( $download->get_error_data()['status'] ?? 404 ),
				$back_url,
				$back_text
			);
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $download['name'] ) . '"; filename*=UTF-8\'\'' . rawurlencode( $download['name'] ) );
		header( 'Content-Length: ' . filesize( $download['path'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( "Content-Security-Policy: default-src 'none'; sandbox" );
		readfile( $download['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * List table columns.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( $columns ) {
		return array(
			'cb'         => $columns['cb'] ?? '',
			'title'      => __( 'Submission', 'launch-forms' ),
			'lf_form'    => __( 'Form', 'launch-forms' ),
			'lf_summary' => __( 'Summary', 'launch-forms' ),
			'date'       => __( 'Date', 'launch-forms' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post ID.
	 */
	public function column( $column, $post_id ) {
		if ( 'lf_form' === $column ) {
			$form_id = (int) wp_get_post_parent_id( $post_id );
			$link    = $form_id ? get_edit_post_link( $form_id ) : '';
			if ( ! $form_id ) {
				echo '&mdash;';
			} elseif ( $link ) {
				echo '<a href="' . esc_url( $link ) . '">' . esc_html( get_the_title( $form_id ) ) . '</a>';
			} else {
				echo esc_html( get_the_title( $form_id ) );
			}
		}
		if ( 'lf_summary' === $column ) {
			$parts = array();
			foreach ( array_slice( $this->get_entries( $post_id ), 0, 3 ) as $entry ) {
				$parts[] = '<strong>' . esc_html( $entry['label'] ) . ':</strong> ' . esc_html( wp_trim_words( Notifications::entry_text( $entry ), 8 ) );
			}
			echo wp_kses_post( implode( '<br>', $parts ) );
		}
	}

	/**
	 * Filter dropdown on the list screen.
	 *
	 * @param string $post_type Post type.
	 */
	public function form_filter( $post_type ) {
		if ( self::POST_TYPE !== $post_type ) {
			return;
		}
		$current = isset( $_GET['lf_form'] ) ? absint( $_GET['lf_form'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<label class="screen-reader-text" for="lf-form-filter">' . esc_html__( 'Filter by form', 'launch-forms' ) . '</label>';
		echo '<select name="lf_form" id="lf-form-filter"><option value="0">' . esc_html__( 'All forms', 'launch-forms' ) . '</option>';
		foreach ( launch_forms()->forms->all() as $id => $title ) {
			echo '<option value="' . esc_attr( $id ) . '"' . selected( $current, $id, false ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Apply the form filter.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function apply_form_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$form = isset( $_GET['lf_form'] ) ? absint( $_GET['lf_form'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $form ) {
			$query->set( 'post_parent', $form );
		}
	}

	/**
	 * Replace "Edit" with "View" and drop quick edit.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Post.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type ) {
			return $actions;
		}
		unset( $actions['inline hide-if-no-js'] );
		$link = get_edit_post_link( $post->ID );
		if ( isset( $actions['edit'] ) && $link ) {
			$actions['edit'] = '<a href="' . esc_url( $link ) . '">' . esc_html__( 'View', 'launch-forms' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Meta boxes on the view screen.
	 */
	public function meta_boxes() {
		remove_meta_box( 'submitdiv', self::POST_TYPE, 'side' );
		remove_meta_box( 'slugdiv', self::POST_TYPE, 'normal' );
		add_meta_box( 'lf-submission-data', __( 'Submission', 'launch-forms' ), array( $this, 'render_data_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'lf-submission-info', __( 'Details', 'launch-forms' ), array( $this, 'render_info_box' ), self::POST_TYPE, 'side', 'high' );
	}

	/**
	 * Submitted values table.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_data_box( $post ) {
		echo '<table class="widefat striped"><tbody>';
		foreach ( $this->get_entries( $post->ID ) as $entry ) {
			echo '<tr><th scope="row" style="width:30%">' . esc_html( '' !== $entry['label'] ? $entry['label'] : $entry['name'] ) . '</th><td>';
			if ( 'file' === $entry['type'] && ! empty( $entry['value']['file'] ) ) {
				if ( $this->uploads->path( $entry['value']['file'] ) ) {
					echo '<a href="' . esc_url( $this->download_url( $post->ID, $entry['value']['file'] ) ) . '">' . esc_html( $entry['value']['name'] ) . '</a> (' . esc_html( size_format( (int) $entry['value']['size'] ) ) . ')';
				} else {
					echo esc_html( $entry['value']['name'] ) . ' &mdash; ' . esc_html__( 'file missing', 'launch-forms' );
				}
			} else {
				echo nl2br( esc_html( Notifications::entry_text( $entry ) ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$log = $this->get_log( $post->ID );
		if ( $log ) {
			echo '<h3>' . esc_html__( 'Actions', 'launch-forms' ) . '</h3><ul>';
			foreach ( $log as $row ) {
				echo '<li>' . ( $row['success'] ? '&#10003; ' : '&#10007; ' ) . esc_html( $row['name'] . ': ' . $row['message'] ) . '</li>';
			}
			echo '</ul>';
		}
	}

	/**
	 * Sidebar details box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function render_info_box( $post ) {
		$form_id = (int) $post->post_parent;
		echo '<p><strong>' . esc_html__( 'Form:', 'launch-forms' ) . '</strong> ' . ( $form_id ? esc_html( get_the_title( $form_id ) ) : '&mdash;' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Date:', 'launch-forms' ) . '</strong> ' . esc_html( get_the_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post ) ) . '</p>';
		$page = get_post_meta( $post->ID, self::META_PAGE, true );
		if ( $page ) {
			echo '<p><strong>' . esc_html__( 'Page:', 'launch-forms' ) . '</strong> <a href="' . esc_url( $page ) . '">' . esc_html( $page ) . '</a></p>';
		}
		if ( $post->post_author ) {
			$user = get_userdata( $post->post_author );
			echo '<p><strong>' . esc_html__( 'User:', 'launch-forms' ) . '</strong> ' . esc_html( $user ? $user->display_name : '#' . $post->post_author ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) ) . '">' . esc_html__( 'Back to submissions', 'launch-forms' ) . '</a> ';
		echo '<a class="submitdelete" style="color:#b32d2e" href="' . esc_url( get_delete_post_link( $post->ID ) ) . '">' . esc_html__( 'Move to Trash', 'launch-forms' ) . '</a></p>';
	}
}
