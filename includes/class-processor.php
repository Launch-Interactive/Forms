<?php
/**
 * Submission pipeline: spam checks → validation → uploads → storage → actions.
 * Shared by the REST endpoint (JavaScript) and the plain POST fallback (no JavaScript).
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Processes submissions.
 */
class Processor {

	/** @var Plugin */
	private $plugin;

	/** @var Validator */
	private $validator;

	/**
	 * Results from this request, keyed by form ID, used to re-render forms after a non-JS POST.
	 *
	 * @var array<int,Submission_Result>
	 */
	private $results = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Container.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin    = $plugin;
		$this->validator = new Validator();
	}

	/**
	 * Process a submission.
	 *
	 * @param Form  $form    Form.
	 * @param array $request Unslashed top-level POST params (contains `lf`, `lf_hp`, captcha tokens).
	 * @param array $files   Normalised uploads keyed by field name (Uploads::normalize_files()).
	 * @param array $context Optional: page_url, ip, user_id.
	 * @return Submission_Result
	 */
	public function process( Form $form, array $request, array $files = array(), array $context = array() ) {
		$input   = isset( $request['lf'] ) && is_array( $request['lf'] ) ? $request['lf'] : array();
		$context = array_merge(
			array(
				'page_url' => '',
				'ip'       => '',
				'user_id'  => get_current_user_id(),
			),
			$context
		);
		$context['page_url'] = self::same_site_url( (string) $context['page_url'] );

		// Honeypot: pretend everything worked so bots learn nothing.
		if ( isset( $request['lf_hp'] ) && '' !== trim( (string) ( is_scalar( $request['lf_hp'] ) ? $request['lf_hp'] : 'x' ) ) ) {
			$result          = new Submission_Result();
			$result->success = true;
			$result->spam    = true;
			$result->message = $this->success_text( $form, array(), array() );
			return $this->remember( $form, $result );
		}

		if ( $this->is_rate_limited( $context['ip'] ) ) {
			$result         = Submission_Result::failure( __( 'Too many submissions from your connection. Please wait a few minutes and try again.', 'launch-forms' ), array(), $this->validator->normalize( $form, $input ) );
			$result->status = 429;
			return $this->remember( $form, $result );
		}

		$captcha = $this->plugin->captcha->verify( $form, $request, $context['ip'] );
		if ( is_wp_error( $captcha ) ) {
			// Show the error at the captcha itself as well as at the top of the form.
			$field = $form->captcha_field();
			return $this->remember( $form, Submission_Result::failure( $captcha->get_error_message(), $field ? array( $field['name'] => $captcha->get_error_message() ) : array(), $this->validator->normalize( $form, $input ) ) );
		}

		$validation = $this->validator->validate( $form, $input, $files );
		$errors     = $validation['errors'];

		// Validate uploads before storing any of them, so a failed submission never leaves files behind.
		$file_fields = array();
		foreach ( $form->fields as $field ) {
			if ( 'file' !== $field['type'] || empty( $validation['visibility'][ $field['name'] ] ) ) {
				continue;
			}
			$file = $files[ $field['name'] ] ?? null;
			if ( ! Uploads::was_provided( $file ) ) {
				if ( $field['required'] ) {
					$errors[ $field['name'] ] = __( 'This field is required.', 'launch-forms' );
				}
				continue;
			}
			$check = $this->plugin->uploads->validate( $field, $file );
			if ( is_wp_error( $check ) ) {
				$errors[ $field['name'] ] = $check->get_error_message();
				continue;
			}
			$file_fields[] = $field;
		}

		if ( $errors ) {
			$raw = $validation['raw'];
			foreach ( $form->fields as $field ) {
				if ( 'file' === $field['type'] ) {
					unset( $raw[ $field['name'] ] );
				}
			}
			return $this->remember( $form, Submission_Result::failure( __( 'Please correct the errors below.', 'launch-forms' ), $errors, $raw ) );
		}

		$stored = array();
		foreach ( $file_fields as $field ) {
			$record = $this->plugin->uploads->store( $field, $files[ $field['name'] ] );
			if ( is_wp_error( $record ) ) {
				foreach ( $stored as $done ) {
					$this->plugin->uploads->delete( $done['file'] );
				}
				return $this->remember( $form, Submission_Result::failure( __( 'Please correct the errors below.', 'launch-forms' ), array( $field['name'] => $record->get_error_message() ), $validation['raw'] ) );
			}
			$stored[ $field['name'] ] = $record;
		}

		$values  = $validation['values'];
		$entries = $this->build_entries( $form, $values, $stored );

		$submission_id = 0;
		if ( $form->settings['store_submissions'] ) {
			$submission_id = $this->plugin->submissions->create( $form, $entries, $context );
		}
		$context['submission_id'] = $submission_id;

		/**
		 * Fires before notifications and integrations run.
		 *
		 * @param int   $submission_id Submission ID (0 when not stored).
		 * @param Form  $form          Form.
		 * @param array $entries       Entries.
		 */
		do_action( 'launch_forms_before_actions', $submission_id, $form, $entries );

		$log = $this->plugin->notifications->send_all( $form, $entries, $context );

		$mailchimp = $this->mailchimp()->subscribe( $form, $values );
		if ( null !== $mailchimp ) {
			$log[] = array(
				'action'  => 'mailchimp',
				'name'    => 'Mailchimp',
				'success' => true === $mailchimp,
				'message' => is_wp_error( $mailchimp ) ? $mailchimp->get_error_message() : __( 'Subscribed', 'launch-forms' ),
			);
		}

		if ( $submission_id ) {
			$this->plugin->submissions->add_log( $submission_id, $log );
		} else {
			// Not storing submissions: files were only needed for email attachments.
			foreach ( $stored as $record ) {
				$this->plugin->uploads->delete( $record['file'] );
			}
		}

		/**
		 * Fires after a successful submission and all actions.
		 *
		 * @param int   $submission_id Submission ID (0 when not stored).
		 * @param Form  $form          Form.
		 * @param array $entries       Entries.
		 * @param array $log           Action log.
		 */
		do_action( 'launch_forms_submission', $submission_id, $form, $entries, $log );

		$this->count_submission( $context['ip'] );

		$result                = new Submission_Result();
		$result->success       = true;
		$result->message       = $this->success_text( $form, $entries, $context );
		$result->submission_id = $submission_id;
		$result->entries       = $entries;
		$result->context       = array(
			'submission_id' => $submission_id,
			'page_url'      => $context['page_url'],
		);
		$result->redirect      = $form->settings['redirect_url'];
		$result->values        = $values;
		return $this->remember( $form, $result );
	}

	/**
	 * Build the ordered entries list (label snapshot + value) for storage and email.
	 *
	 * @param Form  $form   Form.
	 * @param array $values Clean values.
	 * @param array $files  Stored file records keyed by field name.
	 * @return array[] [{name,label,type,value}]
	 */
	public function build_entries( Form $form, array $values, array $files ) {
		$entries = array();
		foreach ( $form->fields as $field ) {
			$name = $field['name'];
			if ( 'file' === $field['type'] ) {
				if ( ! isset( $files[ $name ] ) ) {
					continue;
				}
				$value = $files[ $name ];
			} elseif ( array_key_exists( $name, $values ) ) {
				$value = $values[ $name ];
			} else {
				continue;
			}
			$entries[] = array(
				'name'  => $name,
				'label' => Form_Config::plain_label( $field['label'] ),
				'type'  => $field['type'],
				'value' => $value,
			);
		}
		return $entries;
	}

	/**
	 * Per-IP limit on successful submissions, to stop floods filling the database, the upload
	 * folder or inboxes. Only successful submissions count, so visitors fixing validation errors
	 * are never locked out.
	 *
	 * @return array{max:int,window:int}|false
	 */
	public static function rate_limit() {
		/**
		 * Filters the submission rate limit. Return false to turn it off.
		 *
		 * If the site is behind a proxy or CDN that hides visitor IPs, every visitor shares one
		 * address: raise the limit, or pass the real IP through the `launch_forms_client_ip` filter.
		 *
		 * @param array{max:int,window:int}|false $limit Max successful submissions per IP per window (seconds).
		 */
		$limit = apply_filters(
			'launch_forms_rate_limit',
			array(
				'max'    => 10,
				'window' => 10 * MINUTE_IN_SECONDS,
			)
		);
		if ( ! is_array( $limit ) || empty( $limit['max'] ) || empty( $limit['window'] ) ) {
			return false;
		}
		return array(
			'max'    => max( 1, (int) $limit['max'] ),
			'window' => max( 1, (int) $limit['window'] ),
		);
	}

	/**
	 * Transient key for an IP (hashed, so raw IPs are never stored).
	 *
	 * @param string $ip IP.
	 * @return string
	 */
	private static function rate_key( $ip ) {
		return 'lf_rate_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Whether this IP has used up its allowance.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	private function is_rate_limited( $ip ) {
		$limit = self::rate_limit();
		if ( ! $limit || '' === $ip ) {
			return false;
		}
		$state = get_transient( self::rate_key( $ip ) );
		return is_array( $state ) && $state['until'] > time() && $state['count'] >= $limit['max'];
	}

	/**
	 * Count a successful submission against an IP.
	 *
	 * @param string $ip IP.
	 */
	private function count_submission( $ip ) {
		$limit = self::rate_limit();
		if ( ! $limit || '' === $ip ) {
			return;
		}
		$key   = self::rate_key( $ip );
		$state = get_transient( $key );
		if ( ! is_array( $state ) || $state['until'] <= time() ) {
			$state = array(
				'count' => 0,
				'until' => time() + $limit['window'],
			);
		}
		++$state['count'];
		set_transient( $key, $state, max( 1, $state['until'] - time() ) );
	}

	/**
	 * Keep a page URL only if it points at this site (the Referer and Host headers are
	 * visitor-controlled, and the URL is shown to admins as a link and used in emails).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function same_site_url( $url ) {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return '' !== $host && ( $host === $home || in_array( $host, array_map( 'strtolower', (array) apply_filters( 'allowed_redirect_hosts', array( $home ), $host ) ), true ) ) ? $url : ''; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter, applied the way wp_validate_redirect() does.
	}

	/**
	 * Plain-text success message with merge tags filled in.
	 *
	 * @param Form  $form    Form.
	 * @param array $entries Entries.
	 * @param array $context Context.
	 * @return string
	 */
	private function success_text( Form $form, array $entries, array $context ) {
		return trim( wp_strip_all_tags( $this->plugin->notifications->replace_tags( $form->settings['success_message'], $form, $entries, $context ) ) );
	}

	/**
	 * Mailchimp client using the saved key.
	 *
	 * @return Mailchimp
	 */
	public function mailchimp() {
		return new Mailchimp( (string) $this->plugin->settings->get( 'mailchimp_api_key' ) );
	}

	/**
	 * Store a result for rendering later in the same request.
	 *
	 * @param Form              $form   Form.
	 * @param Submission_Result $result Result.
	 * @return Submission_Result
	 */
	private function remember( Form $form, Submission_Result $result ) {
		$this->results[ $form->id ] = $result;
		return $result;
	}

	/**
	 * Result of a submission made in this request.
	 *
	 * @param int $form_id Form ID.
	 * @return Submission_Result|null
	 */
	public function get_result( $form_id ) {
		return $this->results[ (int) $form_id ] ?? null;
	}

	/**
	 * Forget remembered results (used by tests).
	 */
	public function reset() {
		$this->results = array();
	}
}
