<?php
declare(strict_types=1);

namespace Vodacek\GettextExtractor\Tests\Unit\GettextExtractor\Filters;

use PHPUnit\Framework\TestCase;
use Vodacek\GettextExtractor as GE;

class LatteFilterTest extends TestCase {

	/** @var GE\Filters\LatteFilter */
	private $object;

	protected function setUp(): void {
		$this->object = new GE\Filters\LatteFilter();
	}

	public function testExtract(): void {
		$messages = $this->object->extract(__DIR__ . '/../../data/latte/default.latte');

		self::assertContains(array(
			GE\Extractor::LINE => 2,
			GE\Extractor::SINGULAR => 'A message!'
		), $messages);

		self::assertContains(array(
			GE\Extractor::LINE => 3,
			GE\Extractor::SINGULAR => 'Another message!',
			GE\Extractor::CONTEXT => 'context'
		), $messages);

		self::assertContains(array(
			GE\Extractor::LINE => 4,
			GE\Extractor::SINGULAR => 'I see %d little indian!',
			GE\Extractor::PLURAL => 'I see %d little indians!'
		), $messages);

		self::assertContains(array(
			GE\Extractor::LINE => 5,
			GE\Extractor::SINGULAR => 'I see %d little indian!',
			GE\Extractor::PLURAL => 'I see %d little indians!',
			GE\Extractor::CONTEXT => 'context'
		), $messages);

		self::assertContains(array(
			GE\Extractor::LINE => 8,
			GE\Extractor::SINGULAR => 'A message!'
		), $messages);

		self::assertContains(array(
			GE\Extractor::LINE => 9,
			GE\Extractor::SINGULAR => 'Another message!',
			GE\Extractor::CONTEXT => 'context'
		), $messages);

		self::assertContains(array(
			GE\Extractor::LINE => 10,
			GE\Extractor::SINGULAR => 'I see %d little indian!',
			GE\Extractor::PLURAL => 'I see %d little indians!'
		), $messages);

		self::assertContains(array(
			GE\Extractor::LINE => 11,
			GE\Extractor::SINGULAR => 'I see %d little indian!',
			GE\Extractor::PLURAL => 'I see %d little indians!',
			GE\Extractor::CONTEXT => 'context'
		), $messages);
	}

	public function testCustomMacros(): void {
		$this->object->addFunction('custom', 1);
		$this->object->addFunction('!custom', 1);

		$messages = $this->object->extract(__DIR__ . '/../../data/latte/custom.latte');

		self::assertContains(array(
			GE\Extractor::LINE => 1,
			GE\Extractor::SINGULAR => 'A custom message!'
		), $messages);

		self::assertContains(array(
			GE\Extractor::LINE => 2,
			GE\Extractor::SINGULAR => 'An unescaped custom message!'
		), $messages);
	}

	public function testNoValidMessages(): void {
		$messages = $this->object->extract(__DIR__ . '/../../data/latte/noMessages.latte');
		self::assertSame(array(), $messages);
	}

	public function testConstantsArrayMethodsAndFunctions(): void {
		$messages = $this->object->extract(__DIR__ . '/../../data/latte/test.latte');
		self::assertCount(1, $messages);
		$expected = array(
			GE\Extractor::LINE => 1,
			GE\Extractor::SINGULAR => 'Message'
		);
		ksort($messages[0]);
		ksort($expected);
		self::assertSame(array($expected), $messages);
	}

	/**
	 * @group bug6
	 */
	public function testExtractMultilineMessage(): void {
		$messages = $this->object->extract(__DIR__ . '/../../data/latte/bug6.latte');

		self::assertContains(array(
			GE\Extractor::LINE => 1,
			GE\Extractor::SINGULAR => "A\nmultiline\nmessage!"
		), $messages);
	}

	public function testBackslashCharacter(): void {
		$messages = $this->object->extract(__DIR__ . '/../../data/latte/backslash.latte');

		self::assertContains(array(
			GE\Extractor::LINE => 1,
			GE\Extractor::SINGULAR => "Message with backslash (\\) character."
		), $messages);
	}

	public function testEmbeddedCalls(): void {
		$this->object->addFunction('translate', 1);

		$messages = $this->object->extract(__DIR__ . '/../../data/latte/embedded.latte');

		// Method call inside a print tag: {$x->translate('...')}
		self::assertContains(array(
			GE\Extractor::LINE => 1,
			GE\Extractor::SINGULAR => 'translateInPrintTag'
		), $messages);

		// Method call inside a captured print tag
		self::assertContains(array(
			GE\Extractor::LINE => 2,
			GE\Extractor::SINGULAR => 'translateInsideCapture'
		), $messages);

		// Inline _() function call as a tag-arg value
		self::assertContains(array(
			GE\Extractor::LINE => 3,
			GE\Extractor::SINGULAR => 'inlineFunctionInTagArg'
		), $messages);

		// Inline ->translate() method call as a tag-arg value
		self::assertContains(array(
			GE\Extractor::LINE => 4,
			GE\Extractor::SINGULAR => 'inlineMethodInTagArg'
		), $messages);

		// Both an inline method call and an inline _() in the same tag's args
		self::assertContains(array(
			GE\Extractor::LINE => 5,
			GE\Extractor::SINGULAR => 'inlineMethodInTagArg'
		), $messages);
		self::assertContains(array(
			GE\Extractor::LINE => 5,
			GE\Extractor::SINGULAR => 'inlineFunctionInTagArg2'
		), $messages);

		// Negative: {_('x')} nested inside a tag arg is not valid Latte 3 syntax
		// (TagParser rejects the literal `{`). We must not crash and must not extract it.
		foreach ($messages as $message) {
			self::assertNotSame('invalidNestedBraces', $message[GE\Extractor::SINGULAR] ?? null);
		}
	}
}
