<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\EncodeException;
use Soml\Soml;

/*
Decoding, encoding, and formatting must take linear CPU time in the size of the input, whatever its shape. Each case times an input and one eight times its size, and compares the ratio rather than an absolute time, so a slow machine does not fail the test. CPU time excludes time when another process runs. Linear time gives a ratio near 8, and quadratic time near 64, so the threshold leaves room for noise.
*/
final class ComplexityTest extends TestCase
{
	/** @return iterable<string, array{string, string, int}> */
	public static function amplifiedDiagnosticInputs(): iterable
	{
		$long = str_repeat('0', 4_000_000);
		yield 'spaced numeric duration' => ['a: 1.' . $long . ' s', 'A unit cannot follow a number', 6_000_000];
		yield 'micro sign suffix' => ['a: 1.' . $long . 'µs', 'The unit for microseconds is written us', 6_000_000];
		yield 'Greek mu suffix' => ['a: 1.' . $long . 'μs', 'The unit for microseconds is written us', 6_000_000];
		yield 'space separated instant' => ['a: 2026-01-01 12:00:00.' . $long . 'Z', 'not a space', 1_000_000];
		yield 'unknown duration unit' => ['a: 1' . str_repeat('H', 4_000_000), 'is not a unit', 6_000_000];
		yield 'duration integer overflow' => ['a: 1' . $long . 'ns', 'outside the 64-bit range', 6_000_000];
		yield 'lowercase hexadecimal' => ['a: 0x' . $long . 'a', 'Hexadecimal digits are uppercase', 6_000_000];
		yield 'dotted key' => [str_repeat('a', 4_000_000) . '.b: 1', 'A key cannot contain', 1_000_000];
		yield 'missing colon' => [str_repeat('a', 4_000_000) . ' = 1', 'Expected “:”', 1_000_000];
		yield 'duration precision overflow' => ['a: 1.' . $long . '1s', 'not a whole number of nanoseconds', 6_000_000];
	}

	#[Test]
	#[DataProvider('amplifiedDiagnosticInputs')]
	public function diagnosticHintsDoNotAmplifyLargeInputAllocations(string $source, string $reason, int $memoryLimit): void
	{
		\Soml\Decoder::validate('[]');
		$before = memory_get_usage();
		memory_reset_peak_usage();

		try {
			\Soml\Decoder::validate($source);
			$this->fail('An invalid input was accepted');
		} catch (DecodeException $exception) {
			$this->assertLessThan($memoryLimit, memory_get_peak_usage() - $before);
			$this->assertStringContainsString($reason, $exception->reason);
			$this->assertLessThan(1000, strlen($exception->reason));
		}
	}

	#[Test]
	public function blockStringFormattingAppendsToOneOutputBuffer(): void
	{
		$source = "value: '''\n" . str_repeat("    line\n", 100_000) . "    '''\nafter: 2";
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$formatted = Soml::format($source);
		$this->assertLessThan(2 * 1024 * 1024, memory_get_peak_usage() - $before);
		$this->assertSame("value:\n\t'''\n" . str_repeat("\tline\n", 100_000) . "\t'''\nafter: 2\n", $formatted);
	}

	#[Test]
	public function blockCommentFormattingDoesNotAllocateAnArrayForEveryLine(): void
	{
		$source = "/*\n" . str_repeat("\tx \t\n", 200_000) . "*/\na: 1";
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();
		$formatted = Soml::format($source);
		$extraMemory = memory_get_peak_usage() - $memoryBefore;

		$this->assertSame("/*\n" . str_repeat("\tx\n", 200_000) . "*/\na: 1\n", $formatted);
		$this->assertLessThan(5 * 1024 * 1024, $extraMemory);
	}

	#[Test]
	public function reportingAControlByteDoesNotCopyItsLongSourcePrefix(): void
	{
		$source = '#' . str_repeat('a', 4 * 1024 * 1024) . "\x7F";
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();

		try {
			$value = Soml::decode($source);
			$this->fail('A control byte was accepted');
		} catch (DecodeException $exception) {
			$extraMemory = memory_get_peak_usage() - $memoryBefore;
			$this->assertSame(strlen($source) - 1, $exception->offset);
			$this->assertSame(strlen($source), $exception->columnNumber);
			$this->assertStringContainsString('U+007F', $exception->reason);
			$this->assertLessThan(1024 * 1024, $extraMemory);
		}
	}

	#[Test]
	public function reportingInvalidUtf8DoesNotCopyItsValidPrefix(): void
	{
		$source = "#" . str_repeat('x', 4 * 1024 * 1024) . "\xFF";
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();

		try {
			Soml::decode($source);
			$this->fail('An invalid byte was accepted');
		} catch (DecodeException $exception) {
			$this->assertSame(strlen($source) - 1, $exception->offset);
			$this->assertSame(strlen($source), $exception->columnNumber);
			$this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $memoryBefore);
		}
	}

	#[Test]
	public function syntaxTreeValidationDoesNotRetainDecodedArrayItems(): void
	{
		$source = '[' . str_repeat("'" . str_repeat('x', 100_000) . "',", 40) . ']';
		\Soml\SyntaxTree::parse('[]');
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();
		$tree = \Soml\SyntaxTree::parse($source);

		$this->assertCount(40, $tree->body->children);
		$this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $memoryBefore);
	}

	#[Test]
	public function missingColonDiagnosticsDoNotCopyTheWholeKey(): void
	{
		$source = 'first ' . str_repeat('a', 4 * 1024 * 1024) . ': 1';
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();

		try {
			Soml::decode($source);
			$this->fail('A key with spaces was accepted');
		} catch (DecodeException $exception) {
			$this->assertStringStartsWith('A bare key cannot contain spaces', $exception->reason);
			$this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $memoryBefore);
		}
	}

	/** @return array<string, array{string}> */
	public static function longStringTokens(): array
	{
		return [
			'literal' => ["'" . str_repeat('x', 4 * 1024 * 1024) . "'"],
			'escaped' => ['"' . str_repeat('x', 4 * 1024 * 1024) . '\n"'],
		];
	}

	#[Test]
	#[DataProvider('longStringTokens')]
	public function syntaxValidationDoesNotCopyLongStringValues(string $token): void
	{
		$source = "a: {$token}";
		\Soml\SyntaxTree::parse('[]');
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();
		$tree = \Soml\SyntaxTree::parse($source);
		$extraMemory = memory_get_peak_usage() - $memoryBefore;

		$this->assertSame(strlen($source), $tree->body->end);
		$this->assertLessThan(1024 * 1024, $extraMemory);
	}

	#[Test]
	public function validatingBlockStringsUsesBoundedTemporaryMemory(): void
	{
		$source = "value: \"\"\"\n" . str_repeat("\tline\\tcontent\n", 100_000) . "\t\"\"\"";
		\Soml\Decoder::validate('[]');
		$before = memory_get_usage();
		memory_reset_peak_usage();
		\Soml\Decoder::validate($source);
		$this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $before);
	}

	#[Test]
	public function formattingBlockStringsDoesNotAllocateAnArrayPerLine(): void
	{
		$source = "value: '''\n" . str_repeat("    line\n", 100_000) . "    '''";
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$formatted = Soml::format($source);
		$extra = memory_get_peak_usage() - $before;
		$this->assertSame("value:\n\t'''\n" . str_repeat("\tline\n", 100_000) . "\t'''\n", $formatted);
		$this->assertLessThan(4 * 1024 * 1024, $extra);
	}

	#[Test]
	public function formattingLongTriviaGapsUsesBoundedTemporaryMemory(): void
	{
		$source = "[1,\n" . str_repeat(' ', 4 * 1024 * 1024) . '2]';
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$formatted = Soml::format($source);
		$extra = memory_get_peak_usage() - $before;
		$this->assertSame("[\n\t1\n\t2\n]\n", $formatted);
		$this->assertLessThan(1024 * 1024, $extra);
	}

	#[Test]
	public function excessiveMissingObjectPathsDoNotBuildAnUnboundedGraph(): void
	{
		$path = array_fill(0, 10_000, 'missing');
		$before = memory_get_usage();
		memory_reset_peak_usage();

		try {
			$edited = Soml::edit('{}', $path, 1);
			$this->fail('An excessive path was accepted');
		} catch (EncodeException $exception) {
			$extra = memory_get_peak_usage() - $before;
			$this->assertStringContainsString('nested more than 100 levels', $exception->reason);
			$this->assertSame(array_slice($path, 0, 100), $exception->path);
			$this->assertLessThan(1024 * 1024, $extra);
		}
	}

	/** @return iterable<string, array{string, string}> */
	public static function oversizedDiagnosticTokens(): iterable
	{
		yield 'unquoted word' => ['a: ' . str_repeat('word', 1_000_000), 'A string value must be quoted'];
		yield 'unknown spaced unit' => ['a: 1 ' . str_repeat('unit', 1_000_000), 'A unit cannot follow a number'];
		yield 'inline block close' => ["a: '''\n" . str_repeat('x', 4_000_000) . "'''", 'Its closing delimiter must start a line'];
		yield 'huge block delimiter' => ['a: ' . str_repeat("'", 4_000_000) . "\nno close", 'Unterminated block string'];
		yield 'long comma-group prefix' => ['[1' . str_repeat(',111', 500_000) . ',000]', 'A comma separates items'];
	}

	#[Test]
	#[DataProvider('oversizedDiagnosticTokens')]
	public function oversizedDiagnosticsDoNotCopyWholeTokens(string $source, string $reason): void
	{
		$before = memory_get_usage();
		memory_reset_peak_usage();

		try {
			\Soml\Decoder::validate($source);
			$this->fail('An invalid token was accepted');
		} catch (DecodeException $exception) {
			$extra = memory_get_peak_usage() - $before;
			$this->assertStringContainsString($reason, $exception->reason);
			$this->assertLessThan(1000, strlen($exception->reason));
			$this->assertLessThan(1024 * 1024, $extra);
		}
	}

	private const int SIZE_FACTOR = 8;

	private const int MAXIMUM_RATIO = 24;

	private const int COUNT = 5000;

	/**
	@return array<string, array{\Closure(int): string}>
	*/
	public static function validDocuments(): array
	{
		return [
			'literal strings' => [static fn (int $count): string => '[' . str_repeat("'a', ", $count) . ']'],
			'escaped strings' => [static fn (int $count): string => '[' . str_repeat('"a\n", ', $count) . ']'],
			'quoted keys' => [static fn (int $count): string => '{' . implode(', ', array_map(static fn (int $index): string => "'k{$index}': 1", range(1, $count))) . '}'],
			'bare keys' => [static fn (int $count): string => '{' . implode(', ', array_map(static fn (int $index): string => "k{$index}: 1", range(1, $count))) . '}'],
			'block comments' => [static fn (int $count): string => '[' . str_repeat('/* c */ 1, ', $count) . ']'],
			'numbers of every kind' => [static fn (int $count): string => '[' . str_repeat('1, -2, 1.5, 1e5, 0xFF, 0o7, 0b1, 1_000, infinity, ', intdiv($count, 4)) . ']'],
			'instants' => [static fn (int $count): string => '[' . str_repeat('2026-09-19T14:00:00.5+01:00, ', intdiv($count, 4)) . ']'],
			'durations' => [static fn (int $count): string => '[' . str_repeat('1h2m3s4ms5us6ns, -90m, 1.25h, ', intdiv($count, 4)) . ']'],
			'keywords' => [static fn (int $count): string => '[' . str_repeat('true, false, null, ', $count) . ']'],
			'nested arrays side by side' => [static fn (int $count): string => '[' . str_repeat('[[1]], ', $count) . ']'],
			'top-level entries with comments' => [static fn (int $count): string => implode("\n", array_map(static fn (int $index): string => "k{$index}: 'v' # comment", range(1, $count)))],
			'block strings' => [static fn (int $count): string => "a: [\n" . str_repeat("\t'''\n\tline\n\t''',\n", intdiv($count, 2)) . ']'],
			'one long block string' => [static fn (int $count): string => "a: \"\"\"\n" . str_repeat("\tx\\ty\n", $count) . "\t\"\"\""],
			'one long line comment' => [static fn (int $count): string => '# ' . str_repeat('x', $count * 10) . "\na: 1"],
			'one long block comment' => [static fn (int $count): string => '/* ' . str_repeat('x ', $count * 5) . " */\na: 1"],
			'a long literal string' => [static fn (int $count): string => "a: '" . str_repeat('x', $count * 10) . "'"],
			'a long escaped string with escapes' => [static fn (int $count): string => 'a: "' . str_repeat('x\u{1f600}', $count) . '"'],
			'a long bare key' => [static fn (int $count): string => str_repeat('k', $count * 10) . ': 1'],
			'a long hexadecimal int with leading zeros' => [static fn (int $count): string => 'a: 0x' . str_repeat('0', $count * 10) . '1'],
			'a long float' => [static fn (int $count): string => 'a: 0.' . str_repeat('3', $count * 10)],
			'a long duration fraction with trailing zeros' => [static fn (int $count): string => 'a: 1.5' . str_repeat('0', $count * 10) . 's'],
			'many lines of whitespace' => [static fn (int $count): string => "a: 1\n" . str_repeat(" \t\n", $count * 3) . 'b: 2'],
		];
	}

	/**
	@return array<string, array{\Closure(int): string}>
	*/
	public static function invalidDocuments(): array
	{
		return [
			'an error at the end of a long one-line document' => [static fn (int $count): string => '[' . str_repeat('1, ', $count) . 'nope]'],
			'an error at the end of a long multi-line document' => [static fn (int $count): string => implode("\n", array_map(static fn (int $index): string => "k{$index}: 1", range(1, $count))) . "\nnope"],
			'an unterminated literal string' => [static fn (int $count): string => "a: '" . str_repeat('x', $count * 10)],
			'an unterminated block comment' => [static fn (int $count): string => 'a: 1 /* ' . str_repeat('x', $count * 10)],
			'a key with spaces and no colon on a long line' => [static fn (int $count): string => 'a ' . str_repeat('b ', $count * 5)],
			'an invalid number with a long tail' => [static fn (int $count): string => 'a: 1' . str_repeat('x', $count * 10)],
			'invalid UTF-8 at the end of a long multi-byte input' => [static fn (int $count): string => "a: '" . str_repeat('é', $count * 5) . "\xFF'"],
			'a key that starts with a character a bare key cannot hold' => [static fn (int $count): string => str_repeat('$', $count * 10) . ': 1'],
			'a long key with many dots' => [static fn (int $count): string => str_repeat('a.', $count * 5) . 'b: 1'],
			'a bare value document' => [static fn (int $count): string => "'" . str_repeat('x', $count * 10) . "'"],
			'a long duration fraction with zeros before its last digit' => [static fn (int $count): string => 'a: 1.' . str_repeat('0', $count) . '5s'],
			'a long duplicate key' => [static fn (int $count): string => str_repeat('k', $count * 10) . ": 1\n" . str_repeat('k', $count * 10) . ': 2'],
			'an unterminated escaped string with many tabs' => [static fn (int $count): string => 'a: "' . str_repeat("\t", $count * 10)],
			'many block string openings' => [static fn (int $count): string => 'a: ' . str_repeat("'''\n", $count)],
			'a long instant' => [static fn (int $count): string => 'a: 2026-09-19T' . str_repeat('1', $count * 10)],
			'many duration parts' => [static fn (int $count): string => 'a: 1h' . str_repeat('1m', $count)],
			'a long line that the code frame clips' => [static fn (int $count): string => '[' . str_repeat('😀, ', $count) . 'nope' . str_repeat(', 1', $count) . ']'],
		];
	}

	/**
	@param \Closure(int): string $createDocument
	*/
	#[Test]
	#[DataProvider('validDocuments')]
	public function decodingIsLinear(\Closure $createDocument): void
	{
		$this->assertLinear($createDocument, static function (string $document): void {
			Soml::decode($document) === [] && throw new \LogicException('Empty');
		});
	}

	/**
	@param \Closure(int): string $createDocument
	*/
	#[Test]
	#[DataProvider('invalidDocuments')]
	public function theErrorPathIsLinear(\Closure $createDocument): void
	{
		$this->assertLinear($createDocument, static function (string $document): void {
			try {
				$value = Soml::decode($document);
			} catch (DecodeException) {
				return;
			}

			throw new \LogicException('Accepted ' . get_debug_type($value));
		});
	}

	/**
	@param \Closure(int): string $createDocument
	*/
	#[Test]
	#[DataProvider('validDocuments')]
	public function formattingIsLinear(\Closure $createDocument): void
	{
		$this->assertLinear($createDocument, static function (string $document): void {
			Soml::format($document) === '' && throw new \LogicException('Empty');
		});
	}

	#[Test]
	public function formattingManyItemsBeforeALongLineIsLinear(): void
	{
		$this->assertLinear(
			static fn (int $count): string => '[' . str_repeat('1, ', $count) . "'" . str_repeat('x', $count * 10) . "'\n]",
			static function (string $document): void {
				Soml::format($document) === '' && throw new \LogicException('Empty');
			},
		);
	}

	#[Test]
	public function formattingDeepContainersDoesNotCopyTheirEntireSourceSpans(): void
	{
		$document = str_repeat('[', 90) . '1' . str_repeat(' ', 4 * 1024 * 1024) . str_repeat(']', 90);
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();
		$formatted = Soml::format($document);
		$extraMemory = memory_get_peak_usage() - $memoryBefore;

		$this->assertSame(str_repeat('[', 90) . '1' . str_repeat(']', 90) . "\n", $formatted);
		$this->assertLessThan(1024 * 1024, $extraMemory, 'Checking nested layouts should use bounded temporary memory');
	}

	#[Test]
	public function canonicalEncodingDoesNotCopyAlreadyOrderedMembers(): void
	{
		$value = [];

		for ($index = 0; $index < 40_000; $index++) {
			$value['k' . str_pad((string) $index, 5, '0', STR_PAD_LEFT)] = $index;
		}

		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();
		$text = Soml::encode($value, canonical: true);
		$extraMemory = memory_get_peak_usage() - $memoryBefore;

		$this->assertSame(Soml::encode($value), $text);
		$this->assertLessThan(strlen($text) + 1024 * 1024, $extraMemory, 'Already ordered members should need only the output buffer and bounded temporary memory');
	}

	#[Test]
	public function encodingErrorPathsDoNotNormalizeEntireInvalidKeys(): void
	{
		$key = str_repeat("\xFF", 4 * 1024 * 1024);
		$exception = EncodeException::create('Cannot encode');
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();
		$exception->prependPathSegment($key);
		$extraMemory = memory_get_peak_usage() - $memoryBefore;

		$this->assertSame([$key], $exception->path);
		$this->assertSame('Cannot encode (at "' . str_repeat("\u{FFFD}", 40) . '…")', $exception->getMessage());
		$this->assertLessThan(1024 * 1024, $extraMemory, 'An abbreviated error path should not normalize the entire key');
	}

	#[Test]
	public function invalidUtf8DoesNotCopyTheUnexaminedTail(): void
	{
		$document = str_repeat('a', 4 * 1024 * 1024);
		$document[1] = "\xFF";
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();

		try {
			Soml::decode($document);
			$this->fail('Invalid UTF-8 was accepted');
		} catch (DecodeException $exception) {
			$extraMemory = memory_get_peak_usage() - $memoryBefore;
			$this->assertSame('Invalid UTF-8 byte 0xFF', $exception->reason);
			$this->assertSame(1, $exception->offset);
			$this->assertLessThan(1024 * 1024, $extraMemory, 'Reporting the first invalid byte should not copy the rest of the document');
		}
	}

	#[Test]
	public function codeFramesDoNotCopyLongLines(): void
	{
		$document = '[}' . str_repeat(' ', 4 * 1024 * 1024) . "\n";
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();

		try {
			Soml::decode($document);
			$this->fail('An invalid closing bracket was accepted');
		} catch (DecodeException $exception) {
			$extraMemory = memory_get_peak_usage() - $memoryBefore;
			$this->assertSame(1, $exception->offset);
			$this->assertLessThan(500, strlen($exception->codeFrame));
			$this->assertLessThan(1024 * 1024, $extraMemory, 'A clipped code frame should not copy its entire source line');
		}
	}

	#[Test]
	public function columnsDoNotCopyTheWholeLinePrefix(): void
	{
		$prefixLength = 4 * 1024 * 1024;
		$document = str_repeat(' ', $prefixLength) . "[}\n";
		$memoryBefore = memory_get_usage();
		memory_reset_peak_usage();

		try {
			Soml::decode($document);
			$this->fail('An invalid closing bracket was accepted');
		} catch (DecodeException $exception) {
			$extraMemory = memory_get_peak_usage() - $memoryBefore;
			$this->assertSame($prefixLength + 1, $exception->offset);
			$this->assertSame($prefixLength + 2, $exception->columnNumber);
			$this->assertLessThan(1024 * 1024, $extraMemory, 'Counting a column should not copy the entire line prefix');
		}
	}

	/**
	@return array<string, array{\Closure(int): (array<mixed>|\stdClass)}>
	*/
	public static function values(): array
	{
		return [
			'a wide object' => [static fn (int $count): array => array_combine(array_map(static fn (int $index): string => "key {$index}", range(1, $count)), range(1, $count))],
			'strings that need escapes' => [static fn (int $count): array => array_fill(0, $count, "it's\n")],
			'floats' => [static fn (int $count): array => array_map(static fn (int $index): float => $index / 7, range(1, $count))],
			'deep nesting with a long string at each level' => [static function (int $count): \stdClass {
				$value = new \stdClass();

				for ($index = 0; $index < 90; $index++) {
					$value = (object) ['a' => str_repeat('x', $count), 'b' => $value];
				}

				return $value;
			}],
		];
	}

	/**
	@param \Closure(int): (array<mixed>|\stdClass) $createValue
	*/
	#[Test]
	#[DataProvider('values')]
	public function encodingIsLinear(\Closure $createValue): void
	{
		$this->assertLinear($createValue, static function (array|\stdClass $value): void {
			Soml::encode($value, canonical: true) === '' && throw new \LogicException('Empty');
		});
	}

	/**
	@template Input
	@param \Closure(int): Input $createInput
	@param \Closure(Input): void $run
	*/
	private function assertLinear(\Closure $createInput, \Closure $run): void
	{
		$small = $createInput(self::COUNT);
		$large = $createInput(self::COUNT * self::SIZE_FACTOR);

		// Warm up both sizes, so neither measurement includes the first run.
		$run($small);
		$run($large);

		// Measured again before it fails, because a busy machine can slow one measurement down, while quadratic time is slow every time.
		$ratio = \INF;

		for ($attempt = 0; $attempt < 5 && $ratio >= self::MAXIMUM_RATIO; $attempt++) {
			$ratio = min($ratio, self::time(static fn () => $run($large)) / self::time(static fn () => $run($small)));
		}

		$this->assertLessThan(self::MAXIMUM_RATIO, $ratio, self::SIZE_FACTOR . ' times the input took ' . round($ratio, 1) . ' times as long');
	}

	/*
	The fastest of several runs, which is the measurement least disturbed by garbage collection and by other processes.
	*/
	private static function time(\Closure $run): float
	{
		$fastest = \INF;

		for ($index = 0; $index < 5; $index++) {
			$start = self::cpuTime();
			$run();
			$fastest = min($fastest, self::cpuTime() - $start);
		}

		return max($fastest, 1);
	}

	/**
	The process's user and system CPU time in microseconds. Wall-clock pauses from scheduling are irrelevant to algorithmic complexity.
	*/
	private static function cpuTime(): float
	{
		$usage = getrusage();

		if ($usage === false) {
			throw new \LogicException('Process CPU time is unavailable.');
		}

		return array_sum([$usage['ru_utime.tv_sec'], $usage['ru_stime.tv_sec']]) * 1_000_000 + array_sum([$usage['ru_utime.tv_usec'], $usage['ru_stime.tv_usec']]);
	}
}
