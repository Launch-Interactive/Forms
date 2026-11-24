<?php
/**
 * Front-end scripts load only what a form needs.
 *
 * @covers \LaunchInteractiveForms\Frontend
 * @covers \LaunchInteractiveForms\Form
 */
class AssetsTest extends LF_TestCase {

	public function set_up() {
		parent::set_up();
		$GLOBALS['wp_scripts'] = null; // A fresh queue, so earlier tests' printed scripts don't count as done.
		liforms()->frontend->register_assets();
	}

	public function tear_down() {
		$GLOBALS['wp_scripts'] = null;
		parent::tear_down();
	}

	private function conditional_field() {
		return $this->field(
			'text',
			'company',
			array(
				'conditions' => array(
					'enabled' => true,
					'action'  => 'show',
					'match'   => 'all',
					'rules'   => array( array( 'field' => 'name', 'operator' => 'not_empty', 'value' => '' ) ),
				),
			)
		);
	}

	private function footer_scripts() {
		ob_start();
		wp_print_scripts();
		return ob_get_clean();
	}

	public function test_form_without_conditions_skips_the_conditional_script() {
		$form = $this->make_form( array( $this->field( 'text', 'name' ) ) );
		$this->assertFalse( $form->has_conditions() );
		liforms()->renderer->render( $form );

		$html = $this->footer_scripts();
		$this->assertStringContainsString( 'assets/js/frontend.js', $html );
		$this->assertStringNotContainsString( 'conditional-logic.js', $html );
	}

	public function test_form_with_conditions_loads_it_deferred_before_the_main_script() {
		$form = $this->make_form( array( $this->field( 'text', 'name' ), $this->conditional_field() ) );
		$this->assertTrue( $form->has_conditions() );
		liforms()->renderer->render( $form );

		$html  = $this->footer_scripts();
		$logic = strpos( $html, 'conditional-logic.js' );
		$this->assertNotFalse( $logic );
		$this->assertLessThan( strpos( $html, 'assets/js/frontend.js' ), $logic, 'Runs before frontend.js.' );
		$this->assertMatchesRegularExpression( '#<script[^>]* defer [^>]*conditional-logic\.js#', $html );
		$this->assertMatchesRegularExpression( '#<script[^>]* defer [^>]*frontend\.js#', $html );
	}

	public function test_conditions_on_a_later_form_still_load_it_first() {
		liforms()->renderer->render( $this->make_form( array( $this->field( 'text', 'name' ) ) ) );
		liforms()->renderer->render( $this->make_form( array( $this->field( 'text', 'name' ), $this->conditional_field() ) ) );

		$html = $this->footer_scripts();
		$this->assertLessThan( strpos( $html, 'assets/js/frontend.js' ), strpos( $html, 'conditional-logic.js' ) );
		$this->assertSame( 1, substr_count( $html, 'conditional-logic.js' ) );
	}

	public function test_config_holds_only_what_the_script_uses() {
		liforms()->renderer->render( $this->make_form( array( $this->field( 'text', 'name' ) ) ) );
		$html = $this->footer_scripts();
		$this->assertStringContainsString( 'liformsConfig', $html );
		$this->assertStringNotContainsString( 'sending', $html );
	}
}
