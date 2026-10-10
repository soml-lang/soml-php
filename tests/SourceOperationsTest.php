<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Decoder;
use Soml\Soml;
use Soml\SyntaxTree;

final class SourceOperationsTest extends TestCase
{
	#[Test]
	public function lastLineOffsetsStayInsideEveryAdjacentSpan(): void
	{
		$source = "# header\n\n/* first\n\tsecond\nthird */\n['one', 'two']\n";
		$tree = SyntaxTree::parse($source);

		for ($start = 0; $start <= strlen($source); $start++) {
			for ($end = $start; $end <= strlen($source); $end++) {
				$lineFeed = strrpos(substr($source, $start, $end - $start), "\n");
				$this->assertSame($lineFeed === false ? $start : $start + $lineFeed + 1, $tree->lastLineStart($start, $end));
			}
		}
	}

	#[Test]
	public function formattingCanDiscardKeysWithoutChangingSpansOrComments(): void
	{
		$source = '"\u{61}": 1' . "\n'literal': 2 /* last */\n404: 3";
		$full = SyntaxTree::parse($source);
		$spans = SyntaxTree::parse($source, collectKeys: false);
		$this->assertEquals($full->comments, $spans->comments);

		foreach ($full->body->children as $index => $member) {
			$this->assertSame('', $spans->body->children[$index]->key);
			$this->assertSame([$member->start, $member->keyEnd, $member->end], [$spans->body->children[$index]->start, $spans->body->children[$index]->keyEnd, $spans->body->children[$index]->end]);
		}

		$this->assertSame("a: 1\nliteral: 2\n404: 3\n", Soml::encode(Soml::decode(Soml::format($source))));
		$this->assertSame('"\u{61}": 4' . substr($source, strlen('"\u{61}": 1')), Soml::edit($source, ['a'], 4));
	}

	#[Test]
	public function escapedKeysKeepTheirSourceSpellingAndDecodedIdentity(): void
	{
		$source = "# before\n" . '"\u{61}\\\\\u{1f680}": 1' . "\n'next': 2";
		$tree = SyntaxTree::parse($source);
		$key = "a\\🚀";
		$this->assertSame($key, $tree->body->children[0]->key);
		$this->assertSame("# before\n" . '"\u{61}\\\\\u{1f680}": 3' . "\n'next': 2", Soml::edit($source, [$key], 3));
		$this->assertSame([$key => 1, 'next' => 2], Soml::decode(Soml::format($source), associative: true));
	}

	#[Test]
	public function commentLookupDistinguishesInteriorsFromBoundaries(): void
	{
		$source = "/* first\n continuation */ /* second */ a: 1 # tail";
		$tree = SyntaxTree::parse($source);

		foreach ($tree->comments as $comment) {
			$this->assertNull($tree->commentContaining($comment->start));
			$this->assertNull($tree->commentContaining($comment->end));
			$this->assertSame($comment, $tree->commentContaining($comment->start + 1));
			$this->assertSame($comment, $tree->commentContaining($comment->end - 1));
		}

		$this->assertNull($tree->commentContaining(strlen($source)));
	}

	/** @return iterable<string, array{string, string, string, bool}> */
	public static function tokens(): iterable
	{
		yield 'literal with delimiter syntax' => ["'[,]:{}#/*'", 'String', '[,]:{}#/*', false];
		yield 'escaped quotes' => ['"say\"hi\""', 'String', 'say"hi"', false];
		yield 'escaped trailing backslash' => ['"path\\\\"', 'String', 'path\\', false];
		yield 'unicode escape above BMP' => ['"\u{1f680}"', 'String', '🚀', false];
		yield 'NUL escape' => ['"\u{0}"', 'String', "\0", false];
		yield 'empty escaped string' => ['""', 'String', '', false];
		yield 'empty literal string' => ["''", 'String', '', false];
		yield 'literal block with longer quotes' => ["''''\n'''\n''''", 'String', "'''", true];
		yield 'escaped block with embedded line feed' => ["\"\"\"\nline\\ninside\n\"\"\"", 'String', "line\ninside", true];
		yield 'hexadecimal spelling' => ['0x0_FF', 'Scalar', '255', false];
		yield 'octal spelling' => ['0o7_55', 'Scalar', '493', false];
		yield 'binary spelling' => ['0b1_01', 'Scalar', '5', false];
		yield 'exponent with underscores' => ['1_2e-3', 'Scalar', '0.012', false];
		yield 'fraction with underscores' => ['1_000.5_0', 'Scalar', '1000.5', false];
		yield 'negative zero float' => ['-0.0', 'Scalar', '0.0', false];
		yield 'true keyword' => ['true', 'Scalar', 'true', false];
		yield 'false keyword' => ['false', 'Scalar', 'false', false];
		yield 'null keyword' => ['null', 'Scalar', 'null', false];
		yield 'positive infinity' => ['infinity', 'Scalar', 'infinity', false];
		yield 'negative infinity' => ['-infinity', 'Scalar', '-infinity', false];
		yield 'nanosecond duration' => ['0.0000000001h', 'Scalar', '0.00000036s', false];
		yield 'negative compound duration' => ['-1h2m3ms', 'Scalar', '-1h2m0.003s', false];
		yield 'instant with offset' => ['2026-09-19T16:00:00.050+02:00', 'Scalar', '2026-09-19T14:00:00.05Z', false];
		yield 'instant before epoch' => ['1969-12-31T23:59:59.999999999Z', 'Scalar', '1969-12-31T23:59:59.999999999Z', false];
	}

	#[Test]
	#[DataProvider('tokens')]
	public function sourceSpansPreserveTokensWhileValidationDiscardsValues(string $token, string $type, string $canonical, bool $block): void
	{
		$source = "# header\n'😀': {$token} /* tail */\nafter: 9";
		$tree = SyntaxTree::parse($source);
		$member = $tree->body->children[0];
		$value = $member->value;
		$this->assertNotNull($value);
		$this->assertSame($type, $value->type);
		$this->assertSame($block, $value->block);
		$this->assertSame($token, substr($source, $value->start, $value->end - $value->start));
		$this->assertSame('😀', $member->key);
		$this->assertSame("'😀'", substr($source, $member->start, $member->keyEnd - $member->start));
		$this->assertCount(2, $tree->comments);
		$this->assertSame("'😀': {$token} /* tail */\nafter: 10", substr(Soml::edit($source, ['after'], 10), strlen("# header\n")));

		$formatted = Soml::format($source);
		$this->assertSame($formatted, Soml::format($formatted));
		$expected = $type === 'String' ? $canonical : Decoder::decodeScalar($canonical);
		$decoded = Soml::decode($formatted);
		$this->assertInstanceOf(\stdClass::class, $decoded);
		if (\is_object($expected)) {
			$this->assertEquals($expected, $decoded->{'😀'});
		} else {
			$this->assertSame($expected, $decoded->{'😀'});
		}
	}

	/** @return iterable<string, array{string, string}> */
	public static function invalidDocuments(): iterable
	{
		yield 'duplicate after null' => ['a: null' . "\na: 1", 'Duplicate key a'];
		yield 'duplicate after discarded object' => ['{a: {nested: 1}, a: 2}', 'Duplicate key a'];
		yield 'duplicate after discarded array' => ['{a: [1, 2], a: 2}', 'Duplicate key a'];
		yield 'escaped duplicate of literal key' => ['{"\u{61}": 1, a: 2}', 'Duplicate key a'];
		yield 'numeric key duplicate' => ['{404: 1, "404": 2}', 'Duplicate key 404'];
		yield 'empty key duplicate' => ["{'': [], '': {}}", 'Duplicate key ""'];
		yield 'nested duplicate after null' => ['outer: {inner: null, inner: false}', 'Duplicate key inner'];
		yield 'invalid value before duplicate' => ['{a: 1, a: 01}', 'Leading zeros are not allowed'];
		yield 'duplicate before missing close' => ['{a: 1, a: 2', 'Duplicate key a'];
		yield 'invalid nested value' => ['a: [1, {b: nope}]', 'Unexpected “nope”'];
		yield 'invalid block indentation' => ["a: '''\n x\n  '''", 'This line does not start with the indentation'];
		yield 'invalid escaped block content' => ["a: \"\"\"\n\\u{d}\n\"\"\"", 'A carriage return'];
		yield 'nested block comment' => ['[/* outer /* inner */ 1]', 'Block comments cannot be nested'];
		yield 'comma after a line break' => ["[1\n, 2]", 'A comma must be on the same line'];
		yield 'comment line break is not a separator' => ["[1 /*\n*/ 2]", 'Expected “,”, a line break'];
		yield 'nonzero float underflow' => ['a: 1e-9999', 'is too small to be told apart from zero'];
		yield 'finite float overflow' => ['a: 1e9999', 'is too large to be a finite float'];
		yield 'duration overflow' => ['a: 9223372036854775808ns', 'outside the 64-bit range'];
		yield 'duration fraction below nanosecond' => ['a: 0.1ns', 'not a whole number of nanoseconds'];
		yield 'invalid instant offset' => ['a: 2026-01-01T00:00:00-00:00', 'offset unknown'];
		yield 'instant beyond UTC range' => ['a: 0001-01-01T00:00:00+00:01', 'in UTC it falls outside'];
		yield 'excessive depth' => [str_repeat('[', 101) . str_repeat(']', 101), 'nested more than 100 levels'];
		yield 'trailing source token' => ['{} {}', 'after the end of the document'];
		yield 'bare scalar document' => ['true', 'A bare value is not a document'];
	}

	#[Test]
	#[DataProvider('invalidDocuments')]
	public function validationKeepsTheDecodersErrorPrecedenceAndPositions(string $source, string $reason): void
	{
		$expected = null;
		try {
			Soml::decode($source, associative: true);
			$this->fail('An invalid document was decoded');
		} catch (DecodeException $exception) {
			$expected = $exception;
			$this->assertStringContainsString($reason, $exception->reason);
		}

		foreach ([Decoder::validate(...), Soml::format(...), static fn (string $text): string => Soml::edit($text, ['missing'])] as $run) {
			try {
				$result = $run($source);
				$this->fail('An invalid document was accepted');
			} catch (DecodeException $exception) {
				$this->assertSame($expected->reason, $exception->reason);
				$this->assertSame($expected->offset, $exception->offset);
				$this->assertSame($expected->lineNumber, $exception->lineNumber);
				$this->assertSame($expected->columnNumber, $exception->columnNumber);
				$this->assertSame($expected->codeFrame, $exception->codeFrame);
			}
		}
	}

	/** @return iterable<string, array{string, int, int, bool, bool}> */
	public static function spans(): iterable
	{
		yield 'empty' => ['[]', 0, 0, true, false];
		yield 'at EOF' => ['[]', 2, 2, true, false];
		yield 'LF at start' => ["\n[]", 0, 1, false, false];
		yield 'LF immediately outside end' => ["[]\n", 0, 2, true, false];
		yield 'LF immediately before start' => ["\n[]", 1, 3, true, false];
		yield 'blank LF pair' => ["\n\n[]", 0, 2, false, true];
		yield 'blank line with spaces' => ["\n  \n[]", 0, 4, false, true];
		yield 'blank line with tabs' => ["\n\t\t\n[]", 0, 4, false, true];
		yield 'blank closing LF outside end' => ["\n \n[]", 0, 2, false, false];
		yield 'blank opening LF outside start' => ["\n \n[]", 1, 3, false, false];
		yield 'nonblank line between feeds' => ["# a\n# b\n[]", 3, 8, false, false];
		yield 'Unicode whitespace is content' => ["#\n#\u{A0}\n[]", 0, 7, false, false];
		yield 'long span without LF' => ['[' . str_repeat(' ', 131_072) . ']', 1, 131_073, true, false];
		yield 'LF at old chunk boundary' => [str_repeat(' ', 65_536) . "\n[]", 0, 65_537, false, false];
		yield 'long blank line' => ["\n" . str_repeat(' ', 131_072) . "\n[]", 0, 131_074, false, true];
		yield 'blank line after nonblank comment' => ["# first\n# second\n\t\n[]", 0, 19, false, true];
	}

	#[Test]
	#[DataProvider('spans')]
	public function layoutScansStayInsideTheirSourceSpan(string $source, int $start, int $end, bool $sameLine, bool $blankLine): void
	{
		$tree = SyntaxTree::parse($source);
		$this->assertSame($sameLine, $tree->isSameLine($start, $end));
		$this->assertSame($blankLine, $tree->hasBlankLine($start, $end));
	}
}
