<?php
/**
 * Outcome of processing a submission.
 *
 * @package LaunchForms
 */

namespace LaunchForms;

defined( 'ABSPATH' ) || exit;

/**
 * Result value object.
 */
class Submission_Result {

	/** @var bool */
	public $success = false;
	/** @var string Plain text summary message. */
	public $message = '';
	/** @var array<string,string> Field name => error. */
	public $errors = array();
	/** @var array Values to re-populate the form with. */
	public $values = array();
	/** @var int Stored submission ID (0 if not stored). */
	public $submission_id = 0;
	/** @var string Redirect URL on success. */
	public $redirect = '';
	/** @var bool Silently accepted spam (honeypot). */
	public $spam = false;

	/**
	 * Build a failure result.
	 *
	 * @param string $message Message.
	 * @param array  $errors  Field errors.
	 * @param array  $values  Values.
	 * @return self
	 */
	public static function failure( $message, array $errors = array(), array $values = array() ) {
		$result          = new self();
		$result->message = $message;
		$result->errors  = $errors;
		$result->values  = $values;
		return $result;
	}
}
