<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Decoder;
use Soml\Duration;
use Soml\Instant;
use Soml\Soml;

final class DecodeTest extends TestCase
{
	#[Test]
	public function blockIndentationErrorsTakePrecedenceOverEarlierInvalidEscapes(): void
	{
		$source = "a: \"\"\"\n\t\\u{\nmissing-indent\n\t\"\"\"";
		$exception = $this->decodeError($source);
		$this->assertStringStartsWith('This line does not start with the indentation', $exception->reason);
		$this->assertSame([3, 1], [$exception->lineNumber, $exception->columnNumber]);
	}

	#[Test]
	public function blockBlankTrimmingDoesNotTrimEscapedWhitespace(): void
	{
		$source = "a: \"\"\"\n \n\t\\n\\t\n\t \t\n\tend\\n\n \n\t\"\"\"";
		$this->assertSame(['a' => "\n\t\n\nend\n"], Soml::decode($source, associative: true));
		$this->assertSame(['a' => "\n\t\n\nend\n"], Soml::decode(Soml::format($source), associative: true));
	}

	#[Test]
	public function exponentNumbersNormalizeUnderscoresBeforeConversion(): void
	{
		$this->assertSame(['a' => 1e-12, 'b' => 1e12], Soml::decode("a: 1e-1_2\nb: 1e1_2", associative: true));
		$exception = $this->decodeError('a: 1e-1_0404');
		$this->assertStringContainsString('too small to be told apart from zero', $exception->reason);
	}

	#[Test]
	public function controlBytePositionsCountUnicodeColumnsAndByteOffsetsSeparately(): void
	{
		$prefix = "# é😀\n'a': 'é😀";
		$exception = $this->decodeError($prefix . "\x7F'\n");
		$this->assertSame(strlen($prefix), $exception->offset);
		$this->assertSame([2, 9], [$exception->lineNumber, $exception->columnNumber]);
		$this->assertStringContainsString('U+007F', $exception->reason);
		$this->assertStringNotContainsString("\x7F", $exception->codeFrame);
	}

	#[Test]
	public function truncatedUtf8AfterMixedCharacterWidthsKeepsItsExactPosition(): void
	{
		$prefix = "# é€😀\nkey: '" . str_repeat('a', 10_000) . 'é€😀';
		$exception = $this->decodeError($prefix . "\xE2\x82");
		$this->assertSame('Incomplete UTF-8 sequence at the end of the input', $exception->reason);
		$this->assertSame(strlen($prefix), $exception->offset);
		$this->assertSame([2, 10_010], [$exception->lineNumber, $exception->columnNumber]);
		$this->assertSame(1, preg_match('//u', $exception->codeFrame));
	}

	#[Test]
	public function duplicateDetectionUsesDecodedUnicodeKeysEvenForNullValues(): void
	{
		$prefix = "'é😀': null\n";
		$exception = $this->decodeError($prefix . '"\u{e9}\u{1f600}": 1');
		$this->assertStringStartsWith('Duplicate key ', $exception->reason);
		$this->assertStringContainsString('é😀', $exception->reason);
		$this->assertSame(strlen($prefix), $exception->offset);
		$this->assertSame([2, 1], [$exception->lineNumber, $exception->columnNumber]);
	}

	#[Test]
	public function successfulFileReadsRestoreThePreviousErrorHandler(): void
	{
		$this->assertPreservesErrorHandler(function (): void {
			$this->assertInstanceOf(\stdClass::class, Soml::decodeFile(__DIR__ . '/../bench/fixtures/service.soml'));
		});
	}

	#[Test]
	public function failedFileReadsRestoreThePreviousErrorHandler(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->assertPreservesErrorHandler(static function (): void {
			$value = Soml::decodeFile('/nonexistent/soml-handler-test.soml');
		});
	}

	#[Test]
	public function invalidFilePathsRestoreThePreviousErrorHandler(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->assertPreservesErrorHandler(static function (): void {
			$value = Soml::decodeFile("invalid\0path");
		});
	}

	/**
	Checks that a file operation restores the caller's handler, including when it throws.

	@param \Closure(): void $operation
	*/
	private function assertPreservesErrorHandler(\Closure $operation): void
	{
		$handler = static fn (): bool => true;
		set_error_handler($handler);

		try {
			$operation();
		} finally {
			$restored = set_error_handler($handler);
			restore_error_handler();
			restore_error_handler();
			$this->assertSame($handler, $restored);
		}
	}

	#[Test]
	public function objectsAreStdClassByDefault(): void
	{
		$value = Soml::decode("name: 'api'\npool: {min: 2}\nempty: {}\nlist: []\n404: 'not found'\n'': 'empty'");

		$this->assertInstanceOf(\stdClass::class, $value);
		$this->assertSame('api', $value->name);
		$this->assertInstanceOf(\stdClass::class, $value->pool);
		$this->assertSame(2, $value->pool->min);
		$this->assertEquals(new \stdClass(), $value->empty);
		$this->assertSame([], $value->list);
		$this->assertSame('not found', $value->{'404'});
		$this->assertSame('empty', $value->{''});
		$this->assertSame(['name', 'pool', 'empty', 'list', '404', ''], array_map(strval(...), array_keys(get_object_vars($value))));
	}

	#[Test]
	public function associativeGivesArrays(): void
	{
		$value = Soml::decode("name: 'api'\npool: {min: 2}\nempty: {}\n404: 'not found'", associative: true);

		$this->assertSame(['name' => 'api', 'pool' => ['min' => 2], 'empty' => [], 404 => 'not found'], $value);
	}

	#[Test]
	public function topLevelArray(): void
	{
		$this->assertSame([1, 'two', true, null], Soml::decode("[1, 'two', true, null]"));
		$this->assertEquals([new \stdClass()], Soml::decode('[{}]'));
		$this->assertSame([[]], Soml::decode('[{}]', associative: true));
	}

	#[Test]
	public function aKeyThatStartsWithNulNeedsAssociative(): void
	{
		$this->assertSame(["\0a" => 1], Soml::decode('"\u{0}a": 1', associative: true));

		$exception = $this->decodeError('"\u{0}a": 1');
		$this->assertSame('A key that starts with U+0000 cannot be the name of a property. Decode with `associative: true` to read it into an array', $exception->reason);
		$this->assertSame(1, $exception->columnNumber);
	}

	#[Test]
	public function integers(): void
	{
		$value = Soml::decode('[0, -1, 9223372036854775807, -9223372036854775808, 0x7FFFFFFFFFFFFFFF, 0o777, 0b1010, 1_000_000, 0x00FF, 123456789012345678]');

		$this->assertSame([0, -1, \PHP_INT_MAX, \PHP_INT_MIN, \PHP_INT_MAX, 0o777, 0b1010, 1_000_000, 255, 123456789012345678], $value);
	}

	#[Test]
	public function integersOutsideTheRange(): void
	{
		foreach (['9223372036854775808', '-9223372036854775809', '0x8000000000000000', '0o1000000000000000000000', '99999999999999999999999'] as $text) {
			$this->assertSame("The integer {$text} is outside the 64-bit range (-9223372036854775808 to 9223372036854775807)", $this->decodeError("a: {$text}")->reason, $text);
		}
	}

	#[Test]
	public function floats(): void
	{
		$value = Soml::decode('[1.5, -0.0, 0.0, 1e300, 1.7976931348623157e308, 5e-324, infinity, -infinity, 1_000.5, 3.0, 1e0]');

		$this->assertSame([1.5, 0.0, 0.0, 1e300, 1.7976931348623157e308, 5e-324, \INF, -\INF, 1000.5, 3.0, 1.0], $value);
		$this->assertSame('0', (string) (fdiv(1, $value[1]) > 0 ? 0 : 1), 'Negative zero decodes as positive zero');
		$this->assertSame('1e999 is too large to be a finite float. Use infinity if you mean it', $this->decodeError('a: 1e999')->reason);
		$this->assertSame('-1e999 is too large to be a finite float. Use -infinity if you mean it', $this->decodeError('a: -1e999')->reason);
		$this->assertSame('1e-400 is too small to be told apart from zero. Write 0.0 if you mean zero', $this->decodeError('a: 1e-400')->reason);
	}

	#[Test]
	public function intAndFloatStayDifferent(): void
	{
		$value = Soml::decode("a: 3\nb: 3.0");
		$this->assertInstanceOf(\stdClass::class, $value);

		$this->assertSame(3, $value->a);
		$this->assertSame(3.0, $value->b);
	}

	#[Test]
	public function instants(): void
	{
		$value = Soml::decode('[2026-09-19T14:00:00Z, 2026-09-19T16:00:00.123456789+02:00, 0001-01-01T00:00:00Z, 9999-12-31T23:59:59.999999999Z, 1969-12-31T23:59:59.5Z]');

		$this->assertEquals([
			new Instant(1_789_826_400),
			new Instant(1_789_826_400, 123_456_789),
			new Instant(Instant::MIN_EPOCH_SECONDS),
			new Instant(Instant::MAX_EPOCH_SECONDS, 999_999_999),
			new Instant(-1, 500_000_000),
		], $value);
	}

	#[Test]
	public function durations(): void
	{
		$value = Soml::decode('[1h30m, 1.5h, -5m, 1ns, 9223372036854775807ns, -9223372036854775808ns, 0s, 0.0000000001h, 2562047h47m16.854775807s]');

		$this->assertEquals([
			new Duration(90 * Duration::MINUTE),
			new Duration(90 * Duration::MINUTE),
			new Duration(-5 * Duration::MINUTE),
			new Duration(1),
			new Duration(\PHP_INT_MAX),
			new Duration(\PHP_INT_MIN),
			new Duration(0),
			new Duration(360),
			new Duration(\PHP_INT_MAX),
		], $value);
	}

	#[Test]
	public function durationsOutsideTheRange(): void
	{
		foreach (['9223372036854775808ns', '-9223372036854775809ns', '2562048h', '2562047h47m16.854775808s', '99999999999999999999h1s', '9223372036854775807ns1h'] as $text) {
			$exception = $this->decodeError("a: {$text}");
			$this->assertStringStartsWith("Invalid duration {$text}: ", $exception->reason, $text);
		}

		$this->assertSame('Invalid duration 2562048h: it is outside the 64-bit range of nanoseconds, about 292 years either way', $this->decodeError('a: 2562048h')->reason);
		$this->assertSame('Invalid duration 1.0000000001s: it is not a whole number of nanoseconds', $this->decodeError('a: 1.0000000001s')->reason);
		$this->assertSame('Invalid duration -0s: “-” is not allowed before zero, because zero has one spelling: 0s', $this->decodeError('a: -0s')->reason);
	}

	#[Test]
	public function strings(): void
	{
		$value = Soml::decode(<<<'SOML'
			literal: 'C:\Users\n'
			escaped: "tab\there\nline \"quoted\" \\ \u{1f600} \u{0}"
			block: '''
				first
					indented

				last
				'''
			escapedBlock: """
				a\tb
				"""
			unicode: 'æøå 😀 ​'
			SOML);

		$this->assertInstanceOf(\stdClass::class, $value);
		$this->assertSame('C:\Users\n', $value->literal);
		$this->assertSame("tab\there\nline \"quoted\" \\ 😀 \0", $value->escaped);
		$this->assertSame("first\n\tindented\n\nlast", $value->block);
		$this->assertSame("a\tb", $value->escapedBlock);
		$this->assertSame("æøå 😀 \u{200B}", $value->unicode);
	}

	#[Test]
	public function exceptionProperties(): void
	{
		$exception = $this->decodeError("name: 'api'\nreplicas: three");

		$this->assertSame('Unexpected “three”. A string value must be quoted, as in \'three\'', $exception->reason);
		$this->assertSame('Unexpected “three”. A string value must be quoted, as in \'three\' at line 2, column 11', $exception->getMessage());
		$this->assertSame(2, $exception->lineNumber);
		$this->assertSame(11, $exception->columnNumber);
		$this->assertSame(22, $exception->offset);
		$this->assertNull($exception->filePath);
		$this->assertSame("  1 | name: 'api'\n> 2 | replicas: three\n    |           ^", $exception->codeFrame);
	}

	#[Test]
	public function theColumnCountsCodePointsAndTheOffsetCountsBytes(): void
	{
		$exception = $this->decodeError("a: '😀æ' nope");

		$this->assertSame(9, $exception->columnNumber);
		$this->assertSame(12, $exception->offset);
		$this->assertSame("> 1 | a: '😀æ' nope\n    |         ^", $exception->codeFrame);
	}

	#[Test]
	public function aColumnCountsCodePointsAcrossTheByteChunkBoundary(): void
	{
		// The first chunk ends after the lead byte of the final emoji, with its continuation bytes in the next chunk.
		$exception = $this->decodeError("a:'" . str_repeat('😀', 16_384) . "'\tnope\n");

		$this->assertSame(65_541, $exception->offset);
		$this->assertSame(16_390, $exception->columnNumber);
		$this->assertSame("> 1 | …" . str_repeat('😀', 94) . "'\tnope\n    | " . str_repeat(' ', 96) . "\t^", $exception->codeFrame);
	}

	#[Test]
	public function theCodeFrameKeepsTabsAndShowsThreeLines(): void
	{
		$exception = $this->decodeError("a: 1\nb: 2\nc: {\n\td: nope\n}");

		$this->assertSame("  2 | b: 2\n  3 | c: {\n> 4 | \td: nope\n    | \t   ^", $exception->codeFrame);
	}

	#[Test]
	public function theCodeFrameClipsALongLine(): void
	{
		$exception = $this->decodeError('[' . str_repeat('1, ', 1000) . 'nope]');
		$lines = explode("\n", $exception->codeFrame);

		// The last 100 characters, because the error is near the end of the line.
		$this->assertSame('> 1 | …' . substr(str_repeat('1, ', 1000) . 'nope]', -100), $lines[0]);
		$this->assertSame('    | ' . str_repeat(' ', 96) . '^', $lines[1]);
	}

	#[Test]
	public function theCodeFrameClipsUnicodeAroundThePointerAndKeepsTabs(): void
	{
		$exception = $this->decodeError("a: '" . str_repeat('😀', 60) . "'\tnope" . str_repeat('x', 120));

		$this->assertSame("> 1 | …" . str_repeat('😀', 48) . "'\tnope" . str_repeat('x', 46) . "…\n    | " . str_repeat(' ', 50) . "\t^", $exception->codeFrame);
	}

	#[Test]
	public function theCodeFrameClipsLongContextAndPointsPastTheLastCodePoint(): void
	{
		$exception = $this->decodeError('#' . str_repeat('😀', 120) . "\n'" . str_repeat('😀', 120) . "':");

		$this->assertSame("  1 | #" . str_repeat('😀', 99) . "…\n> 2 | …" . str_repeat('😀', 98) . "':\n    | " . str_repeat(' ', 101) . '^', $exception->codeFrame);
	}

	#[Test]
	public function theCodeFrameReplacesCharactersThatATerminalActsOn(): void
	{
		$exception = $this->decodeError("a: '\u{202E}' nope");

		$this->assertSame("> 1 | a: '\u{FFFD}' nope\n    |        ^", $exception->codeFrame);
	}

	#[Test]
	public function anErrorAtTheEndPointsPastTheLastCharacter(): void
	{
		$exception = $this->decodeError("a: [1, 2\n");

		$this->assertSame('Unterminated array: expected “]”', $exception->reason);
		$this->assertSame([1, 4], [$exception->lineNumber, $exception->columnNumber]);

		$exception = $this->decodeError('a:');
		$this->assertSame('Expected a value, but reached the end of the document', $exception->reason);
		$this->assertSame([1, 3], [$exception->lineNumber, $exception->columnNumber]);
	}

	#[Test]
	public function invalidUtf8(): void
	{
		$exception = $this->decodeError("a: 'é'\nb: '\xFF'");
		$this->assertSame('Invalid UTF-8 byte 0xFF', $exception->reason);
		$this->assertSame([2, 5, 12], [$exception->lineNumber, $exception->columnNumber, $exception->offset]);

		$this->assertSame('Incomplete UTF-8 sequence at the end of the input', $this->decodeError("a: '\xE2\x82")->reason);
		$this->assertSame('Invalid UTF-8 byte 0xE2', $this->decodeError("a: '\xE2\x82'")->reason);
		$this->assertSame('Invalid UTF-8 byte 0xED', $this->decodeError("a: '\xED\xA0\x80'")->reason, 'An encoded surrogate');
		$this->assertSame('Invalid UTF-8 byte 0xC0', $this->decodeError("a: '\xC0\xAF'")->reason, 'An overlong form');
		$this->assertSame('Invalid UTF-8 byte 0xF4', $this->decodeError("a: '\xF4\x90\x80\x80'")->reason, 'Above U+10FFFF');
	}

	#[Test]
	public function nestingIsLimitedTo100Levels(): void
	{
		$this->assertIsArray(Soml::decode(str_repeat('[', 100) . str_repeat(']', 100)));
		$this->assertInstanceOf(\stdClass::class, Soml::decode('a: ' . str_repeat('[', 99) . str_repeat(']', 99)));

		$exception = $this->decodeError(str_repeat('[', 101) . str_repeat(']', 101));
		$this->assertSame('The document is nested more than 100 levels deep', $exception->reason);
		$this->assertSame(101, $exception->columnNumber);

		$exception = $this->decodeError('a: ' . str_repeat('[', 100) . str_repeat(']', 100));
		$this->assertSame(103, $exception->columnNumber);
	}

	#[Test]
	public function aDeepDocumentDoesNotOverflowTheStack(): void
	{
		$exception = $this->decodeError(str_repeat('[', 1_000_000));
		$this->assertSame('The document is nested more than 100 levels deep', $exception->reason);
	}

	#[Test]
	public function hugeTokens(): void
	{
		$length = 5_000_000;

		$this->assertSame(['a' => 1], Soml::decode('a: 0x' . str_repeat('0', $length) . '1', associative: true));
		$this->assertSame(['a' => 1], Soml::decode('a: 0b' . str_repeat('0_', $length) . '1', associative: true));
		$this->assertSame(['a' => str_repeat('x', $length)], Soml::decode("a: '" . str_repeat('x', $length) . "'", associative: true));
		$this->assertSame(['a' => str_repeat('é', $length)], Soml::decode('a: "' . str_repeat('é', $length) . '"', associative: true));
		$this->assertEquals(['a' => new Duration(1500 * Duration::MILLISECOND)], Soml::decode('a: 1.5' . str_repeat('0', $length) . 's', associative: true));
		$this->assertSame(['a' => 1 / 3], Soml::decode('a: 0.' . str_repeat('3', $length), associative: true));

		$this->assertSame('Leading zeros are not allowed in a decimal number. Write an identifier, such as a ZIP code, as a string: \'0' . str_repeat('7', 39) . '…\'', $this->decodeError('a: 0' . str_repeat('7', $length))->reason);
		$this->assertSame('1e' . str_repeat('1', 38) . '… is too large to be a finite float. Use infinity if you mean it', $this->decodeError('a: 1e' . str_repeat('1', $length))->reason);
		$this->assertSame('Invalid instant “2026-09-19T' . str_repeat('1', 29) . '…”. An instant is written as 2026-09-19T14:00:00Z, with an optional fraction of up to nine digits and an offset of Z or ±HH:MM', $this->decodeError('a: 2026-09-19T' . str_repeat('1', $length))->reason);
		$this->assertSame('Invalid duration 1.' . str_repeat('0', 38) . '…: it is not a whole number of nanoseconds', $this->decodeError('a: 1.' . str_repeat('0', $length) . '1s')->reason);
		$this->assertSame('An underscore in a number must be between two digits', $this->decodeError('a: 0x' . str_repeat('A', $length) . '_')->reason);
	}

	#[Test]
	public function aLongBlockDelimiterDoesNotMakeALongDiagnostic(): void
	{
		$delimiter = str_repeat("'", 100_000);
		$exception = $this->decodeError("a: {$delimiter}\ntext{$delimiter}");

		$this->assertLessThan(200, \strlen($exception->reason));
		$this->assertStringContainsString('closing delimiter must start a line', $exception->reason);
		$this->assertSame([1, 4, 3], [$exception->lineNumber, $exception->columnNumber, $exception->offset]);
	}

	#[Test]
	public function unicodeLineSeparatorsCannotBreakDiagnostics(): void
	{
		foreach (["\u{2028}", "\u{2029}"] as $separator) {
			$exception = $this->decodeError("'key{$separator}suffix': 1\n'key{$separator}suffix': 2");
			$this->assertStringNotContainsString($separator, $exception->reason);
			$this->assertStringNotContainsString($separator, $exception->getMessage());
			$this->assertStringNotContainsString($separator, $exception->codeFrame);
			$this->assertStringContainsString("key\u{FFFD}suffix", $exception->reason);
			$this->assertSame([2, 1], [$exception->lineNumber, $exception->columnNumber]);
		}
	}

	#[Test]
	public function diagnosticsCountUtf16CodeUnitsAsTheReferenceDoes(): void
	{
		$hint = 'Expected a key, but found “%s”. A key that contains characters other than letters, digits, “_”, and “-” must be quoted, as in \'%s…\'';

		$this->assertSame(sprintf($hint, 'é', str_repeat('é', 40)), $this->decodeError(str_repeat('é', 500) . ': 1')->reason);
		$this->assertSame(sprintf($hint, '😀', str_repeat('😀', 20)), $this->decodeError(str_repeat('😀', 300) . ': 1')->reason);
	}

	#[Test]
	public function stackTracesDoNotShowTheDocument(): void
	{
		foreach (["hunter2: 1\nx", "\xFFhunter2", 'a: "\u{D}hunter2"', "a: 'hunter2'\nb: nope", 'a: hunter2'] as $source) {
			$exception = $this->decodeError($source);

			// The values of the arguments too, which `getTraceAsString()` shows only as `Array`.
			foreach ($exception->getTrace() as $frame) {
				// The calls made inside the library.
				if (str_contains($frame['file'] ?? '', '/src/')) {
					$this->assertStringNotContainsString('hunt', print_r($frame['args'] ?? [], true));
				}
			}

			// The calls into the library, not the test's own.
			$calls = array_filter(explode("\n", $exception->getTraceAsString()), static fn (string $line): bool => preg_match('/: Soml\\\\(?!Tests\\\\)/', $line) === 1);
			$this->assertNotEmpty($calls);
			$this->assertStringNotContainsString('hunt', implode("\n", $calls));
		}
	}

	#[Test]
	public function decodeFile(): void
	{
		$path = tempnam(sys_get_temp_dir(), 'soml');
		$this->assertIsString($path);

		try {
			file_put_contents($path, "port: 8080\n");
			$this->assertSame(['port' => 8080], Soml::decodeFile($path, associative: true));
			$this->assertSame(8080, Soml::decodeFile($path)->port ?? null);

			file_put_contents($path, "port: 8080\nport: 8081\n");

			try {
				$value = Soml::decodeFile($path);
				$this->fail('Accepted ' . get_debug_type($value));
			} catch (DecodeException $exception) {
				$this->assertSame("Duplicate key port at {$path}:2:1", $exception->getMessage());
				$this->assertSame($path, $exception->filePath);
			}
		} finally {
			unlink($path);
		}
	}

	#[Test]
	public function decodeFileOnADirectory(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Cannot read ' . sys_get_temp_dir() . ': ');
		$value = Soml::decodeFile(sys_get_temp_dir());
		$this->fail('Accepted ' . get_debug_type($value));
	}

	#[Test]
	public function fileNamesCannotInjectControlCharactersIntoDiagnostics(): void
	{
		foreach ([
			"config\n\e[31m\u{202E}.soml" => 'config\u{a}\u{1b}[31m\u{202e}.soml',
			"config\xFF\n\e[31m.soml" => "config\u{FFFD}\\u{a}\\u{1b}[31m.soml",
		] as $path => $displayPath) {
			try {
				$value = Decoder::decode("port: 8080\nport: 8081", false, $path);
				$this->fail('Accepted ' . get_debug_type($value));
			} catch (DecodeException $exception) {
				$this->assertSame("Duplicate key port at {$displayPath}:2:1", $exception->getMessage());
				$this->assertSame($path, $exception->filePath);
				$this->assertSame([2, 1], [$exception->lineNumber, $exception->columnNumber]);
			}
		}
	}

	#[Test]
	public function fileReadErrorsCannotInjectControlCharactersIntoDiagnostics(): void
	{
		foreach ([
			"/nonexistent/config\n\e[31m\u{202E}.soml",
			"/nonexistent/config\xFF\n\e[31m.soml",
			"config\0\n\e[31m.soml",
		] as $path) {
			try {
				$value = Soml::decodeFile($path);
				$this->fail('Accepted ' . get_debug_type($value));
			} catch (\RuntimeException $exception) {
				$this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $exception->getMessage());
				$this->assertStringNotContainsString("\u{202E}", $exception->getMessage());
				$this->assertSame(1, preg_match('//u', $exception->getMessage()));
				$this->assertStringContainsString('\\u{a}\\u{1b}[31m', $exception->getMessage());
			}
		}
	}

	#[Test]
	public function decodeFileWithAnInvalidPath(): void
	{
		foreach (['' => '', "a\0b" => 'a\u{0}b'] as $path => $displayPath) {
			try {
				$value = Soml::decodeFile($path);
				$this->fail('Accepted ' . get_debug_type($value));
			} catch (\RuntimeException $exception) {
				$this->assertStringStartsWith("Cannot read {$displayPath}: ", $exception->getMessage());
			}
		}
	}

	#[Test]
	public function decodeFileReportsTheReadErrorEvenWhenAnErrorHandlerHidesIt(): void
	{
		set_error_handler(static fn (): bool => true);

		try {
			$value = Soml::decodeFile('/nonexistent/config.soml');
			$this->fail('Accepted ' . get_debug_type($value));
		} catch (\RuntimeException $exception) {
			$this->assertSame('Cannot read /nonexistent/config.soml: file_get_contents(/nonexistent/config.soml): Failed to open stream: No such file or directory', $exception->getMessage());
		} finally {
			restore_error_handler();
		}
	}

	#[Test]
	public function decodeFileThatCannotBeRead(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Cannot read /nonexistent/config.soml: ');
		$value = Soml::decodeFile('/nonexistent/config.soml');
		$this->fail('Accepted ' . get_debug_type($value));
	}

	#[Test]
	public function valuesDoNotDependOnTheLocale(): void
	{
		$locale = setlocale(\LC_ALL, '0');

		try {
			if (setlocale(\LC_ALL, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8') === false) {
				$this->markTestSkipped('No locale with a decimal comma is installed');
			}

			$this->assertSame([1.5, 1.5e-7], Soml::decode('[1.5, 1.5e-7]'));
			$this->assertSame("[\n\t1.5\n\t1.5e-7\n]\n", Soml::encode([1.5, 1.5e-7]));
		} finally {
			setlocale(\LC_ALL, (string) $locale);
		}
	}

	/**
	Reasons from the error tests of the JavaScript reference implementation, for diagnostics that the conformance suite does not cover.

	@return array<string, array{string, string}>
	*/
	public static function referenceErrors(): array
	{
		$cases = json_decode((string) file_get_contents(__DIR__ . '/error-reasons.json'), true, flags: \JSON_THROW_ON_ERROR);
		$data = [];

		foreach (\is_array($cases) ? $cases : [] as $source => $reason) {
			$data[(string) $source] = [(string) $source, \is_string($reason) ? $reason : ''];
		}

		return $data;
	}

	#[Test]
	#[DataProvider('referenceErrors')]
	public function sameReasonAsTheReference(string $source, string $reason): void
	{
		$this->assertSame($reason, $this->decodeError($source)->reason);
	}

	private function decodeError(string $source): DecodeException
	{
		try {
			$value = Soml::decode($source);
		} catch (DecodeException $exception) {
			return $exception;
		}

		$this->fail('Accepted ' . get_debug_type($value));
	}
}
