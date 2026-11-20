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

		// Honeypot: pretend everything worked so bots learn nothing.
		if ( isset( $request['lf_hp'] ) && '' !== trim( (string) ( is_scalar( $request['lf_hp'] ) ? $request['lf_hp'] : 'x' ) ) ) {
			$result          = new Submission_Result();
			$result->success = true;
			$result->spam    = true;
			$result->message = wp_strip_all_tags( $form->settings['success_message'] );
			return $this->remember( $form, $result );
		}

		$captcha = $this->plugin->captcha->verify( $form, $request, $context['ip'] );
		if ( is_wp_error( $captcha ) ) {
			return $this->remember( $form, Submission_Result::failure( $captcha->get_error_message(), array(), $this->validator->normalize( $form, $input ) ) );
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

		$result                = new Submission_Result();
		$result->success       = true;
		$result->message       = wp_strip_all_tags( $form->settings['success_message'] );
		$result->submission_id = $submission_id;
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
				'label' => $field['label'],
				'type'  => $field['type'],
				'value' => $value,
			);
		}
		return $entries;
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
