<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Duration;
use Soml\EncodeException;
use Soml\Instant;
use Soml\Soml;

final class EditorTest extends TestCase
{
	#[Test]
	public function removingTheFirstMemberPreservesTheIndentationAfterManyBlankLines(): void
	{
		$blankLine = " \t\n";
		$source = "first: 1\n" . str_repeat($blankLine, 100_000) . "\tsecond: 2\n";
		$this->assertSame(str_repeat($blankLine, 99_999) . "\tsecond: 2\n", Soml::edit($source, ['first']));
	}

	#[Test]
	public function blankLineScanningStopsBeforeCommentsAndKeepsUnterminatedWhitespace(): void
	{
		foreach (["\t# retained\n\tsecond: 2\n", "\t/* retained */ second: 2", "\tsecond: 2\n\t  ", "\tsecond: 2"] as $suffix) {
			$source = "first: 1\n \t\n\n" . $suffix;
			$this->assertSame("\n" . $suffix, Soml::edit($source, ['first']));
		}
	}

	#[Test]
	public function nestedCommentContinuationsKeepTheOriginalIndentation(): void
	{
		$source = "root: {\n\t/* first\nend */ /* second\nend */ values: [1]\n}";
		$edited = Soml::edit($source, ['root', 'values', 1], [2, 3]);
		$this->assertSame("root: {\n\t/* first\nend */ /* second\nend */ values: [1, [2, 3]]\n}", $edited);
	}

	#[Test]
	public function removingAMissingNestedMemberPreservesTheWholeSource(): void
	{
		$source = "root: {present: [1, 2]} # retained\n";
		$this->assertSame($source, Soml::edit($source, ['root', 'missing', 'child']));
	}

	#[Test]
	public function replacingAnIdenticalValuePreservesTheMissingFinalNewline(): void
	{
		$this->assertSame('a: [1]', Soml::edit('a: [1]', ['a', 0], 1));
	}

	#[Test]
	public function anOutOfRangeArrayRemovalIsANoOp(): void
	{
		$source = '[1,  2,] # retained';
		$this->assertSame($source, Soml::edit($source, [\PHP_INT_MAX]));
	}

	#[Test]
	public function successiveArrayRemovalsAndAppendsKeepIndexesConsistent(): void
	{
		$source = '[1, 2, 3]';
		$source = Soml::edit($source, [1]);
		$source = Soml::edit($source, [2], 4);
		$source = Soml::edit($source, [0]);
		$this->assertSame([3, 4], Soml::decode($source));
		$source = Soml::edit($source, [1]);
		$source = Soml::edit($source, [0]);
		$this->assertSame('[]', $source);
	}

	#[Test]
	public function appendingAfterATrailingCommaRetainsItsComment(): void
	{
		$source = Soml::edit('[1, /* retained */]', [1], 2);
		$this->assertSame([1, 2], Soml::decode($source));
		$this->assertSame(1, substr_count($source, '/* retained */'));
		$this->assertSame('[1, 2, /* retained */]', $source);
		$this->assertSame("[1, 2 /* retained */]\n", Soml::format($source));
	}

	#[Test]
	public function creatingMissingObjectsSupportsNulAndEmptyKeysTogether(): void
	{
		$source = Soml::edit('{}', ['outer', "\0key", ''], 42);
		$this->assertSame(['outer' => ["\0key" => ['' => 42]]], Soml::decode($source, associative: true));
		$this->assertSame($source . "\n", Soml::format($source));
	}

	#[Test]
	public function numericObjectKeysUseStringPaths(): void
	{
		$source = Soml::edit("'': 0\n0: 1\n", ['0'], 2);
		$source = Soml::edit($source, [''], 3);
		$this->assertSame(['' => 3, 0 => 2], Soml::decode($source, associative: true));
		$this->expectException(\InvalidArgumentException::class);
		$result = Soml::edit($source, [0], 4);
	}

	#[Test]
	public function insertingAfterAnEndOfFileCommentStartsANewLine(): void
	{
		$source = Soml::edit('a: 0x10 # retained', ['b'], true);
		$this->assertSame("a: 0x10 # retained\nb: true", $source);
	}

	#[Test]
	public function aReplacementCanUseExactlyTheRemainingDepth(): void
	{
		$source = str_repeat('[', 98) . '0' . str_repeat(']', 98);
		$edited = Soml::edit($source, array_fill(0, 98, 0), [[]]);
		$this->assertSame(str_repeat('[', 100) . str_repeat(']', 100), $edited);
		$this->assertSame($edited . "\n", Soml::format($edited));
	}

	#[Test]
	public function circularReplacementsReportTheDestinationAndCycle(): void
	{
		$value = new \stdClass();
		$value->self = $value;

		try {
			$result = Soml::edit('pool: {}', ['pool', 'item'], $value);
			$this->fail('A circular replacement was accepted');
		} catch (EncodeException $exception) {
			$this->assertSame(['pool', 'item', 'self'], $exception->path);
			$this->assertStringContainsString('circular structure', $exception->reason);
		}
	}

	#[Test]
	public function anArrayCannotBeExtendedWithAGap(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('A new array item must use the index at the end of the array');
		$result = Soml::edit('[1]', [2], 3);
	}

	#[Test]
	public function pathsCannotDescendThroughScalars(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The path leads through a value that is not an object or an array');
		$result = Soml::edit('a: null', ['a', 'child'], 1);
	}

	#[Test]
	public function successiveEditsKeepLiteralAndEscapedKeysDistinct(): void
	{
		$source = <<<'SOML'
			# Keep the spellings and unrelated values.
			'path\n': {"path\n": [1, 2]} # Keep the comment.
			untouched: 0x10
			SOML;
		$edited = Soml::edit($source, ['path\n', "path\n", 0], 3);
		$edited = Soml::edit($edited, ['path\n', "path\n", 2], (object) ['nested' => [true, null]]);
		$edited = Soml::edit($edited, ['path\n', "path\n", 1]);

		$this->assertSame(<<<'SOML'
			# Keep the spellings and unrelated values.
			'path\n': {"path\n": [3, {nested: [true, null]}]} # Keep the comment.
			untouched: 0x10
			SOML, $edited);
		$this->assertSame(['path\n' => ["path\n" => [3, ['nested' => [true, null]]]], 'untouched' => 16], Soml::decode($edited, associative: true));
	}

	#[Test]
	public function sourceOperationsSupportKeysStartingWithNul(): void
	{
		$source = '"\u{0}key": 1' . "\n";
		$this->assertSame($source, Soml::format($source));
		$this->assertSame('"\u{0}key": 2' . "\n", Soml::edit($source, ["\0key"], 2));
		$this->assertSame("{\n\t\"\\u{0}key\": 1\n}", Soml::edit('{}', ["\0key"], 1));
		$this->assertSame("{}\n", Soml::edit($source, ["\0key"]));
		$this->assertSame("outer: {\n\t\"\\u{0}key\": 1\n}\n", Soml::edit("outer: {}\n", ['outer', "\0key"], 1));
	}

	#[Test]
	public function invalidUtf8PathKeyIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$result = Soml::edit('{}', ["\xFF"], 1);
	}

	#[Test]
	public function stackTracesDoNotShowDocumentKeysOrReplacementValues(): void
	{
		$ignoreArguments = (string) ini_get('zend.exception_ignore_args');
		ini_set('zend.exception_ignore_args', '0');

		try {
			foreach (['existing', 'missing'] as $key) {
				try {
					$result = Soml::edit("existing: 'document-secret'\n'source-secret-key': 2\n", [$key], "replacement-secret\r");
					$this->fail('An unrepresentable value was accepted');
				} catch (EncodeException $exception) {
					foreach ($exception->getTrace() as $frame) {
						if (str_starts_with($frame['file'] ?? '', \dirname(__DIR__) . '/src/')) {
							$this->assertNotEmpty($frame['args'] ?? []);
							$this->assertStringNotContainsString('-secret', print_r($frame['args'], true));
						}
					}
				}
			}
		} finally {
			ini_set('zend.exception_ignore_args', $ignoreArguments);
		}
	}

	#[Test]
	public function replacementMustFitTheRemainingNestingDepth(): void
	{
		$source = str_repeat('[', 100) . '0' . str_repeat(']', 100);
		$this->expectException(EncodeException::class);
		$result = Soml::edit($source, array_fill(0, 100, 0), []);
	}

	#[Test]
	public function encodingErrorsIncludeTheDocumentDestination(): void
	{
		foreach ([
			['outer: 0', ['outer'], \NAN, ['outer']],
			['{}', ['outer'], \NAN, ['outer']],
			['outer: {inner: 0}', ['outer', 'inner'], (object) ['bad' => \NAN], ['outer', 'inner', 'bad']],
			['outer: {}', ['outer', 'inner'], (object) ['bad' => \NAN], ['outer', 'inner', 'bad']],
			['{}', ['outer', 'inner'], (object) ['bad' => \NAN], ['outer', 'inner', 'bad']],
			['[{inner: 0}]', [0, 'inner'], \NAN, [0, 'inner']],
			['[]', [0], [1, \NAN], [0, 1]],
			['[]', [0, 'inner'], [1, \NAN], [0, 'inner', 1]],
			['outer: [0]', ['outer', 0], (object) ['bad' => \NAN], ['outer', 0, 'bad']],
		] as [$source, $path, $replacement, $expectedPath]) {
			try {
				$result = Soml::edit($source, $path, $replacement);
				$this->fail('An unrepresentable replacement was accepted');
			} catch (EncodeException $exception) {
				$this->assertSame($expectedPath, $exception->path);
			}
		}
	}

	#[Test]
	public function nestedReplacementStaysOnOneLine(): void
	{
		$this->assertSame('values: [1, {a: [2, 3]}]', Soml::edit('values: [1]', ['values', 1], (object) ['a' => [2, 3]]));
	}

	/** @return array<string, array{string, list<string|int>, mixed, string}> */
	public static function continuationEdits(): array
	{
		return [
			'insert into empty array' => ["a: /* first\nsecond */ []\n", ['a', 0], 1, "a: /* first\nsecond */ [\n\t\t1\n\t]\n"],
			'insert into empty object' => ["a: /* first\nsecond */ {}\n", ['a', 'b'], 1, "a: /* first\nsecond */ {\n\t\tb: 1\n\t}\n"],
			'replace value with array' => ["a: /* first\nsecond */ 0\n", ['a'], [1], "a: /* first\nsecond */ [\n\t\t1\n\t]\n"],
			'create nested object' => ["a: /* first\nsecond */ {}\n", ['a', 'b', 'c'], 1, "a: /* first\nsecond */ {\n\t\tb: {\n\t\t\tc: 1\n\t\t}\n\t}\n"],
			'create object inside array' => ["a: /* first\nsecond */ []\n", ['a', 0, 'b'], 1, "a: /* first\nsecond */ [\n\t\t{\n\t\t\tb: 1\n\t\t}\n\t]\n"],
		];
	}

	/** @param list<string|int> $path */
	#[Test]
	#[DataProvider('continuationEdits')]
	public function editsAfterMultilineKeyCommentsRemainFormatted(string $source, array $path, mixed $value, string $expected): void
	{
		$this->assertSame($source, Soml::format($source));
		$edited = Soml::edit($source, $path, $value);
		$this->assertSame($expected, $edited);
		$this->assertSame($edited, Soml::format($edited));
	}

	#[Test]
	public function editsPreserveIndentationOfUnformattedOwnLineValues(): void
	{
		$prefix = "a: /* first\nsecond */\n  ";
		$this->assertSame($prefix . "[\n  \t1\n  ]\n", Soml::edit($prefix . "[]\n", ['a', 0], 1));
		$this->assertSame($prefix . "[\n  \t1\n  ]\n", Soml::edit($prefix . "0\n", ['a'], [1]));
	}

	#[Test]
	public function omittedValueRemovesWhileExplicitNullSetsNull(): void
	{
		$source = "a: 1\nb: 2\n";
		$this->assertSame("b: 2\n", Soml::edit($source, ['a']));
		$this->assertSame("a: null\nb: 2\n", Soml::edit($source, ['a'], null));
	}

	#[Test]
	public function missingRemovalReturnsTheOriginalDocument(): void
	{
		$source = "a:  1  # keep layout\n";
		$this->assertSame($source, Soml::edit($source, ['missing']));
	}

	/**
	@return array<string, array{mixed, string}>
	*/
	public static function scalarValues(): array
	{
		return [
			'duration' => [new Duration(1_500_000_000), '1.5s'],
			'instant' => [Instant::parse('2026-09-19T14:00:00.123456789Z'), '2026-09-19T14:00:00.123456789Z'],
			'float' => [1.0, '1.0'],
			'negative zero' => [-0.0, '0.0'],
			'infinity' => [\INF, 'infinity'],
			'negative infinity' => [-\INF, '-infinity'],
			'maximum integer' => [\PHP_INT_MAX, '9223372036854775807'],
			'minimum integer' => [\PHP_INT_MIN, '-9223372036854775808'],
		];
	}

	#[Test]
	#[DataProvider('scalarValues')]
	public function replacementPreservesScalarTypes(mixed $value, string $expected): void
	{
		$this->assertSame("value: {$expected}\n", Soml::edit("value: 0\n", ['value'], $value));
	}

	#[Test]
	public function emptyPathIsRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$result = Soml::edit("a: 1\n", [], 2);
	}

	#[Test]
	public function invalidDocumentIsRejectedEvenForAMissingRemoval(): void
	{
		$this->expectException(DecodeException::class);
		$result = Soml::edit('a: [', ['missing']);
	}

	#[Test]
	public function unsupportedReplacementIsRejected(): void
	{
		$this->expectException(EncodeException::class);
		$result = Soml::edit("a: 1\n", ['a'], \NAN);
	}
}
