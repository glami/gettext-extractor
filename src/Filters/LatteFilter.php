<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2009 Karel Klíma
 * @copyright Copyright (c) 2010 Ondřej Vodáček
 * @license New BSD License
 */

namespace Vodacek\GettextExtractor\Filters;

use Nette\Utils\FileSystem;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use Vodacek\GettextExtractor\Extractor;
use PhpParser;

class LatteFilter extends AFilter implements IFilter {

	public function __construct() {
		$this->addFunction('_');
		$this->addFunction('!_');
		$this->addFunction('_n', 1, 2);
		$this->addFunction('!_n', 1, 2);
		$this->addFunction('_p', 2, null, 1);
		$this->addFunction('!_p', 2, null, 1);
		$this->addFunction('_np', 2, 3, 1);
		$this->addFunction('!_np', 2, 3, 1);
	}

	public function extract(string $file): array {
		$data = [];

		$functions = array_keys($this->functions);
		usort($functions, static function(string $a, string $b) {
			return strlen($b) <=> strlen($a);
		});

		$phpParser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();

		foreach ($this->scanLatteTags(FileSystem::read($file)) as $token) {
			$name = $this->findMacroName($token['text'], $functions);
			if ($name === null) {
				continue;
			}
			$value = $this->trimMacroValue($name, $token['value']);
			$stmts = $phpParser->parse("<?php\nf($value);");

			if ($stmts === null) {
				continue;
			}
			if ($stmts[0] instanceof Expression && $stmts[0]->expr instanceof FuncCall) {
				foreach ($this->functions[$name] as $definition) {
					$message = $this->processFunction($definition, $stmts[0]->expr);
					if ($message !== []) {
						$message[Extractor::LINE] = $token['line'];
						$data[] = $message;
					}
				}
			}
		}
		return $data;
	}

	/**
	 * Scans Latte template source and returns all tag tokens as arrays:
	 *   - text:  full tag including braces, e.g. {_'Hello'}
	 *   - value: content inside braces,     e.g. _'Hello'
	 *   - line:  1-based line number of the opening brace
	 *
	 * Works with both Latte 2 and Latte 3 — does not rely on Latte internals.
	 *
	 * @return list<array{text: string, value: string, line: int}>
	 */
	private function scanLatteTags(string $content): array {
		$tokens = [];
		$len = strlen($content);
		$i = 0;
		$line = 1;

		while ($i < $len) {
			$char = $content[$i];

			if ($char === "\n") {
				$line++;
				$i++;
				continue;
			}

			// Skip anything that is not a Latte tag opening:
			//   {{ ... }}  — JS/double-brace literal
			//   {* ... *}  — Latte comment
			if ($char !== '{' || ($i + 1 < $len && ($content[$i + 1] === '{' || $content[$i + 1] === '*'))) {
				$i++;
				continue;
			}

			$startLine = $line;
			$depth = 1;
			$j = $i + 1;
			$inString = false;
			$stringChar = '';

			while ($j < $len && $depth > 0) {
				$c = $content[$j];

				if ($c === "\n") {
					$line++;
				}

				if ($inString) {
					if ($c === '\\' && $j + 1 < $len) {
						// escaped character inside string — skip next char
						$j += 2;
						continue;
					}
					if ($c === $stringChar) {
						$inString = false;
					}
				} else {
					if ($c === '"' || $c === "'") {
						$inString = true;
						$stringChar = $c;
					} elseif ($c === '{') {
						$depth++;
					} elseif ($c === '}') {
						$depth--;
					}
				}
				$j++;
			}

			if ($depth === 0) {
				$tokens[] = [
					'text'  => substr($content, $i, $j - $i),
					'value' => substr($content, $i + 1, $j - $i - 2),
					'line'  => $startLine,
				];
			}

			$i = $j;
		}

		return $tokens;
	}

	private function processFunction(array $definition, FuncCall $node): array {
		$message = [];
		foreach ($definition as $type => $position) {
			if (!isset($node->args[$position - 1])) {
				return [];
			}
			$arg = $node->args[$position - 1]->value;
			if ($arg instanceof String_) {
				$message[$type] = $arg->value;
			} else {
				return [];
			}
		}
		return $message;
	}

	private function findMacroName(string $text, array $functions): ?string {
		foreach ($functions as $function) {
			if (strpos($text, '{'.$function) === 0) {
				return $function;
			}
		}
		return null;
	}

	private function trimMacroValue(string $name, string $value): string {
		if (strpos($name, '!') === 0) {
			// exclamation mark is never removed
			return trim(substr($value, strlen($name)));
		}

		if (strpos($name, '_') === 0) {
			// only underscore is removed
			$offset = strlen(ltrim($name, '_'));
			return substr($value, $offset);
		}

		return $value;
	}
}
