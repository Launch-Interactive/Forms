<?php
/**
 * @covers \LaunchInteractiveForms\Conditional_Logic
 */

use LaunchInteractiveForms\Conditional_Logic;

class ConditionalLogicTest extends LF_TestCase {

	/**
	 * @dataProvider rule_provider
	 */
	public function test_rule_matches( $operator, $value, $expected, $result ) {
		$this->assertSame( $result, Conditional_Logic::rule_matches( $operator, $value, $expected ) );
	}

	public function rule_provider() {
		return array(
			'is exact'                 => array( 'is', 'Yes', 'yes', true ),
			'is trims'                 => array( 'is', '  yes ', 'yes', true ),
			'is different'             => array( 'is', 'no', 'yes', false ),
			'is in array'              => array( 'is', array( 'a', 'b' ), 'b', true ),
			'is empty expected'        => array( 'is', '', '', true ),
			'is_not'                   => array( 'is_not', 'no', 'yes', true ),
			'is_not array'             => array( 'is_not', array( 'a' ), 'a', false ),
			'contains'                 => array( 'contains', 'Hello World', 'world', true ),
			'contains array item'      => array( 'contains', array( 'apple pie', 'x' ), 'pie', true ),
			'contains miss'            => array( 'contains', 'Hello', 'bye', false ),
			'not_contains'             => array( 'not_contains', 'Hello', 'bye', true ),
			'empty string'             => array( 'empty', '', '', true ),
			'empty whitespace'         => array( 'empty', '   ', '', true ),
			'empty array'              => array( 'empty', array(), '', true ),
			'empty array of blanks'    => array( 'empty', array( '' ), '', true ),
			'not_empty'                => array( 'not_empty', '0', '', true ),
			'greater_than'             => array( 'greater_than', '10', '9.5', true ),
			'greater_than equal'       => array( 'greater_than', '10', '10', false ),
			'less_than'                => array( 'less_than', '-1', '0', true ),
			'numeric non number'       => array( 'greater_than', 'abc', '1', false ),
			'numeric array'            => array( 'greater_than', array( '5', '6' ), '1', false ),
			'unknown operator'         => array( 'regex', 'x', 'x', false ),
		);
	}

	private function conditional( $name, $rules, $action = 'show', $match = 'all' ) {
		return array(
			'name'       => $name,
			'conditions' => array(
				'enabled' => true,
				'action'  => $action,
				'match'   => $match,
				'rules'   => $rules,
			),
		);
	}

	public function test_show_when_all_rules_match() {
		$fields = array(
			array( 'name' => 'type' ),
			array( 'name' => 'size' ),
			$this->conditional(
				'details',
				array(
					array( 'field' => 'type', 'operator' => 'is', 'value' => 'other' ),
					array( 'field' => 'size', 'operator' => 'greater_than', 'value' => '3' ),
				)
			),
		);
		$this->assertFalse( Conditional_Logic::visibility( $fields, array( 'type' => 'other', 'size' => '2' ) )['details'] );
		$this->assertTrue( Conditional_Logic::visibility( $fields, array( 'type' => 'other', 'size' => '4' ) )['details'] );
		$this->assertTrue( Conditional_Logic::visibility( $fields, array() )['type'] );
	}

	public function test_hide_when_any_rule_matches() {
		$fields = array(
			array( 'name' => 'a' ),
			array( 'name' => 'b' ),
			$this->conditional(
				'c',
				array(
					array( 'field' => 'a', 'operator' => 'is', 'value' => '1' ),
					array( 'field' => 'b', 'operator' => 'is', 'value' => '1' ),
				),
				'hide',
				'any'
			),
		);
		$this->assertTrue( Conditional_Logic::visibility( $fields, array( 'a' => '0', 'b' => '0' ) )['c'] );
		$this->assertFalse( Conditional_Logic::visibility( $fields, array( 'a' => '0', 'b' => '1' ) )['c'] );
	}

	public function test_hidden_controller_counts_as_empty() {
		$fields = array(
			array( 'name' => 'contact' ),
			$this->conditional( 'phone', array( array( 'field' => 'contact', 'operator' => 'is', 'value' => 'phone' ) ) ),
			$this->conditional( 'time', array( array( 'field' => 'phone', 'operator' => 'not_empty', 'value' => '' ) ) ),
		);
		// Phone has a stale value but is hidden, so "time" must be hidden too.
		$vis = Conditional_Logic::visibility( $fields, array( 'contact' => 'email', 'phone' => '555', 'time' => 'am' ) );
		$this->assertFalse( $vis['phone'] );
		$this->assertFalse( $vis['time'] );

		$vis = Conditional_Logic::visibility( $fields, array( 'contact' => 'phone', 'phone' => '555' ) );
		$this->assertTrue( $vis['phone'] );
		$this->assertTrue( $vis['time'] );
	}

	public function test_circular_references_do_not_loop() {
		$fields = array(
			$this->conditional( 'a', array( array( 'field' => 'b', 'operator' => 'empty', 'value' => '' ) ) ),
			$this->conditional( 'b', array( array( 'field' => 'a', 'operator' => 'empty', 'value' => '' ) ) ),
		);
		$vis = Conditional_Logic::visibility( $fields, array() );
		$this->assertArrayHasKey( 'a', $vis );
		$this->assertArrayHasKey( 'b', $vis );
	}

	public function test_disabled_conditions_are_ignored() {
		$field                          = $this->conditional( 'x', array( array( 'field' => 'y', 'operator' => 'is', 'value' => 'never' ) ) );
		$field['conditions']['enabled'] = false;
		$this->assertTrue( Conditional_Logic::visibility( array( array( 'name' => 'y' ), $field ), array() )['x'] );
	}

	/**
	 * The JS implementation must agree with PHP. Runs the same cases through Node if available.
	 */
	public function test_php_and_js_implementations_agree() {
		$node = trim( (string) shell_exec( 'command -v node' ) );
		if ( '' === $node ) {
			$this->markTestSkipped( 'Node.js not available.' );
		}
		$cases = array();
		foreach ( $this->rule_provider() as $name => $case ) {
			$cases[] = array(
				'name'     => $name,
				'operator' => $case[0],
				'value'    => $case[1],
				'expected' => $case[2],
			);
		}
		$script = 'const l=require(' . wp_json_encode( LIFORMS_DIR . 'assets/js/conditional-logic.js' ) . ');'
			. 'const cases=' . wp_json_encode( $cases ) . ';'
			. 'console.log(JSON.stringify(cases.map(c=>l.ruleMatches(c.operator,c.value,c.expected))));';
		$output = shell_exec( escapeshellarg( $node ) . ' -e ' . escapeshellarg( $script ) );
		$js     = json_decode( (string) $output, true );
		$this->assertIsArray( $js );
		foreach ( $cases as $i => $case ) {
			$this->assertSame( Conditional_Logic::rule_matches( $case['operator'], $case['value'], $case['expected'] ), $js[ $i ], 'Mismatch: ' . $case['name'] );
		}
	}
}
