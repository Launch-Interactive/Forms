<?php
/**
 * @covers \LaunchInteractiveForms\Form_Config
 * @covers \LaunchInteractiveForms\Forms
 * @covers \LaunchInteractiveForms\Form
 */

use LaunchInteractiveForms\Form_Config;
use LaunchInteractiveForms\Forms;

class FormConfigTest extends LF_TestCase {

	public function test_defaults_include_admin_notification() {
		$config = Form_Config::from_json( '' );
		$this->assertSame( array(), $config['fields'] );
		$this->assertCount( 1, $config['notifications'] );
		$this->assertSame( '{admin_email}', $config['notifications'][0]['to'] );
		$this->assertTrue( $config['settings']['store_submissions'] );
		$this->assertSame( 'none', $config['settings']['captcha'] );
	}

	public function test_width_is_limited_to_known_values() {
		$config = Form_Config::sanitize(
			array(
				'fields' => array(
					array( 'type' => 'text', 'label' => 'First', 'width' => 'half' ),
					array( 'type' => 'text', 'label' => 'Last', 'width' => 'two-thirds' ),
					array( 'type' => 'text', 'label' => 'Bad', 'width' => '50%"><script>' ),
					array( 'type' => 'text', 'label' => 'None' ),
					array( 'type' => 'hidden', 'label' => 'Ref', 'width' => 'half' ),
				),
			)
		);
		$this->assertSame( array( 'half', 'two-thirds', 'full', 'full', 'full' ), wp_list_pluck( $config['fields'], 'width' ) );
		$this->assertSame( Form_Config::WIDTHS, array_keys( Form_Config::width_labels() ) );
	}

	public function test_unknown_field_types_are_dropped() {
		$config = Form_Config::sanitize(
			array(
				'fields' => array(
					array( 'type' => 'text', 'label' => 'Name' ),
					array( 'type' => 'script', 'label' => 'Bad' ),
					'not an array',
				),
			)
		);
		$this->assertCount( 1, $config['fields'] );
		$this->assertSame( 'text', $config['fields'][0]['type'] );
	}

	public function test_names_are_derived_slugified_and_unique() {
		$config = Form_Config::sanitize(
			array(
				'fields' => array(
					array( 'type' => 'text', 'label' => 'Your Name!' ),
					array( 'type' => 'text', 'label' => 'Your name' ),
					array( 'type' => 'text', 'name' => '1st choice' ),
					array( 'type' => 'email', 'label' => 'Émail' ),
				),
			)
		);
		$this->assertSame( array( 'your_name', 'your_name_2', 'f_1st_choice', 'email' ), wp_list_pluck( $config['fields'], 'name' ) );
	}

	public function test_labels_and_text_are_sanitised() {
		$config = Form_Config::sanitize(
			array(
				'fields'   => array(
					array(
						'type'        => 'text',
						'label'       => '<script>alert(1)</script>Name',
						'description' => '<b>Help</b>',
						'css_class'   => 'one two" onclick="x',
					),
				),
				'settings' => array(
					'success_message' => '<p>Thanks</p><script>bad()</script>',
					'redirect_url'    => 'javascript:alert(1)',
				),
			)
		);
		$field = $config['fields'][0];
		$this->assertSame( 'Name', $field['label'] );
		$this->assertSame( 'Help', $field['description'] );
		$this->assertStringNotContainsString( '"', $field['css_class'] );
		$this->assertStringNotContainsString( '<script>', $config['settings']['success_message'] );
		$this->assertStringContainsString( '<p>Thanks</p>', $config['settings']['success_message'] );
		$this->assertSame( '', $config['settings']['redirect_url'] );
	}

	public function test_options_accept_multiple_formats_and_dedupe() {
		$this->assertSame(
			array(
				array( 'label' => 'Red', 'value' => 'red' ),
				array( 'label' => 'Blue', 'value' => 'Blue' ),
			),
			Form_Config::sanitize_options( "Red|red\nBlue\n\nRed|red" )
		);
		$this->assertSame(
			array( array( 'label' => 'A', 'value' => 'a' ) ),
			Form_Config::sanitize_options( array( array( 'label' => 'A', 'value' => 'a' ), array( 'label' => '', 'value' => '' ) ) )
		);
	}

	public function test_file_types_never_allow_scripts() {
		$this->assertSame( array( 'pdf', 'png' ), Form_Config::sanitize_file_types( 'PDF, .png, php, phtml, svg, js, exe, pdf' ) );
		$this->assertSame( Form_Config::DEFAULT_FILE_TYPES, Form_Config::sanitize_file_types( 'php' ) );
	}

	public function test_file_field_size_defaults_and_caps() {
		$f = Form_Config::sanitize_field( array( 'type' => 'file', 'max_size' => -3 ) );
		$this->assertEquals( 5, $f['max_size'] );
		$f = Form_Config::sanitize_field( array( 'type' => 'file', 'max_size' => 99999 ) );
		$this->assertEquals( 1024, $f['max_size'] );
	}

	public function test_conditions_referencing_missing_fields_are_removed() {
		$config = Form_Config::sanitize(
			array(
				'fields' => array(
					array( 'type' => 'text', 'name' => 'a' ),
					array(
						'type'       => 'text',
						'name'       => 'b',
						'conditions' => array(
							'enabled' => true,
							'action'  => 'hide',
							'match'   => 'any',
							'rules'   => array(
								array( 'field' => 'a', 'operator' => 'is', 'value' => 'x' ),
								array( 'field' => 'missing', 'operator' => 'is', 'value' => 'x' ),
								array( 'field' => 'b', 'operator' => 'is', 'value' => 'self' ),
								array( 'field' => 'a', 'operator' => 'eval', 'value' => 'x' ),
							),
						),
					),
					array(
						'type'       => 'text',
						'name'       => 'c',
						'conditions' => array(
							'enabled' => true,
							'rules'   => array( array( 'field' => 'nope', 'operator' => 'is', 'value' => '' ) ),
						),
					),
				),
			)
		);
		$this->assertSame( array( array( 'field' => 'a', 'operator' => 'is', 'value' => 'x' ) ), $config['fields'][1]['conditions']['rules'] );
		$this->assertSame( 'hide', $config['fields'][1]['conditions']['action'] );
		$this->assertSame( 'any', $config['fields'][1]['conditions']['match'] );
		$this->assertFalse( $config['fields'][2]['conditions']['enabled'] );
	}

	public function test_number_and_select_specific_props() {
		$n = Form_Config::sanitize_field( array( 'type' => 'number', 'min' => '1', 'max' => 'abc', 'step' => '0.5' ) );
		$this->assertSame( '1', $n['min'] );
		$this->assertSame( '', $n['max'] );
		$this->assertSame( '0.5', $n['step'] );
		$s = Form_Config::sanitize_field( array( 'type' => 'select', 'multiple' => 'true', 'options' => 'a' ) );
		$this->assertTrue( $s['multiple'] );
		$h = Form_Config::sanitize_field( array( 'type' => 'hidden', 'required' => true ) );
		$this->assertFalse( $h['required'] );
	}

	public function test_mailchimp_settings_only_reference_existing_fields() {
		$config = Form_Config::sanitize(
			array(
				'fields'    => array( array( 'type' => 'email', 'name' => 'email' ), array( 'type' => 'text', 'name' => 'first' ) ),
				'mailchimp' => array(
					'enabled'       => '1',
					'list_id'       => 'abc123!<>',
					'email_field'   => 'email',
					'consent_field' => 'ghost',
					'merge_fields'  => array(
						array( 'tag' => 'fname', 'field' => 'first' ),
						array( 'tag' => 'LNAME', 'field' => 'ghost' ),
					),
				),
			)
		);
		$mc = $config['mailchimp'];
		$this->assertTrue( $mc['enabled'] );
		$this->assertSame( 'abc123', $mc['list_id'] );
		$this->assertSame( 'email', $mc['email_field'] );
		$this->assertSame( 'ghost', $mc['consent_field'], 'A missing consent field is kept so the gate stays closed.' );
		$this->assertSame( array( array( 'tag' => 'FNAME', 'field' => 'first' ) ), $mc['merge_fields'] );
	}

	public function test_notifications_sanitised() {
		$n = Form_Config::sanitize_notification(
			array(
				'to'         => "a@example.com\r\nBcc: evil@example.com",
				'from_email' => 'not an email',
				'message'    => "Line 1\nLine 2",
			)
		);
		$this->assertStringNotContainsString( "\n", $n['to'] );
		$this->assertSame( '', $n['from_email'] );
		$this->assertSame( "Line 1\nLine 2", $n['message'] );
		$this->assertTrue( $n['enabled'] );
	}

	public function test_meta_round_trip_via_forms_repository() {
		$id = liforms()->forms->create(
			'Quote',
			array(
				'fields'   => array( array( 'type' => 'text', 'label' => 'Company "Ltd"' ) ),
				'settings' => array( 'submit_label' => 'Get a quote' ),
			)
		);
		$form = liforms()->forms->get( $id );
		$this->assertSame( 'Quote', $form->title );
		$this->assertSame( 'Company "Ltd"', $form->fields[0]['label'] );
		$this->assertSame( 'Get a quote', $form->settings['submit_label'] );
		$this->assertNotNull( $form->get_field( 'company_ltd' ) );
		$this->assertTrue( $form->has_field_type( 'text' ) );
		$this->assertFalse( $form->has_field_type( 'file' ) );
	}

	public function test_get_only_returns_published_forms_of_correct_type() {
		$draft = self::factory()->post->create( array( 'post_type' => Forms::POST_TYPE, 'post_status' => 'draft' ) );
		$post  = self::factory()->post->create();
		$this->assertNull( liforms()->forms->get( $draft ) );
		$this->assertNotNull( liforms()->forms->get( $draft, false ) );
		$this->assertNull( liforms()->forms->get( $post ) );
		$this->assertNull( liforms()->forms->get( 999999 ) );
	}

	public function test_all_lists_published_forms() {
		$a = liforms()->forms->create( 'B form' );
		$b = liforms()->forms->create( 'A form' );
		$this->assertSame( array( $b => 'A form', $a => 'B form' ), liforms()->forms->all() );
	}

	public function test_meta_is_protected_and_block_editor_disabled() {
		$this->assertTrue( is_protected_meta( Forms::META_KEY, 'post' ) );
		$this->assertFalse( use_block_editor_for_post_type( Forms::POST_TYPE ) );
		$this->assertTrue( use_block_editor_for_post_type( 'page' ) );
	}

	public function test_classic_editor_save_requires_nonce_and_capability() {
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$id     = liforms()->forms->create( 'Classic' );
		$config = wp_json_encode( array( 'fields' => array( array( 'type' => 'email', 'label' => 'Work email' ) ) ) );

		// Without a nonce nothing changes.
		$_POST = array( Forms::META_KEY => wp_slash( $config ) );
		liforms()->forms->save_classic_editor( $id, get_post( $id ) );
		$this->assertSame( array(), liforms()->forms->get( $id )->fields );

		$_POST['liforms_builder_nonce'] = wp_create_nonce( 'liforms_builder_' . $id );
		liforms()->forms->save_classic_editor( $id, get_post( $id ) );
		$this->assertSame( 'work_email', liforms()->forms->get( $id )->fields[0]['name'] );

		// A subscriber cannot save even with a valid nonce.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_POST = array(
			Forms::META_KEY            => wp_slash( wp_json_encode( array( 'fields' => array() ) ) ),
			'liforms_builder_nonce' => wp_create_nonce( 'liforms_builder_' . $id ),
		);
		liforms()->forms->save_classic_editor( $id, get_post( $id ) );
		$this->assertCount( 1, liforms()->forms->get( $id )->fields );
		$_POST = array();
	}
}
