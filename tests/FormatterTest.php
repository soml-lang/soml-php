<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\Soml;

final class FormatterTest extends TestCase
{
	#[Test]
	public function blockCommentsTrimLineEndsWithoutChangingIndentationOrUnicodeSpaces(): void
	{
		$source = "/* header \t\n\tfirst \t\n \t\n\n\tsecond\u{A0}\t \n*/\na: 1";
		$this->assertSame("/* header\n\tfirst\n\n\tsecond\u{A0}\n*/\na: 1\n", Soml::format($source));
	}

	#[Test]
	public function indentedBlockCommentsDoNotDependOnTheBacktrackingLimit(): void
	{
		$previousLimit = (string) ini_get('pcre.backtrack_limit');
		$indentation = str_repeat(' ', 100_000);
		$source = "/*\n{$indentation}text \t\n*/\na: 1";

		try {
			ini_set('pcre.backtrack_limit', '1000');
			$this->assertSame("/*\n{$indentation}text\n*/\na: 1\n", Soml::format($source));
		} finally {
			ini_set('pcre.backtrack_limit', $previousLimit);
		}
	}

	#[Test]
	public function literalBlockStringsKeepRelativeIndentationAndBlankContent(): void
	{
		$source = "a: '''\n  first\n    second\n  \n  third\n  '''\n";
		$formatted = Soml::format($source);
		$this->assertSame("first\n  second\n\nthird", Soml::decode($formatted, associative: true)['a']);
		$this->assertSame($formatted, Soml::format($formatted));
	}

	#[Test]
	public function longerBlockDelimitersPreserveShorterQuoteRunsAsContent(): void
	{
		$source = "a: ''''\n'''\n''''\n";
		$formatted = Soml::format($source);
		$this->assertSame("'''", Soml::decode($formatted, associative: true)['a']);
		$this->assertStringContainsString("\t''''\n\t'''\n\t''''", $formatted);
		$this->assertSame($formatted, Soml::format($formatted));
	}

	#[Test]
	public function escapedBlockStringsKeepEscapesAndCommentSyntaxAsContent(): void
	{
		$source = <<<'SOML'
			a: """
			  say\t"hi" # /* not comment */
			  line\\tail\ninside
			  """
			SOML;
		$formatted = Soml::format($source);
		$this->assertSame("say\t\"hi\" # /* not comment */\nline\\tail\ninside", Soml::decode($formatted, associative: true)['a']);
		$this->assertStringContainsString('line\\\\tail\\ninside', $formatted);
		$this->assertSame($formatted, Soml::format($formatted));
	}

	#[Test]
	public function emptyContainersRetainTheirOwnComments(): void
	{
		$source = '{a:{/* object */},b:[/* array */]}';
		$formatted = Soml::format($source);
		$this->assertSame("{a: {/* object */}, b: [/* array */]}\n", $formatted);
		$this->assertEquals((object) ['a' => new \stdClass(), 'b' => []], Soml::decode($formatted));
	}

	#[Test]
	public function formattingPreservesNoncanonicalNumbersAndTemporalSpellings(): void
	{
		$source = '{a:[0x00FF,1_000.0,0.0000000001h],b:2026-09-19T16:00:00.500+02:00}';
		$formatted = Soml::format($source);
		$this->assertSame("{a: [0x00FF, 1_000.0, 0.0000000001h], b: 2026-09-19T16:00:00.500+02:00}\n", $formatted);
		$this->assertEquals(Soml::decode($source), Soml::decode($formatted));
	}

	#[Test]
	public function commentMarkersInUnicodeKeysDoNotBecomeComments(): void
	{
		$source = "{'é😀#/*':1,other:2} /* real */";
		$formatted = Soml::format($source);
		$this->assertSame("{'é😀#/*': 1, other: 2} /* real */\n", $formatted);
		$this->assertSame(1, Soml::decode($formatted, associative: true)['é😀#/*']);
		$this->assertSame($formatted, Soml::format($formatted));
	}

	#[Test]
	public function lineBreaksAtScanBoundariesKeepContainersMultiline(): void
	{
		foreach ([65_535, 65_536, 65_537, 131_072] as $lineFeedOffset) {
			$source = '[' . str_repeat(' ', $lineFeedOffset - 2) . "1\n]";
			$this->assertSame("[\n\t1\n]\n", Soml::format($source));
		}

		$this->assertSame("[1]\n", Soml::format('[' . str_repeat(' ', 131_072) . "1]\n"), 'A line feed after a container must not affect its layout');
	}

	#[Test]
	public function commasInsideCommentsCannotSeparateValues(): void
	{
		$source = "[1 /* before, */ , /* after, */ 2, # line,\n3 /* trailing, */ ,]\n";
		$formatted = "[\n\t1 /* before, */\n\t/* after, */ 2 # line,\n\t3 /* trailing, */\n]\n";
		$this->assertSame($formatted, Soml::format($source));
		$this->assertSame([1, 2, 3], Soml::decode($formatted));
		$this->assertSame($formatted, Soml::format($formatted));
	}

	/**
	@return iterable<string, array{string, string}>
	*/
	public static function quotedTokens(): iterable
	{
		foreach ([
			'empty' => '',
			'trailing backslash' => "path\\",
			'quote' => 'a"b',
			'backslash before quote' => "a\\\"b",
			'backslash run' => str_repeat('\\', 64),
			'syntax inside string' => '/* comment */ # [,]:{}',
			'multibyte' => 'é😀',
			'long string' => str_repeat('text \\ " 😀 ', 4096),
		] as $name => $value) {
			yield "literal {$name}" => ["'{$value}'", $value];
			yield "escaped {$name}" => [json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), $value];
		}

		yield 'escaped delimiters and whitespace' => ['"\u{22}\u{5c}\n\t"', "\"\\\n\t"];
	}

	#[Test]
	#[DataProvider('quotedTokens')]
	public function quotedKeysAndValuesKeepTheirBoundaries(string $token, string $value): void
	{
		$source = "{{$token}:{$token},after: 1}";
		$formatted = Soml::format($source);
		$this->assertSame("{{$token}: {$token}, after: 1}\n", $formatted);
		$this->assertSame($formatted, Soml::format($formatted));
		$this->assertSame($value, Soml::decode($formatted)->{$value});

		$edited = Soml::decode(Soml::edit($source, [$value], 42));
		$this->assertInstanceOf(\stdClass::class, $edited);
		$this->assertSame(42, $edited->{$value});
		$this->assertSame(1, $edited->after);
	}

	/**
	@return array<string, array{string, string}>
	*/
	public static function valuesAfterMultilineComments(): array
	{
		return [
			'array' => ["x: /* c\n */ [1,\n2]", "x: /* c\n */ [\n\t\t1\n\t\t2\n\t]\n"],
			'object' => ["x: /* c\n */ {a: 1,\nb: 2}", "x: /* c\n */ {\n\t\ta: 1\n\t\tb: 2\n\t}\n"],
			'nested container' => ["{x: /* c\n */ [[1,\n2]]}", "{\n\tx: /* c\n */ [\n\t\t\t[\n\t\t\t\t1\n\t\t\t\t2\n\t\t\t]\n\t\t]\n}\n"],
			'block string in continuation' => ["x: /* c\n */ {a: '''\ntext\n'''}", "x: /* c\n */ {\n\t\ta:\n\t\t\t'''\n\t\t\ttext\n\t\t\t'''\n\t}\n"],
		];
	}

	#[Test]
	#[DataProvider('valuesAfterMultilineComments')]
	public function multilineCommentsBeforeValuesPreserveContinuationIndentation(string $source, string $expected): void
	{
		$formatted = Soml::format($source);
		$this->assertSame($expected, $formatted);
		$this->assertSame($formatted, Soml::format($formatted));
		$this->assertSame(Soml::encode(Soml::decode($source), canonical: true), Soml::encode(Soml::decode($formatted), canonical: true));
	}
}
