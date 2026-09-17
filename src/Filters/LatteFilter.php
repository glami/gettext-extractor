<?php
declare(strict_types=1);
/**
 * @copyright Copyright (c) 2009 Karel Klíma
 * @copyright Copyright (c) 2010 Ondřej Vodáček
 * @license New BSD License
 */

namespace Vodacek\GettextExtractor\Filters;

use Latte\CompileException;
use Latte\Compiler\Node;
use Latte\Compiler\Nodes\Php\ArgumentNode;
use Latte\Compiler\Nodes\Php\ExpressionNode;
use Latte\Compiler\Nodes\Php\Expression\FunctionCallNode;
use Latte\Compiler\Nodes\Php\Expression\MethodCallNode;
use Latte\Compiler\Nodes\Php\Expression\StaticMethodCallNode;
use Latte\Compiler\Nodes\Php\IdentifierNode;
use Latte\Compiler\Nodes\Php\NameNode;
use Latte\Compiler\Nodes\Php\Scalar\StringNode;
use Latte\Compiler\TagParser;
use Latte\Compiler\TemplateLexer;
use Latte\Compiler\Token;
use Nette\Utils\FileSystem;
use Throwable;
use Vodacek\GettextExtractor\Extractor;

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

		$lexer = new TemplateLexer();
		try {
			$generator = $lexer->tokenize(FileSystem::read($file));
		} catch (CompileException) {
			return [];
		}

		foreach ($generator as $token) {
			if ($token->type !== Token::Latte_TagOpen) {
				continue;
			}

			$line = $token->position->line;
			$tagOpenPosition = $token->position;

			// Switch lexer into tag mode so it emits PHP-kind tokens for the content
			$lexer->pushState(TemplateLexer::StateLatteTag);

			$nameToken = null;
			$phpTokens = [];

			while ($generator->valid()) {
				$tagToken = $generator->current();
				if ($tagToken->type === Token::Latte_TagClose || $tagToken->type === Token::End) {
					break; // do NOT advance — outer foreach will call next() with StatePlain restored
				}
				$generator->next();
				if ($tagToken->type === Token::Latte_Name) {
					$nameToken = $tagToken;
				} elseif ($tagToken->isPhpKind()) {
					$phpTokens[] = $tagToken;
				}
			}

			$lexer->popState();

			// Determine the actual function name for the tag-name path.
			// Latte 3's tag name regex only matches single `_` (not `_p`, `_n`, `_np`),
			// and cannot match `!`-prefixed names. We reconstruct the full name here.
			$name = $this->resolveTagFunctionName($nameToken, $phpTokens);

			if ($phpTokens === [] && $name === null) {
				continue;
			}

			// TagParser requires a Token::End sentinel at the end
			$endPosition = $phpTokens !== [] ? end($phpTokens)->position : $tagOpenPosition;
			$phpTokens[] = new Token(Token::End, '', $endPosition);

			try {
				$args = (new TagParser($phpTokens))->parseArguments();
			} catch (Throwable) {
				continue;
			}

			// Path 1: the tag itself is a registered translation call — e.g. {_'msg'},
			// {_p 'ctx', 'msg'}, {!_'msg'}. Args nodes are the call arguments directly.
			if ($name !== null && in_array($name, $functions, true)) {
				$values = [];
				foreach ($args->items as $item) {
					$values[] = $item->value;
				}
				foreach ($this->functions[$name] as $definition) {
					$message = $this->processCall($definition, $values);
					if ($message !== []) {
						$data[] = [Extractor::LINE => $line] + $message;
					}
				}
			}

			// Path 2: walk every nested expression for inline calls — picks up
			// {$translator->translate('msg')}, {tag attr => _('msg')},
			// {tag attr => $translator->translate('msg')}, etc.
			$this->collectFromNode($args, $functions, $line, $data);
		}

		return $data;
	}

	/**
	 * Determines the logical function name from the tag name token and PHP-kind token list.
	 *
	 * Latte 3's TemplateLexer only produces Latte_Name='_' for {_p...}, {_n...}, {_np...}
	 * and cannot produce a Latte_Name at all for {!_...}, {!custom...} tags.
	 *
	 * @param Token[] $phpTokens Modified in-place to strip consumed prefix tokens.
	 */
	private function resolveTagFunctionName(?Token $nameToken, array &$phpTokens): ?string {
		if ($nameToken !== null) {
			$name = $nameToken->text;

			// Detect _p / _n / _np: {_p'ctx','msg'} tokenizes as Latte_Name='_' + bare identifier 'p'
			if ($name === '_') {
				foreach ($phpTokens as $i => $phpToken) {
					if (trim($phpToken->text) === '') {
						continue; // skip whitespace
					}
					if (in_array($phpToken->text, ['p', 'n', 'np'], true)) {
						$name .= $phpToken->text;
						array_splice($phpTokens, $i, 1);
					}
					break;
				}
			}

			return $name;
		}

		// No Latte_Name token: detect {!_'msg'}, {!custom 'msg'}, {!_p'ctx','msg'} etc.
		// phpTokens starts with [Token('!'), Token(identifier), ...]
		if (count($phpTokens) >= 2 && $phpTokens[0]->text === '!') {
			$identifier = $phpTokens[1]->text;
			if (preg_match('/^[_a-z][_a-z0-9]*$/i', $identifier)) {
				$name = '!' . $identifier;
				array_splice($phpTokens, 0, 2);
				return $name;
			}
		}

		return null;
	}

	/**
	 * @param string[] $functions
	 * @param array<int, array<string, mixed>> $data
	 */
	private function collectFromNode(Node $node, array $functions, int $line, array &$data): void {
		$callName = null;
		$callArgs = null;
		if ($node instanceof FunctionCallNode && $node->name instanceof NameNode) {
			$callName = $node->name->name;
			$callArgs = $node->args;
		} elseif ($node instanceof MethodCallNode && $node->name instanceof IdentifierNode) {
			$callName = $node->name->name;
			$callArgs = $node->args;
		} elseif ($node instanceof StaticMethodCallNode && $node->name instanceof IdentifierNode) {
			$callName = $node->name->name;
			$callArgs = $node->args;
		}

		if ($callName !== null && in_array($callName, $functions, true)) {
			$values = [];
			foreach ($callArgs as $arg) {
				$values[] = $arg instanceof ArgumentNode ? $arg->value : null;
			}
			foreach ($this->functions[$callName] as $definition) {
				$message = $this->processCall($definition, $values);
				if ($message !== []) {
					$data[] = [Extractor::LINE => $line] + $message;
				}
			}
		}

		foreach ($node as $child) {
			if ($child instanceof Node) {
				$this->collectFromNode($child, $functions, $line, $data);
			}
		}
	}

	/**
	 * @param array<string, int> $definition
	 * @param array<int, ExpressionNode|null> $values
	 * @return array<string, string>
	 */
	private function processCall(array $definition, array $values): array {
		$message = [];
		foreach ($definition as $type => $position) {
			$value = $values[$position - 1] ?? null;
			if ($value instanceof StringNode) {
				$message[$type] = $value->value;
			} else {
				return [];
			}
		}
		return $message;
	}
}
