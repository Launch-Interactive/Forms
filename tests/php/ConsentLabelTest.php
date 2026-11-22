<?php
/**
 * Links in a consent checkbox's label.
 *
 * @covers \LaunchForms\Form_Config
 * @covers \LaunchForms\Renderer
 * @covers \LaunchForms\Processor
 */

use LaunchForms\Form_Config;

class ConsentLabelTest extends LF_TestCase {

	const LABEL = 'I agree to the <a href="#terms">terms and conditions</a>';

	private function consent_form( $label = self::LABEL, array $extra = array() ) {
		return $this->make_form(
			array(
				$this->field( 'email', 'email' ),
				$this->field( 'consent', 'terms', array_merge( array( 'label' => $label, 'required' => true ), $extra ) ),
			),
			array( 'notifications' => array( array( 'enabled' => true, 'to' => 'a@example.org', 'subject' => 'S', 'message' => '{all_fields}' ) ) )
		);
	}

	public function test_link_is_kept_and_rendered_inside_the_label() {
		$form = $this->consent_form();
		$this->assertSame( self::LABEL, $form->fields[1]['label'] );
		$html = launch_forms()->renderer->render( $form );
		$this->assertMatchesRegularExpression( '#<label for="[^"]+">I agree to the <a href="\#terms">terms and conditions</a> <span class="lf-required"#', $html );
	}

	public function test_unsafe_html_is_removed() {
		$form  = $this->consent_form( 'I <strong>agree</strong> <a href="javascript:alert(1)" onclick="x()">here</a><script>alert(2)</script> <img src=x onerror=y> <em>ok</em>' );
		$label = $form->fields[1]['label'];
		$this->assertSame( 'I <strong>agree</strong> <a href="alert(1)">here</a>alert(2) <em>ok</em>', $label );
		$html = launch_forms()->renderer->render( $form );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'onclick', $html );
		$this->assertStringNotContainsString( '<script>alert(2)', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}

	public function test_new_tab_links_get_noopener() {
		$form = $this->consent_form( 'I agree to the <a href="/terms" target="_blank">terms</a>' );
		$this->assertSame( 'I agree to the <a rel="noopener noreferrer" href="/terms" target="_blank">terms</a>', $form->fields[1]['label'] );
		$form = $this->consent_form( 'I agree to the <a href="/terms" target="_blank" rel="opener">terms</a>' );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $form->fields[1]['label'], 'An author-supplied rel is replaced.' );
	}

	public function test_other_field_labels_stay_plain_text() {
		$form = $this->make_form( array( $this->field( 'text', 'name', array( 'label' => 'Your <a href="#x">name</a>' ) ) ) );
		$this->assertSame( 'Your name', $form->fields[0]['label'] );
		$this->assertStringContainsString( '>Your name</label>', launch_forms()->renderer->render( $form ) );
	}

	public function test_emails_and_submissions_use_the_plain_text_label() {
		$form = $this->consent_form( 'I agree to the <a href="#terms">terms &amp; conditions</a>' );
		reset_phpmailer_instance();
		$result = launch_forms()->processor->process( $form, array( 'lf' => array( 'email' => 'ann@example.com', 'terms' => '1' ) ) );
		$this->assertTrue( $result->success );

		$labels = wp_list_pluck( $result->entries, 'label', 'name' );
		$this->assertSame( 'I agree to the terms & conditions', $labels['terms'] );

		$body = tests_retrieve_phpmailer_instance()->get_sent()->body;
		$this->assertStringContainsString( 'I agree to the terms & conditions: Yes', $body, 'Plain-text email.' );
		$this->assertStringNotContainsString( '<a href', $body );
	}

	public function test_field_key_made_from_a_linked_label_ignores_the_html() {
		$form = $this->make_form( array( array( 'type' => 'consent', 'label' => self::LABEL ) ) );
		$this->assertSame( 'i_agree_to_the_terms_and_conditions', $form->fields[0]['name'] );
	}

	public function test_plain_label() {
		$this->assertSame( 'Terms & "conditions"', Form_Config::plain_label( '<strong>Terms</strong> &amp; <a href="#">&quot;conditions&quot;</a>' ) );
	}
}
