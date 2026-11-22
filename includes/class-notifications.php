<?php
/**
 * Email notifications with merge tags.
 *
 * Merge tags: {field:name}, {all_fields}, {form_title}, {form_id}, {site_name}, {site_url},
 * {admin_email}, {submission_id}, {page_url}, {date}.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Sends notification emails.
 */
class Notifications {

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
	 * Send every enabled notification.
	 *
	 * @param Form  $form    Form.
	 * @param array $entries Submission entries (see Processor::build_entries()).
	 * @param array $context Context: submission_id, page_url.
	 * @return array[] Log: [{action, name, success, message}].
	 */
	public function send_all( Form $form, array $entries, array $context = array() ) {
		$log = array();
		foreach ( $form->notifications as $index => $notification ) {
			if ( empty( $notification['enabled'] ) ) {
				continue;
			}
			$result = $this->send( $notification, $form, $entries, $context );
			$log[]  = array(
				'action'  => 'email',
				'name'    => '' !== $notification['name'] ? $notification['name'] : sprintf( 'Notification %d', $index + 1 ),
				'success' => true === $result,
				'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Sent', 'launch-forms' ),
			);
		}
		return $log;
	}

	/**
	 * Send one notification.
	 *
	 * @param array $notification Notification config.
	 * @param Form  $form         Form.
	 * @param array $entries      Entries.
	 * @param array $context      Context.
	 * @return true|\WP_Error
	 */
	public function send( array $notification, Form $form, array $entries, array $context = array() ) {
		$to = $this->parse_addresses( $this->replace_tags( $notification['to'], $form, $entries, $context ) );
		if ( empty( $to ) ) {
			return new \WP_Error( 'lf_no_recipient', __( 'No valid recipient address.', 'launch-forms' ) );
		}

		$subject = self::single_line( $this->replace_tags( $notification['subject'], $form, $entries, $context ) );
		if ( '' === $subject ) {
			/* translators: %s: form title. */
			$subject = sprintf( __( 'New submission: %s', 'launch-forms' ), $form->title );
		}
		$message = $this->replace_tags( '' !== $notification['message'] ? $notification['message'] : '{all_fields}', $form, $entries, $context );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		$reply   = $this->parse_addresses( $this->replace_tags( $notification['reply_to'], $form, $entries, $context ) );
		if ( $reply ) {
			$headers[] = 'Reply-To: ' . $reply[0];
		}
		$from_email = sanitize_email( $notification['from_email'] );
		if ( is_email( $from_email ) ) {
			$from_name = trim( str_replace( array( '"', '<', '>' ), '', self::single_line( $this->replace_tags( $notification['from_name'], $form, $entries, $context ) ) ) );
			$headers[] = 'From: ' . ( '' !== $from_name ? '"' . $from_name . '" ' : '' ) . '<' . $from_email . '>';
		}

		$attachments = array();
		$temp_dir    = '';
		if ( ! empty( $notification['attach_files'] ) ) {
			list( $attachments, $temp_dir ) = $this->prepare_attachments( $entries );
		}

		/**
		 * Filters the email before sending.
		 *
		 * @param array $email        {to, subject, message, headers, attachments}.
		 * @param array $notification Notification config.
		 * @param Form  $form         Form.
		 * @param array $entries      Entries.
		 */
		$email = apply_filters( 'launch_forms_notification_email', compact( 'to', 'subject', 'message', 'headers', 'attachments' ), $notification, $form, $entries );

		$error   = null;
		$catcher = static function ( $wp_error ) use ( &$error ) {
			$error = $wp_error;
		};
		add_action( 'wp_mail_failed', $catcher );
		$sent = wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'], $email['attachments'] );
		remove_action( 'wp_mail_failed', $catcher );

		if ( $temp_dir ) {
			$this->cleanup_temp( $temp_dir );
		}

		if ( ! $sent ) {
			return $error instanceof \WP_Error ? $error : new \WP_Error( 'lf_mail_failed', __( 'wp_mail() could not send the email.', 'launch-forms' ) );
		}
		return true;
	}

	/**
	 * Replace merge tags in one pass, so text a visitor typed (e.g. "{admin_email}") is never
	 * itself treated as a tag. Unknown or empty {field:...} tags become ''.
	 *
	 * @param string        $text    Text.
	 * @param Form          $form    Form.
	 * @param array         $entries Entries.
	 * @param array         $context Context.
	 * @param callable|null $escape  Applied to every inserted value (e.g. 'esc_html' for HTML output).
	 * @return string
	 */
	public function replace_tags( $text, Form $form, array $entries, array $context = array(), $escape = null ) {
		$text = (string) $text;
		if ( false === strpos( $text, '{' ) ) {
			return $text;
		}
		$by_name = array();
		foreach ( $entries as $entry ) {
			$by_name[ $entry['name'] ] = $entry;
		}

		$values = array(
			'all_fields'    => $this->all_fields( $entries ),
			'form_title'    => $form->title,
			'form_id'       => (string) $form->id,
			'site_name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'      => home_url( '/' ),
			'admin_email'   => (string) get_option( 'admin_email' ),
			'submission_id' => (string) ( $context['submission_id'] ?? '' ),
			'page_url'      => (string) ( $context['page_url'] ?? '' ),
			'date'          => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);

		return preg_replace_callback(
			'/\{(field:([a-z0-9_]+)|' . implode( '|', array_keys( $values ) ) . ')\}/',
			static function ( $m ) use ( $by_name, $values, $escape ) {
				if ( isset( $m[2] ) && '' !== $m[2] ) {
					$value = isset( $by_name[ $m[2] ] ) ? self::entry_text( $by_name[ $m[2] ] ) : '';
				} else {
					$value = $values[ $m[1] ];
				}
				return $escape ? (string) call_user_func( $escape, $value ) : $value;
			},
			$text
		);
	}

	/**
	 * Plain-text "Label: value" list of all submitted entries.
	 *
	 * @param array $entries Entries.
	 * @return string
	 */
	public function all_fields( array $entries ) {
		$lines = array();
		foreach ( $entries as $entry ) {
			$label   = '' !== $entry['label'] ? $entry['label'] : $entry['name'];
			$value   = self::entry_text( $entry );
			$lines[] = $label . ': ' . ( false !== strpos( $value, "\n" ) ? "\n" . $value : $value );
		}
		return implode( "\n", $lines );
	}

	/**
	 * Text representation of one entry value.
	 *
	 * @param array $entry Entry.
	 * @return string
	 */
	public static function entry_text( array $entry ) {
		$value = $entry['value'];
		if ( 'file' === $entry['type'] ) {
			return is_array( $value ) && isset( $value['name'] ) ? $value['name'] : '';
		}
		if ( 'consent' === $entry['type'] ) {
			return '' !== (string) $value ? __( 'Yes', 'launch-forms' ) : __( 'No', 'launch-forms' );
		}
		return is_array( $value ) ? implode( ', ', $value ) : (string) $value;
	}

	/**
	 * Split and validate a list of email addresses.
	 *
	 * @param string $list Comma/semicolon separated.
	 * @return string[]
	 */
	public function parse_addresses( $list ) {
		$valid = array();
		foreach ( preg_split( '/[,;]+/', self::single_line( (string) $list ) ) as $address ) {
			$address = sanitize_email( trim( $address ) );
			if ( is_email( $address ) && ! in_array( $address, $valid, true ) ) {
				$valid[] = $address;
			}
		}
		return $valid;
	}

	/**
	 * Copy stored files to a temp folder under their original names so the attachment names are friendly.
	 *
	 * @param array $entries Entries.
	 * @return array{0:string[],1:string} Paths and temp dir.
	 */
	private function prepare_attachments( array $entries ) {
		$paths = array();
		$dir   = trailingslashit( get_temp_dir() ) . 'launch-forms-' . bin2hex( random_bytes( 8 ) ) . '/';
		foreach ( $entries as $entry ) {
			if ( 'file' !== $entry['type'] || empty( $entry['value']['file'] ) ) {
				continue;
			}
			$source = $this->uploads->path( $entry['value']['file'] );
			if ( ! $source ) {
				continue;
			}
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			$name = sanitize_file_name( $entry['value']['name'] );
			$dest = $dir . ( '' !== $name ? $name : $entry['value']['file'] );
			if ( file_exists( $dest ) ) {
				$dest = $dir . wp_unique_filename( $dir, $name );
			}
			if ( copy( $source, $dest ) ) {
				$paths[] = $dest;
			}
		}
		return array( $paths, $paths ? $dir : '' );
	}

	/**
	 * Remove a temp attachment folder.
	 *
	 * @param string $dir Dir.
	 */
	private function cleanup_temp( $dir ) {
		foreach ( (array) glob( $dir . '*' ) as $file ) {
			wp_delete_file( $file );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	}

	/**
	 * Strip line breaks (prevents header injection).
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function single_line( $value ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) );
	}
}
