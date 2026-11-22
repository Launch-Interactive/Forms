<?php
/**
 * The Country field.
 *
 * @covers \LaunchForms\Countries
 * @covers \LaunchForms\Form_Config
 * @covers \LaunchForms\Renderer
 * @covers \LaunchForms\Validator
 */

use LaunchForms\Countries;

class CountryFieldTest extends LF_TestCase {

	private function country_form( array $extra = array(), ?array $conditions_field = null ) {
		$fields = array(
			$this->field( 'text', 'name' ),
			$this->field( 'country', 'country', $extra ),
		);
		if ( $conditions_field ) {
			$fields[] = $conditions_field;
		}
		return $this->make_form( $fields );
	}

	private function submit( $form, array $values ) {
		return launch_forms()->processor->process( $form, array( 'lf' => $values ) );
	}

	public function test_list_is_complete_sorted_and_unique() {
		$all = Countries::all();
		$this->assertGreaterThanOrEqual( 245, count( $all ) );
		$this->assertSame( 'Australia', $all['AU'] );
		$this->assertSame( 'United Kingdom', $all['GB'] );
		$this->assertSame( array_unique( $all ), $all, 'No duplicate names.' );

		$names  = array_values( $all );
		$sorted = $names;
		usort(
			$sorted,
			static function ( $a, $b ) {
				return strcasecmp( remove_accents( $a ), remove_accents( $b ) );
			}
		);
		$this->assertSame( $sorted, $names );
		$this->assertSame( 'Afghanistan', $names[0] );
		$this->assertSame( 'Åland Islands', $names[1], 'Accented names sort with their letter.' );
	}

	public function test_renders_a_select_of_countries() {
		$form = $this->country_form( array( 'required' => true, 'placeholder' => 'Choose a country' ) );
		$html = launch_forms()->renderer->render( $form );
		$this->assertMatchesRegularExpression( '/<select[^>]*name="lf\[country\]"[^>]*>/', $html );
		$this->assertMatchesRegularExpression( '/<select[^>]*autocomplete="country-name"/', $html, 'Browsers can autofill it.' );
		$this->assertMatchesRegularExpression( '/<select[^>]*required/', $html );
		$this->assertStringContainsString( '<option value="">Choose a country</option>', $html );
		$this->assertStringContainsString( '<option value="Australia" data-code="AU">Australia</option>', $html );
		$this->assertStringContainsString( '<option value="Côte d&#039;Ivoire" data-code="CI">Côte d&#039;Ivoire</option>', $html );
		$this->assertSame( count( Countries::all() ) + 1, substr_count( $html, '<option' ) );
	}

	public function test_default_country_is_preselected() {
		$form = $this->country_form( array( 'default' => 'New Zealand' ) );
		$this->assertStringContainsString( '<option value="New Zealand" data-code="NZ" selected=\'selected\'>', launch_forms()->renderer->render( $form ) );
	}

	public function test_unknown_default_is_dropped_on_save() {
		$form = $this->country_form( array( 'default' => 'Atlantis' ) );
		$this->assertSame( '', $form->fields[1]['default'] );
	}

	public function test_valid_country_is_stored_by_name() {
		$result = $this->submit( $this->country_form(), array( 'name' => 'Ann', 'country' => "Côte d'Ivoire" ) );
		$this->assertTrue( $result->success );
		$entries = wp_list_pluck( $result->entries, 'value', 'name' );
		$this->assertSame( "Côte d'Ivoire", $entries['country'] );
	}

	public function test_made_up_country_is_rejected() {
		$result = $this->submit( $this->country_form(), array( 'name' => 'Ann', 'country' => 'Atlantis' ) );
		$this->assertFalse( $result->success );
		$this->assertSame( 'Please choose a country from the list.', $result->errors['country'] );
	}

	public function test_required() {
		$form = $this->country_form( array( 'required' => true ) );
		$this->assertSame( 'This field is required.', $this->submit( $form, array( 'name' => 'Ann' ) )->errors['country'] );
		$this->assertTrue( $this->submit( $form, array( 'name' => 'Ann', 'country' => 'Australia' ) )->success );
	}

	public function test_filter_can_limit_the_list() {
		add_filter(
			'launch_forms_countries',
			static function ( $countries ) {
				return array_intersect_key( $countries, array_flip( array( 'AU', 'NZ' ) ) );
			}
		);
		$form = $this->country_form();
		$this->assertSame( 3, substr_count( launch_forms()->renderer->render( $form ), '<option' ) );
		$this->assertFalse( $this->submit( $form, array( 'name' => 'Ann', 'country' => 'France' ) )->success );
		$this->assertTrue( $this->submit( $form, array( 'name' => 'Ann', 'country' => 'New Zealand' ) )->success );
	}

	public function test_conditional_logic_on_country() {
		$state = $this->field(
			'text',
			'state',
			array(
				'required'   => true,
				'conditions' => array(
					'enabled' => true,
					'action'  => 'show',
					'match'   => 'all',
					'rules'   => array( array( 'field' => 'country', 'operator' => 'is', 'value' => 'Australia' ) ),
				),
			)
		);
		$form = $this->country_form( array(), $state );
		$this->assertTrue( $this->submit( $form, array( 'name' => 'Ann', 'country' => 'France' ) )->success, 'State hidden, so not required.' );
		$this->assertArrayHasKey( 'state', $this->submit( $form, array( 'name' => 'Ann', 'country' => 'Australia' ) )->errors );
	}

	public function test_appears_in_notification_email() {
		$form = $this->make_form(
			array( $this->field( 'country', 'country' ) ),
			array( 'notifications' => array( array( 'enabled' => true, 'to' => 'a@example.org', 'subject' => 'S', 'message' => 'From {field:country}' ) ) )
		);
		reset_phpmailer_instance();
		$this->submit( $form, array( 'country' => 'Australia' ) );
		$this->assertStringContainsString( 'From Australia', tests_retrieve_phpmailer_instance()->get_sent()->body );
	}

	public function test_offered_in_the_builder() {
		$data = launch_forms()->admin->builder_data();
		$this->assertSame( 'Country', $data['fieldTypes']['country'] );
		$this->assertContains( 'Australia', $data['countries'] );
	}
}
