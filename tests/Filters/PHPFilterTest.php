<?php

require_once dirname(__FILE__).'/FilterTest.php';
require_once dirname(__FILE__).'/../../Filters/PHPFilter.php';

/**
 * Test class for PHPFilter.
 * Generated by PHPUnit on 2010-12-15 at 21:59:45.
 */
class PHPFilterTest extends FilterTest {

	protected function setUp() {
		error_reporting(-1);
		$this->object = new PHPFilter();
		$this->object->addFunction('addRule', 2);
		$this->file = dirname(__FILE__) . '/../data/default.php';
	}

	public function testFunctionCallWithVariables() {
		$messages = $this->object->extract($this->file);

		$this->assertNotContains(array(
			iFilter::LINE => 7
		),$messages);

		$this->assertNotContains(array(
			iFilter::LINE => 8,
			iFilter::CONTEXT => 'context'
		),$messages);

		$this->assertNotContains(array(
			iFilter::LINE => 9,
			iFilter::SINGULAR => 'I see %d little indian!',
			iFilter::PLURAL => 'I see %d little indians!'
		),$messages);
	}

	public function testNestedFunctions() {
		$messages = $this->object->extract($this->file);

		$this->assertNotContains(array(
			iFilter::LINE => 11,
			iFilter::SINGULAR => 'Some string.'
		),$messages);

		$this->assertContains(array(
			iFilter::LINE => 12,
			iFilter::SINGULAR => 'Nested function.'
		),$messages);

		$this->assertContains(array(
			iFilter::LINE => 13,
			iFilter::SINGULAR => 'Nested function 2.',
			iFilter::CONTEXT => 'context'
		),$messages);
		$this->assertNotContains(array(
			iFilter::LINE => 13,
			iFilter::SINGULAR => 'context'
		),$messages);

		$this->assertContains(array(
			iFilter::LINE => 14,
			iFilter::SINGULAR => "%d meeting wasn't imported.",
			iFilter::PLURAL => "%d meetings weren't importeded."
		),$messages);
		$this->assertNotContains(array(
			iFilter::LINE => 14,
			iFilter::SINGULAR => "%d meeting wasn't imported."
		),$messages);

		$this->assertContains(array(
			iFilter::LINE => 17,
			iFilter::SINGULAR => "Please provide a text 2."
		),$messages);
		$this->assertContains(array(
			iFilter::LINE => 18,
			iFilter::SINGULAR => "Please provide a text 3."
		),$messages);
	}

	public function testConstantAsParameter() {
		$messages = $this->object->extract($this->file);

		$this->assertContains(array(
			iFilter::LINE => 16,
			iFilter::SINGULAR => "Please provide a text."
		),$messages);
	}
}
