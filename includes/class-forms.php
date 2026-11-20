<?php
/**
 * Form storage: the lf_form post type and its config meta.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and loads forms.
 */
class Forms {

	const POST_TYPE = 'lf_form';
	const META_KEY  = 'launch_forms_config';

	/**
	 * Config sections only users with the manage capability may change: they decide where
	 * submissions (and attached files) are sent, while viewing submissions is admin-only.
	 */
	const ADMIN_SECTIONS = array( 'notifications', 'mailchimp' );

	/** @var bool Re-entry guard while writing a corrected config. */
	private $locking = false;

	/**
	 * Hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'register' ) );
		add_filter( 'use_block_editor_for_post_type', array( $this, 'disable_block_editor' ), 10, 2 );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_classic_editor' ), 10, 2 );
		add_filter( 'is_protected_meta', array( $this, 'protect_meta' ), 10, 2 );
		add_filter( 'update_post_metadata', array( $this, 'lock_admin_sections' ), 10, 4 );
		add_filter( 'add_post_metadata', array( $this, 'lock_admin_sections' ), 10, 4 );
	}

	/**
	 * Whether the current user may change notifications and Mailchimp settings.
	 *
	 * @return bool
	 */
	public static function can_edit_admin_sections() {
		return current_user_can( Plugin::manage_capability() );
	}

	/**
	 * When someone without the manage capability saves a form (classic screen, block editor or
	 * REST), keep the stored notifications and Mailchimp settings and apply only their other
	 * changes. New forms they create get the default notifications.
	 *
	 * @param null|bool $check      Short-circuit value.
	 * @param int       $object_id  Post ID.
	 * @param string    $meta_key   Meta key.
	 * @param mixed     $meta_value Sanitised, unslashed value.
	 * @return null|bool
	 */
	public function lock_admin_sections( $check, $object_id, $meta_key, $meta_value ) {
		if ( null !== $check || self::META_KEY !== $meta_key || $this->locking || ! is_user_logged_in() || self::can_edit_admin_sections() ) {
			return $check;
		}
		if ( self::POST_TYPE !== get_post_type( $object_id ) ) {
			return $check;
		}
		$incoming = Form_Config::from_json( $meta_value );
		$stored   = Form_Config::from_json( get_post_meta( $object_id, self::META_KEY, true ) );
		$locked   = $incoming;
		foreach ( self::ADMIN_SECTIONS as $section ) {
			$locked[ $section ] = $stored[ $section ];
		}
		if ( $locked === $incoming ) {
			return $check;
		}
		$this->locking = true;
		update_post_meta( $object_id, self::META_KEY, wp_slash( wp_json_encode( $locked ) ) );
		$this->locking = false;
		// Report success: the allowed changes were saved (the REST API treats false as an error).
		return true;
	}

	/**
	 * Keep the config out of the generic "Custom Fields" UI. ('custom-fields' support is only
	 * declared because the REST API requires it to expose registered meta to the block editor.)
	 *
	 * @param bool   $protected Current value.
	 * @param string $meta_key  Meta key.
	 * @return bool
	 */
	public function protect_meta( $protected, $meta_key ) {
		return self::META_KEY === $meta_key ? true : $protected;
	}

	/**
	 * Register post type and meta.
	 */
	public function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'                => array(
					'name'               => __( 'Forms', 'launch-forms' ),
					'singular_name'      => __( 'Form', 'launch-forms' ),
					'add_new'            => __( 'Add New Form', 'launch-forms' ),
					'add_new_item'       => __( 'Add New Form', 'launch-forms' ),
					'edit_item'          => __( 'Edit Form', 'launch-forms' ),
					'new_item'           => __( 'New Form', 'launch-forms' ),
					'search_items'       => __( 'Search Forms', 'launch-forms' ),
					'not_found'          => __( 'No forms found.', 'launch-forms' ),
					'not_found_in_trash' => __( 'No forms found in Trash.', 'launch-forms' ),
					'all_items'          => __( 'All Forms', 'launch-forms' ),
					'menu_name'          => __( 'Forms', 'launch-forms' ),
				),
				'public'                => false,
				'publicly_queryable'    => false,
				'exclude_from_search'   => true,
				'show_ui'               => true,
				'show_in_menu'          => true,
				'show_in_nav_menus'     => false,
				'menu_icon'             => 'dashicons-feedback',
				'menu_position'         => 26,
				'capability_type'       => 'page',
				'map_meta_cap'          => true,
				'supports'              => array( 'title', 'custom-fields' ),
				'show_in_rest'          => true,
				'rest_base'             => 'launch-forms',
				'rest_controller_class' => Forms_Rest_Controller::class,
				'rewrite'               => false,
				'query_var'             => false,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_KEY,
			array(
				'type'              => 'string',
				'single'            => true,
				'default'           => wp_json_encode( Form_Config::defaults() ),
				'show_in_rest'      => true,
				'sanitize_callback' => array( Form_Config::class, 'sanitize_json' ),
				'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * Forms use the plugin's own builder screen, not the block editor.
	 *
	 * @param bool   $use       Current value.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public function disable_block_editor( $use, $post_type ) {
		return self::POST_TYPE === $post_type ? false : $use;
	}

	/**
	 * Load a form.
	 *
	 * @param int  $id               Post ID.
	 * @param bool $published_only   Only return published forms.
	 * @return Form|null
	 */
	public function get( $id, $published_only = true ) {
		$post = get_post( (int) $id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return null;
		}
		if ( $published_only && 'publish' !== $post->post_status ) {
			return null;
		}
		return new Form( $post->ID, get_the_title( $post ), get_post_meta( $post->ID, self::META_KEY, true ) );
	}

	/**
	 * Create a form programmatically.
	 *
	 * @param string $title  Title.
	 * @param array  $config Config.
	 * @return int|\WP_Error
	 */
	public function create( $title, array $config = array() ) {
		$id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$this->save_config( $id, $config );
		return $id;
	}

	/**
	 * Save a config array (sanitised by the registered meta callback).
	 *
	 * @param int   $id     Post ID.
	 * @param array $config Config.
	 */
	public function save_config( $id, array $config ) {
		update_post_meta( $id, self::META_KEY, wp_slash( wp_json_encode( $config ) ) );
	}

	/**
	 * All published forms as id => title.
	 *
	 * @return array<int,string>
	 */
	public function all() {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$forms = array();
		foreach ( $posts as $post ) {
			$forms[ $post->ID ] = get_the_title( $post );
		}
		return $forms;
	}

	/**
	 * Persist the builder's hidden field from the classic edit screen.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function save_classic_editor( $post_id, $post ) {
		if ( ! isset( $_POST['launch_forms_builder_nonce'], $_POST[ self::META_KEY ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['launch_forms_builder_nonce'] ) ), 'launch_forms_builder_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// The registered sanitize callback validates the whole structure; $_POST is already slashed
		// which is what update_post_meta() expects.
		update_post_meta( $post_id, self::META_KEY, $_POST[ self::META_KEY ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}
