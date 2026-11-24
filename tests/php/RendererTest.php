<?php
/**
 * @covers \LaunchInteractiveForms\Renderer
 * @covers \LaunchInteractiveForms\Frontend
 */

use LaunchInteractiveForms\Submission_Result;

class RendererTest extends LF_TestCase {

	private function render( $form, $args = array() ) {
		return liforms()->renderer->render( $form, $args );
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
		$this->assertSame( 1, $x->query( '//form[@method="post" and @class="lif-form"]' )->length );
		$this->assertSame( 3, $x->query( '//div[contains(@class,"lif-field ")]' )->length );
		$this->assertSame( 'Submit', trim( $x->query( '//button[@type="submit"]' )->item( 0 )->textContent ) );
	}

	public function test_fields_sit_directly_in_the_form_and_carry_their_width() {
		$form = $this->make_form(
			array(
				$this->field( 'text', 'first', array( 'width' => 'half' ) ),
				$this->field( 'text', 'last', array( 'width' => 'half' ) ),
				$this->field( 'textarea', 'message' ),
			)
		);
		$x = $this->dom( $this->render( $form ) );
		$this->assertSame( 3, $x->query( '//form/div[contains(@class,"lif-field ")]' )->length, 'Fields are direct children of the form (no wrapper).' );
		$this->assertSame( 0, $x->query( '//div[@class="lif-fields"]' )->length );
		$this->assertSame( 2, $x->query( '//div[contains(concat(" ",@class," ")," lif-field--half ")]' )->length );
		$this->assertSame( 0, $x->query( '//div[contains(@class,"lif-field--full")]' )->length, 'Full width is the default and adds no class.' );
		$items = $x->query( '//form/div[not(contains(@class,"lif-hp"))]' );
		$this->assertSame( 'lif-submit', $items->item( $items->length - 1 )->getAttribute( 'class' ), 'The submit button is the last item, after the fields.' );
	}

	public function test_labels_are_associated_with_inputs() {
		$x = $this->dom( $this->render( $this->contact_form() ) );
		foreach ( $x->query( '//input[@name="lif[name]"] | //input[@name="lif[email]"] | //textarea[@name="lif[message]"]' ) as $input ) {
			$id = $input->getAttribute( 'id' );
			$this->assertNotEmpty( $id );
			$this->assertSame( 1, $x->query( '//label[@for="' . $id . '"]' )->length, "Missing label for $id" );
		}
	}

	public function test_required_and_types() {
		$x = $this->dom( $this->render( $this->contact_form() ) );
		$email = $x->query( '//input[@name="lif[email]"]' )->item( 0 );
		$this->assertSame( 'email', $email->getAttribute( 'type' ) );
		$this->assertTrue( $email->hasAttribute( 'required' ) );
		$this->assertSame( 'email', $email->getAttribute( 'autocomplete' ) );
		$this->assertFalse( $x->query( '//textarea[@name="lif[message]"]' )->item( 0 )->hasAttribute( 'required' ) );
		$this->assertSame( 2, $x->query( '//span[@class="lif-required"]' )->length );
	}

	public function test_required_marker_comes_from_settings() {
		$form   = $this->contact_form();
		$marker = function () use ( $form ) {
			liforms()->renderer->reset();
			$spans = $this->dom( $this->render( $form ) )->query( '//label[@for="lif-' . $form->id . '-1-name"]/span[@class="lif-required"]' );
			return $spans->length ? $spans->item( 0 )->textContent : null;
		};
		$this->assertSame( '*', $marker(), 'Default.' );

		liforms()->settings->update( array( 'required_marker' => '(required)' ) );
		$this->assertSame( '(required)', $marker() );
		$this->assertStringContainsString( 'Name <span class="lif-required" aria-hidden="true">(required)</span>', $this->render( $form ) );

		liforms()->settings->update( array( 'required_marker' => '' ) );
		$this->assertNull( $marker(), 'Empty shows nothing.' );
		liforms()->renderer->reset();
		$this->assertStringContainsString( 'required', $this->render( $form ), 'Inputs still carry the required attribute.' );

		add_filter(
			'liforms_required_marker',
			static function () {
				return '<b>must</b>';
			}
		);
		$this->assertSame( '<b>must</b>', $marker(), 'Filtered markers are escaped, not rendered as HTML.' );
	}

	public function test_hidden_form_id_and_honeypot() {
		$form = $this->contact_form();
		$x    = $this->dom( $this->render( $form ) );
		$this->assertSame( (string) $form->id, $x->query( '//input[@name="lif_form_id"]' )->item( 0 )->getAttribute( 'value' ) );
		$hp = $x->query( '//div[@class="lif-hp"]//input[@name="lif_hp"]' )->item( 0 );
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

		$this->assertSame( 'Pick one', $x->query( '//select[@name="lif[colour]"]/option[1]' )->item( 0 )->textContent );
		$this->assertSame( 'b', $x->query( '//select[@name="lif[colour]"]/option[@selected]' )->item( 0 )->getAttribute( 'value' ) );

		$this->assertTrue( $x->query( '//select[@name="lif[sizes][]"]' )->item( 0 )->hasAttribute( 'multiple' ) );
		$this->assertSame( 2, $x->query( '//select[@name="lif[sizes][]"]/option[@selected]' )->length );

		$this->assertSame( 1, $x->query( '//fieldset[contains(@class,"lif-field--radio")]/legend' )->length );
		$this->assertSame( 2, $x->query( '//input[@type="radio" and @name="lif[plan]" and @required]' )->length );

		$this->assertSame( 2, $x->query( '//input[@type="checkbox" and @name="lif[topics][]"]' )->length );
		$this->assertSame( 'Offers', $x->query( '//input[@name="lif[topics][]" and @checked]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 0, $x->query( '//input[@name="lif[topics][]" and @required]' )->length, 'Native required on a checkbox group would require every box.' );

		$agree = $x->query( '//input[@name="lif[agree]"]' )->item( 0 );
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
		$qty  = $x->query( '//input[@name="lif[qty]"]' )->item( 0 );
		$this->assertSame( array( 'number', '1', '10', '1' ), array( $qty->getAttribute( 'type' ), $qty->getAttribute( 'min' ), $qty->getAttribute( 'max' ), $qty->getAttribute( 'step' ) ) );
		$this->assertSame( 'date', $x->query( '//input[@name="lif[when]"]' )->item( 0 )->getAttribute( 'type' ) );
		$this->assertStringContainsString( '<input type="hidden" name="lif[source]" value="landing">', $html );
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
		$wrapper = $x->query( '//div[@data-lif-name="other"]' )->item( 0 );
		$this->assertTrue( $wrapper->hasAttribute( 'hidden' ) );
		$conditions = json_decode( $wrapper->getAttribute( 'data-lif-conditions' ), true );
		$this->assertSame( 'reason', $conditions['rules'][0]['field'] );
		$this->assertTrue( $x->query( '//input[@name="lif[other]"]' )->item( 0 )->hasAttribute( 'disabled' ) );
		$this->assertFalse( $x->query( '//div[@data-lif-name="reason"]' )->item( 0 )->hasAttribute( 'data-lif-conditions' ) );
	}

	public function test_errors_and_previous_values_are_rendered() {
		$form = $this->contact_form();
		liforms()->processor->process( $form, array( 'lif' => array( 'name' => 'Ann <b>', 'email' => 'nope' ) ) );
		$html = $this->render( $form );
		$x    = $this->dom( $html );

		$this->assertSame( 1, $x->query( '//div[contains(@class,"lif-message--error") and @role="alert"]' )->length );
		$email = $x->query( '//input[@name="lif[email]"]' )->item( 0 );
		$this->assertSame( 'true', $email->getAttribute( 'aria-invalid' ) );
		$error_id = $email->getAttribute( 'aria-describedby' );
		$this->assertSame( 'Please enter a valid email address.', $x->query( '//p[@id="' . $error_id . '"]' )->item( 0 )->textContent );
		$this->assertSame( 'Ann', $x->query( '//input[@name="lif[name]"]' )->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'nope', $email->getAttribute( 'value' ) );
	}

	public function test_success_message_replaces_form_after_redirect() {
		$form           = $this->contact_form( array( 'settings' => array( 'success_message' => '<strong>Done!</strong>' ) ) );
		$_GET['lif_sent'] = (string) $form->id;
		$html           = $this->render( $form );
		unset( $_GET['lif_sent'] );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( '<strong>Done!</strong>', $html );
		$this->assertStringContainsString( 'id="lif-form-' . $form->id . '"', $html );
	}

	public function test_success_message_above_fresh_form_when_not_hiding() {
		$form            = $this->contact_form( array( 'settings' => array( 'hide_form_on_success' => false ) ) );
		$_GET['lif_sent'] = (string) $form->id;
		$html            = $this->render( $form );
		unset( $_GET['lif_sent'] );
		$this->assertStringContainsString( '<form', $html );
		$this->assertStringContainsString( 'lif-message--success', $html );
	}

	public function test_same_form_twice_on_page_has_unique_ids() {
		$form   = $this->contact_form();
		$first  = $this->render( $form );
		$second = $this->render( $form );
		$this->assertStringContainsString( 'id="lif-form-' . $form->id . '"', $first );
		$this->assertStringContainsString( 'id="lif-form-' . $form->id . '-2"', $second );
		$this->assertStringContainsString( 'id="lif-' . $form->id . '-2-email"', $second );
	}

	public function test_render_id_for_missing_form() {
		$this->assertSame( '', liforms()->renderer->render_id( 987654 ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertStringContainsString( 'Form not found', liforms()->renderer->render_id( 987654 ) );
	}

	public function test_shortcode_renders_form_with_class() {
		$form = $this->contact_form();
		$html = do_shortcode( '[liforms id="' . $form->id . '" class="my-form other"]' );
		$this->assertStringContainsString( 'class="lif-form my-form other"', $html );
		$this->assertStringContainsString( 'name="lif[email]"', $html );
		$this->assertTrue( shortcode_exists( 'liforms' ) );

		$html = liforms()->frontend->shortcode( array( 'id' => $form->id, 'class' => 'ok "><script>' ) );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertSame( '', liforms()->frontend->shortcode( array() ) );
	}

	public function test_block_is_registered_and_renders() {
		$form = $this->contact_form();
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'liforms/form' ) );
		$html = do_blocks( '<!-- wp:liforms/form {"formId":' . $form->id . ',"className":"is-style-x"} /-->' );
		$this->assertStringContainsString( 'class="lif-form wp-block-liforms-form is-style-x"', $html );
		$this->assertSame( '', trim( do_blocks( '<!-- wp:liforms/form /-->' ) ) );
	}

	public function test_same_form_reused_across_pages() {
		$form  = $this->contact_form();
		$page1 = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '<!-- wp:liforms/form {"formId":' . $form->id . '} /-->' ) );
		$page2 = self::factory()->post->create( array( 'post_type' => 'page', 'post_content' => '[liforms id="' . $form->id . '"]' ) );
		foreach ( array( $page1, $page2 ) as $page ) {
			$html = apply_filters( 'the_content', get_post( $page )->post_content );
			$this->assertStringContainsString( 'data-lif-form="' . $form->id . '"', $html );
		}
	}

	public function test_assets_enqueued_and_css_can_be_disabled() {
		$this->render( $this->contact_form() );
		$this->assertTrue( wp_script_is( 'liforms', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'liforms', 'enqueued' ) );
		$this->assertStringEndsWith( 'assets/css/liforms.css', wp_styles()->registered['liforms']->src );

		liforms()->settings->update( array( 'load_css' => false ) );
		wp_deregister_style( 'liforms' );
		liforms()->frontend->register_assets();
		$this->assertFalse( wp_styles()->registered['liforms']->src );
	}

	public function test_structural_css_is_small_and_has_no_theme_styling() {
		$css = file_get_contents( LIFORMS_DIR . 'assets/css/liforms.css' );
		$this->assertLessThan( 2048, strlen( $css ) );
		foreach ( array( 'font-family', 'font-size', 'background', 'border-radius', 'box-shadow' ) as $prop ) {
			$this->assertStringNotContainsString( $prop, $css );
		}
		$this->assertStringContainsString( 'gap: var(--lif-gap, 1em);', $css, 'One variable spaces rows and side-by-side fields.' );
		$this->assertStringNotContainsString( 'row-gap', $css );
	}

	public function test_html_filter() {
		add_filter(
			'liforms_form_html',
			static function ( $html ) {
				return $html . '<!-- filtered -->';
			}
		);
		$this->assertStringEndsWith( '<!-- filtered -->', $this->render( $this->contact_form() ) );
	}

	public function test_analytics_event_is_added_to_the_form_only_when_set() {
		$form = $this->contact_form();
		$this->assertStringNotContainsString( 'data-lif-event', liforms()->renderer->render( $form ) );

		$form = $this->contact_form( array( 'settings' => array( 'analytics_event' => 'contact_form_submit' ) ) );
		liforms()->renderer->reset();
		$html = liforms()->renderer->render( $form );
		$this->assertStringContainsString( 'data-lif-event="contact_form_submit"', $html );
		$this->assertStringContainsString( 'data-lif-event-form="Contact"', $html );
	}

	public function test_analytics_event_names_are_cleaned_to_ga4_rules() {
		$clean = static function ( $name ) {
			return \LaunchInteractiveForms\Form_Config::sanitize_event_name( $name );
		};
		$this->assertSame( 'contact_form_submit', $clean( 'contact_form_submit' ) );
		$this->assertSame( 'Contact_form_submit', $clean( ' Contact form submit! ' ) );
		$this->assertSame( 'form_sent', $clean( '123 form-sent' ), 'Must start with a letter.' );
		$this->assertSame( 'cafe_signup', $clean( 'café signup' ), 'Accents removed.' );
		$this->assertSame( 40, strlen( $clean( str_repeat( 'a', 60 ) ) ) );
		$this->assertSame( '', $clean( '!!!' ) );

		$form = $this->contact_form( array( 'settings' => array( 'analytics_event' => 'Lead "form" <b>' ) ) );
		$this->assertSame( 'Lead_form_b', $form->settings['analytics_event'] );
	}

	public function test_success_page_after_a_normal_submit_carries_the_analytics_event() {
		$form            = $this->contact_form( array( 'settings' => array( 'analytics_event' => 'contact_form_submit' ) ) );
		$_GET['lif_sent'] = (string) $form->id;
		$html            = $this->render( $form );
		$this->assertMatchesRegularExpression( '#<div id="lif-form-' . $form->id . '" class="lif-message lif-message--success" role="status" data-lif-sent data-lif-form="' . $form->id . '" data-lif-event="contact_form_submit" data-lif-event-form="Contact">#', $html );

		$form = $this->contact_form( array( 'settings' => array( 'analytics_event' => 'contact_form_submit', 'hide_form_on_success' => false ) ) );
		liforms()->renderer->reset();
		$_GET['lif_sent'] = (string) $form->id;
		$html            = $this->render( $form );
		unset( $_GET['lif_sent'] );
		$this->assertMatchesRegularExpression( '#<div class="lif-message lif-message--success" role="status" data-lif-sent [^>]*data-lif-event="contact_form_submit"#', $html, 'Also when the form stays visible.' );
		$this->assertSame( 1, substr_count( $html, 'data-lif-sent' ) );
	}

	public function test_success_page_without_an_analytics_event_has_no_marker() {
		$form            = $this->contact_form();
		$_GET['lif_sent'] = (string) $form->id;
		$html            = $this->render( $form );
		unset( $_GET['lif_sent'] );
		$this->assertStringNotContainsString( 'data-lif-sent', $html );
		$this->assertStringNotContainsString( 'data-lif-event', $html );
	}
}
