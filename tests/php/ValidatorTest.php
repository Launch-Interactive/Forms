<?php
/**
 * @covers \LaunchForms\Validator
 */

use LaunchForms\Form_Config;
use LaunchForms\Validator;

class ValidatorTest extends LF_TestCase {

	/** @var Validator */
	private $validator;

	public function set_up() {
		parent::set_up();
		$this->validator = new Validator();
	}

	private function check( array $raw_field, $value ) {
		$field = Form_Config::sanitize_field( $raw_field );
		$error = $this->validator->validate_field( $field, $value );
		return array( $error, $value );
	}

	public function test_required() {
		$this->assertSame( 'This field is required.', $this->check( array( 'type' => 'text', 'required' => true ), '  ' )[0] );
		$this->assertNull( $this->check( array( 'type' => 'text' ), '' )[0] );
		$this->assertSame( 'This field is required.', $this->check( array( 'type' => 'checkbox', 'required' => true, 'options' => 'a' ), array() )[0] );
	}

	public function test_email() {
		$this->assertNotNull( $this->check( array( 'type' => 'email' ), 'not-an-email' )[0] );
		list( $error, $value ) = $this->check( array( 'type' => 'email' ), 'ann@example.com' );
		$this->assertNull( $error );
		$this->assertSame( 'ann@example.com', $value );
	}

	public function test_url() {
		$this->assertNotNull( $this->check( array( 'type' => 'url' ), 'javascript:alert(1)' )[0] );
		$this->assertNotNull( $this->check( array( 'type' => 'url' ), 'example' )[0] );
		$this->assertNotNull( $this->check( array( 'type' => 'url' ), 'not a url' )[0] );
		$this->assertNull( $this->check( array( 'type' => 'url' ), 'https://example.com/path?q=1' )[0] );
		list( $error, $value ) = $this->check( array( 'type' => 'url' ), 'example.com' );
		$this->assertNull( $error, 'Scheme-less domains are accepted.' );
		$this->assertSame( 'http://example.com', $value );
	}

	public function test_tel() {
		$this->assertNull( $this->check( array( 'type' => 'tel' ), '+61 (02) 9999-1234' )[0] );
		$this->assertNotNull( $this->check( array( 'type' => 'tel' ), 'call me maybe' )[0] );
	}

	public function test_number_range() {
		$field = array( 'type' => 'number', 'min' => 1, 'max' => 10 );
		$this->assertNotNull( $this->check( $field, 'ten' )[0] );
		$this->assertSame( 'Please enter a number of at least 1.', $this->check( $field, '0' )[0] );
		$this->assertSame( 'Please enter a number no greater than 10.', $this->check( $field, '11' )[0] );
		$this->assertNull( $this->check( $field, '5.5' )[0] );
	}

	public function test_date() {
		$this->assertNull( $this->check( array( 'type' => 'date' ), '2026-02-28' )[0] );
		$this->assertNotNull( $this->check( array( 'type' => 'date' ), '2026-02-30' )[0] );
		$this->assertNotNull( $this->check( array( 'type' => 'date' ), '28/02/2026' )[0] );
	}

	public function test_choices_must_be_whitelisted() {
		$select = array( 'type' => 'select', 'options' => "Red|r\nBlue|b" );
		$this->assertNull( $this->check( $select, 'r' )[0] );
		$this->assertNotNull( $this->check( $select, 'Red' )[0], 'Label is not a valid value.' );
		$this->assertNotNull( $this->check( array( 'type' => 'radio', 'options' => 'a' ), 'z' )[0] );

		list( $error, $value ) = $this->check( array( 'type' => 'checkbox', 'options' => "a\nb" ), array( 'a', 'b', 'a' ) );
		$this->assertNull( $error );
		$this->assertSame( array( 'a', 'b' ), $value );
		$this->assertNotNull( $this->check( array( 'type' => 'checkbox', 'options' => "a\nb" ), array( 'a', 'evil' ) )[0] );
	}

	public function test_consent_normalised() {
		list( $error, $value ) = $this->check( array( 'type' => 'consent' ), 'on' );
		$this->assertNull( $error );
		$this->assertSame( '1', $value );
	}

	public function test_normalize_sanitises_and_handles_bad_shapes() {
		$form = $this->make_form(
			array(
				$this->field( 'text', 'name' ),
				$this->field( 'textarea', 'msg' ),
				$this->field( 'checkbox', 'tags', array( 'options' => "a\nb" ) ),
				$this->field( 'file', 'doc' ),
				array( 'type' => 'content', 'content' => 'x' ),
			)
		);
		$values = $this->validator->normalize(
			$form,
			array(
				'name' => array( 'array', 'instead' ),
				'msg'  => "Line one\n<script>x</script>Line two",
				'tags' => 'a',
			),
			array( 'doc' => array( 'name' => 'My CV.pdf', 'error' => UPLOAD_ERR_OK ) )
		);
		$this->assertSame( '', $values['name'] );
		$this->assertSame( "Line one\nLine two", $values['msg'] );
		$this->assertSame( array( 'a' ), $values['tags'] );
		$this->assertSame( 'My-CV.pdf', $values['doc'] );
		$this->assertArrayNotHasKey( 'content', $values );
	}

	public function test_hidden_conditional_fields_are_skipped() {
		$form   = $this->make_form(
			array(
				$this->field( 'radio', 'contact', array( 'options' => "email\nphone", 'required' => true ) ),
				$this->field(
					'tel',
					'phone',
					array(
						'required'   => true,
						'conditions' => array(
							'enabled' => true,
							'rules'   => array( array( 'field' => 'contact', 'operator' => 'is', 'value' => 'phone' ) ),
						),
					)
				),
			)
		);
		$result = $this->validator->validate( $form, array( 'contact' => 'email', 'phone' => 'garbage!!' ) );
		$this->assertSame( array(), $result['errors'] );
		$this->assertArrayNotHasKey( 'phone', $result['values'], 'Values from hidden fields must be discarded.' );

		$result = $this->validator->validate( $form, array( 'contact' => 'phone' ) );
		$this->assertSame( array( 'phone' => 'This field is required.' ), $result['errors'] );
	}

	public function test_validation_errors_filter() {
		$form = $this->contact_form();
		add_filter(
			'launch_forms_validation_errors',
			static function ( $errors, $values ) {
				if ( 'blocked@example.com' === $values['email'] ) {
					$errors['email'] = 'Blocked.';
				}
				return $errors;
			},
			10,
			2
		);
		$result = $this->validator->validate( $form, array( 'name' => 'A', 'email' => 'blocked@example.com' ) );
		$this->assertSame( array( 'email' => 'Blocked.' ), $result['errors'] );
	}

	public function test_overlong_input_is_truncated() {
		$form   = $this->make_form( array( $this->field( 'textarea', 'msg' ) ) );
		$values = $this->validator->normalize( $form, array( 'msg' => str_repeat( 'a', 50000 ) ) );
		$this->assertSame( Validator::MAX_LENGTH, strlen( $values['msg'] ) );
	}
}
