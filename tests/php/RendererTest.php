<?php
/**
 * @covers \LaunchForms\Renderer
 * @covers \LaunchForms\Frontend
 */

use LaunchForms\Submission_Result;

class RendererTest extends LF_TestCase {

	private function render( $form, $args = array() ) {
		return launch_forms()->renderer->render( $form, $args );
	}

	private function dom( $html ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><div id="root">' . $html . '</div>' );
		libxml_clear_errors();
		return new DOMXPath( $doc );
	}

	public function test_minimal_semantic_markup_without_inline_styles() {
		$html = $this->render( $this->contact_form() );
		$this->assertStringStartsWith( '<form', $html );
		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertStringNotContainsString( '<table', $html );
		$this->assertStringNotContainsString( '<br', $html );
		$this->assertStringContainsString( 'class="wp-element-button"', $html, 'Submit button should inherit theme.json button styles.' );
		$this->assertStringNotContainsString( 'enctype', $html, 'enctype only needed for file uploads.' );

		$x = $this->dom( $html );
		$this->assertSame( 1, $x->query( '//form[@method="post" and @class="lf-form"]' )->length );
		$this->assertSame( 3, $x->query( '//div[contains(@class,"lf-field ")]' )->length );
		$this->assertSame( 'Submit', trim( $x->query( '//button[@type="submit"]' )->item( 0 )->textContent ) );
	}

	public function test_labels_are_associated_with_inputs() {
		$x = $this->dom( $this->render( $this->contact_form() ) );
		foreach ( $x->query( '//input[@name="lf[name]"] | //input[@name="lf[email]"] | //textarea[@name="lf[message]"]' ) as $input ) {
			$id = $input->getAttribute( 'id' );
			$this->assertNotEmpty( $id );
			$this->assertSame( 1, $x->query( '//label[@for="' . $id . '"]' )->length, "Missing label for $id" );
		}
	}

	public function test_required_and_types() {
		$x = $this->dom( $this->render( $this->contact_form() ) );
		$email = $x->query( '//input[@name="lf[email]"]' )->item( 0 );
		$this->assertSame( 'email', $email->getAttribute( 'type' ) );
		$this->assertTrue( $email->hasAttribute( 'required' ) );
		$this->assertSame( 'email', $email->getAttribute( 'autocomplete' ) );
		$this->assertFalse( $x->query( '//textarea[@name="lf[message]"]' )->item( 0 )->hasAttribute( 'required' ) );
		$this->assertSame( 2, $x->query( '//span[@class="lf-required"]' )->length );
	}

	public function test_hidden_form_id_and_honeypot() {
		$form = $this->contact_form();
		$x    = $this->dom( $this->render( $form ) );
		$this->assertSame( (string) $form->id, $x->query( '//input[@name="lf_form_id"]' )->item( 0 )->getAttribute( 'value' ) );
		$hp = $x->query( '//div[@class="lf-hp"]//input[@name="lf_hp"]' )->item( 0 );
		$this->assertSame( '-1', $hp->getAttribute( 'tabindex' ) );
		$this->assertSame( 'off', $hp->getAttribute( 'autocomplete' ) );
	}

	public function test_choice_fields() {
		$form = $this->make_form(
			array(
				$this->field( 'select', 'colour', array( 'options' => "Red|r\nBlue|b", 'default' => 'b', 'placeholder' => 'Pick one' ) ),
				$this->field( 'select', 'sizes', array( 'options' => "S\nM\nL", 'multiple' => true, 'default' => 'S, L' ) ),
				$this->field( 'radio', 'plan', array( 'options' => "Free\nPro", 'required' => true ) ),
				$this->field( 'checkbox', 'topics', array( 'options' => "News\nOffers", 'default' => 'Offers' ) ),
				$this->field( 'consent', 'agree', array( 'label' => 'I agree', 'required' => true ) ),
			)
		);
		$x = $this->dom( $this->render( $form ) );

		$this->assertSame( 'Pick one', $x->query( '//select[@name="lf[colour]"]/option[1]' )->item( 0 )->textContent );
		$this->assertSame( 'b', $x->query( '//select[@name="lf[colour]"]/option[@selected]' )->item( 0 )->getAttribute( 'value' ) );

		$this->assertTrue( $x->query( '//select[@name="lf[sizes][]"]' )->item( 0 )->hasAttribute( 'multiple' ) );
		$this->assertSame( 2, $x->query( '//select[@name="lf[sizes][]"]/option[@selected]' )->length );

		$this->assertSame( 1, $x->query( '//fieldset[contains(@class,"lf-field--radio")]/legend' )->length );
		$this->assertSame( 2, $x->query( '//input[@type="radio" and @name="lf[plan]" and @required]' )->length );

		$this->assertSame( 2, $x->query( '//input[@type="checkbox" and @name="lf[topics][]"]' )->length );
		$this->assertSame( 'Offers', $x->query( '//input[@name="lf[topics][]" and @checked]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $x->query( '//input[@name="lf[topics][]" and @required]' )->length, 'Native required on a checkbox group would require every box.' );

		$agree = $x->query( '//input[@name="lf[agree]"]' )->item( 0 );
		$this->assertSame( 'checkbox', $agree->getAttribute( 'type' ) );
		$this->assertSame( '1', $agree->getAttribute( 'value' ) );
	}

	public function test_number_date_hidden_and_content_fields() {
		$form = $this->make_form(
			array(
				$this->field( 'number', 'qty', array( 'min' => 1, 'max' => 10, 'step' => 1 ) ),
				$this->field( 'date', 'when' ),
				$this->field( 'hidden', 'source', array( 'default' => 'landing' ) ),
				array( 'type' => 'content', 'content' => '<p>Read <a href="/terms">terms</a></p><script>x()</script>' ),
			)
		);
		$html = $this->render( $form );
		$x    = $this->dom( $html );
		$qty  = $x->query( '//input[@name="lf[qty]"]' )->item( 0 );
		$this->assertSame( array( 'number', '1', '10', '1' ), array( $qty->getAttribute( 'type' ), $qty->getAttribute( 'min' ), $qty->getAttribute( 'max' ), $qty->getAttribute( 'step' ) ) );
		$this->assertSame( 'date', $x->query( '//input[@name="lf[when]"]' )->item( 0 )->getAttribute( 'type' ) );
		$this->assertStringContainsString( '<input type="hidden" name="lf[source]" value="landing">', $html );
		$this->assertStringContainsString( '<a href="/terms">terms</a>', $html );
		$this->assertStringNotContainsString( '<script>x()', $html );
	}

	public function test_file_field_sets_enctype_and_accept() {
		$form = $this->make_form( array( $this->field( 'file', 'cv', array( 'allowed_types' => 'pdf,docx', 'max_size' => 2 ) ) ) );
		$html = $this->render( $form );
		$this->assertStringContainsString( 'enctype="multipart/form-data"', $html );
		$this->assertStringContainsString( 'accept=".pdf,.docx"', $html );
		$this->assertStringContainsString( 'Allowed: pdf, docx. Max 2 MB.', $html );
	}

	public function test_output_is_escaped() {
		$form = $this->make_form(
			array(
				$this->field( 'text', 'x', array( 'label' => 'A "quoted" <b>label</b>', 'placeholder' => '"><script>', 'default' => '"><img src=x onerror=alert(1)>' ) ),
			),
			array( 'settings' => array( 'submit_label' => '<em>Go</em>' ) )
		);
		$html = $this->render( $form );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( '<em>', $html );
	}

	public function test_conditional_fields_render_hidden_and_disabled_initially() {
		$form = $this->make_form(
			array(
				$this->field( 'select', 'reason', array( 'options' => "Sales\nOther" ) ),
				$this->field(
					'text',
					'other',
					array(
						'required'   => true,
						'conditions' => array(
							'enabled' => true,
							'rules'   => array( array( 'field' => 'reason', 'operator' => 'is', 'value' => 'Other' ) ),
						),
					)
				),
			)
		);
		$x       = $this->dom( $this->render( $form ) );
		$wrapper = $x->query( '//div[@data-lf-name="other"]' )->item( 0 );
		$this->assertTrue( $wrapper->hasAttribute( 'hidden' ) );
		$conditions = json_decode( $wrapper->getAttribute( 'data-lf-conditions' ), true );
		$this->assertSame( 'reason', $conditions['rules'][0]['field'] );
		$this->assertTrue( $x->query( '//input[@name="lf[other]"]' )->item( 0 )->hasAttribute( 'disabled' ) );
		$this->assertFalse( $x->query( '//div[@data-lf-name="reason"]' )->item( 0 )->hasAttribute( 'data-lf-conditions' ) );
	}

	public function test_errors_and_previous_values_are_rendered() {
		$form = $this->contact_form();
		launch_forms()->processor->process( $form, array( 'lf' => array( 'name' => 'Ann <b>', 'email' => 'nope' ) ) );
		$html = $this->render( $form );
		$x    = $this->dom( $html );

		$this->assertSame( 1, $x->query( '//div[contains(@class,"lf-message--error") and @role="alert"]' )->length );
		$email = $x->query( '//input[@name="lf[email]"]' )->item( 0 );
		$this->assertSame( 'true', $email->getAttribute( 'aria-invalid' ) );
		$error_id = $email->getAttribute( 'aria-describedby' );
		$this->assertSame( 'Please enter a valid email address.', $x->query( '//p[@id="' . $error_id . '"]' )->item( 0 )->textContent );
		$this->assertSame( 'Ann', $x->query( '//input[@name="lf[name]"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'nope', $email->getAttribute( 'value' ) );
	}

	public function test_success_message_replaces_form_after_redirect() {
		$form           = $this->contact_form( array( 'settings' => array( 'success_message' => '<strong>Done!</strong>' ) ) );
		$_GET['lf_sent'] = (string) $form->id;
		$html           = $this->render( $form );
		unset( $_GET['lf_sent'] );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( '<strong>Done!</strong>', $html );
		$this->assertStringContainsString( 'id="lf-form-' . $form->id . '"', $html );
	}

	public function test_success_message_above_fresh_form_when_not_hiding() {
		$form            = $this->contact_form( array( 'settings' => array( 'hide_form_on_success' => false ) ) );
		$_GET['lf_sent'] = (string) $form->id;
		$html            = $this->render( $form );
		unset( $_GET['lf_sent'] );
		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'lf-message--success', $html );
	}

	public function test_same_form_twice_on_page_has_unique_ids() {
		$form   = $this->contact_form();
		$first  = $this->render( $form );
		$second = $this->render( $form );
		$this->assertStringContainsString( 'id="lf-form-' . $form->id . '"', $first );
		$this->assertStringContainsString( 'id="lf-form-' . $form->id . '-2"', $second );
		$this->assertStringContainsString( 'id="lf-' . $form->id . '-2-email"', $second );
	}

	public function test_render_id_for_missing_form() {
		$this->assertSame( '', launch_forms()->renderer->render_id( 987654 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertStringContainsString( 'Form not found', launch_forms()->renderer->render_id( 987654 ) );
	}

	public function test_shortcode_renders_form_with_class() {
		$form = $this->contact_form();
		$html = do_shortcode( '[launch_form id="' . $form->id . '" class="my-form other"]' );
		$this->assertStringContainsString( 'class="lf-form my-form other"', $html );
		$this->assertStringContainsString( 'name="lf[email]"', $html );
		$this->assertTrue( shortcode_exists( 'launch_form' ) );

		$html = launch_forms()->frontend->shortcode( array( 'id' => $form->id, 'class' => 'ok "><script>' ) );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertSame( '', launch_forms()->frontend->shortcode( array() ) );
	}

	public function test_block_is_registered_and_renders() {
		$form = $this->contact_form();
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'launch-forms/form' ) );
		$html = do_blocks( '<!-- wp:launch-forms/form {"formId":' . $form->id . ',"className":"is-style-x"} /-->' );
		$this->assertStringContainsString( 'class="lf-form wp-block-launch-forms-form is-style-x"', $html );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:launch-forms/form /-->' ) ) );
	}

	public function test_same_form_reused_across_pages() {
		$form  = $this->contact_form();
		$page1 = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '<!-- wp:launch-forms/form {"formId":' . $form->id . '} /-->' ) );
		$page2 = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '[launch_form id="' . $form->id . '"]' ) );
		foreach ( array( $page1, $page2 ) as $page ) {
			$html = apply_filters( 'the_content', get_post( $page )->post_content );
			$this->assertStringContainsString( 'data-lf-form="' . $form->id . '"', $html );
		}
	}

	public function test_assets_enqueued_and_css_can_be_disabled() {
		$this->render( $this->contact_form() );
		$this->assertTrue( wp_script_is( 'launch-forms', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'launch-forms', 'enqueued' ) );
		$this->assertStringEndsWith( 'assets/css/launch-forms.css', wp_styles()->registered['launch-forms']->src );

		launch_forms()->settings->update( array( 'load_css' => false ) );
		wp_deregister_style( 'launch-forms' );
		launch_forms()->frontend->register_assets();
		$this->assertFalse( wp_styles()->registered['launch-forms']->src );
	}

	public function test_structural_css_is_small_and_has_no_theme_styling() {
		$css = file_get_contents( LAUNCH_FORMS_DIR . 'assets/css/launch-forms.css' );
		$this->assertLessThan( 2048, strlen( $css ) );
		foreach ( array( 'font-family', 'font-size', 'background', 'border-radius', 'box-shadow' ) as $prop ) {
			$this->assertStringNotContainsString( $prop, $css );
		}
	}

	public function test_html_filter() {
		add_filter(
			'launch_forms_form_html',
			static function ( $html ) {
				return $html . '<!-- filtered -->';
			}
		);
		$this->assertStringEndsWith( '<!-- filtered -->', $this->render( $this->contact_form() ) );
	}
}
