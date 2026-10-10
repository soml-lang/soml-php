<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Decoder;
use Soml\Soml;

final class ImprovementContractsTest extends TestCase
{
	#[Test]
	public function invalidInstantDiagnosticsDoNotCopyTheFullToken(): void
	{
		$source = 'a: 2026-10-13T' . str_repeat('1', 4_000_000);
		Decoder::validate('[]');
		$before = memory_get_usage();
		memory_reset_peak_usage();

		try {
			Decoder::validate($source);
			$this->fail('An invalid instant was accepted');
		} catch (DecodeException $exception) {
			$this->assertLessThan(1_000_000, memory_get_peak_usage() - $before);
			$this->assertStringStartsWith('Invalid instant', $exception->reason);
			$this->assertSame(3, $exception->offset);
		}
	}

	#[Test]
	public function decimalOverflowDoesNotAllocateAnUnderscoreFreeCopy(): void
	{
		$source = 'a: 1' . str_repeat('_1', 2_000_000);
		Decoder::validate('[]');
		$before = memory_get_usage();
		memory_reset_peak_usage();

		try {
			Decoder::validate($source);
			$this->fail('An overflowing integer was accepted');
		} catch (DecodeException $exception) {
			$this->assertLessThan(5_000_000, memory_get_peak_usage() - $before);
			$this->assertStringContainsString('outside the 64-bit range', $exception->reason);
			$this->assertSame(3, $exception->offset);
		}
	}

	/** @return iterable<string, array{string, int|float}> */
	public static function decimalBoundaries(): iterable
	{
		yield 'maximum integer' => ['9223372036854775807', \PHP_INT_MAX];
		yield 'minimum integer' => ['-9223372036854775808', \PHP_INT_MIN];
		yield 'nineteen digit integer' => ['1000000000000000000', 1_000_000_000_000_000_000];
		yield 'large whole float' => ['9223372036854775808.0', 9_223_372_036_854_775_808.0];
		yield 'positive exponent' => ['1e21', 1e21];
		yield 'negative exponent' => ['1e-7', 1e-7];
		yield 'fraction and exponent' => ['-12.25e2', -1225.0];
		yield 'zero exponent' => ['0e0', 0.0];
		yield 'negative float zero' => ['-0.0', 0.0];
		yield 'smallest subnormal' => ['5e-324', 5e-324];
	}

	#[Test]
	#[DataProvider('decimalBoundaries')]
	public function decimalFastPathsKeepTheirExactTypes(string $literal, int|float $expected): void
	{
		$this->assertSame(['value' => $expected], Soml::decode('value: ' . $literal, associative: true));
		$this->assertSame(['value' => $expected], Soml::decode(Soml::format('value: ' . $literal), associative: true));
		$this->assertSame('value: ' . $literal, Soml::edit('value: ' . $literal, ['missing']));
	}

	/** @return iterable<string, array{string, int, string}> */
	public static function diagnosticWindows(): iterable
	{
		yield 'supplementary character does not fit' => [str_repeat('a', 39) . '😀tail', 40, str_repeat('a', 39) . '…'];
		yield 'supplementary character fits exactly' => [str_repeat('a', 38) . '😀tail', 40, str_repeat('a', 38) . '😀…'];
		yield 'mixed byte lengths fit exactly' => [str_repeat('a', 37) . 'é😀tail', 40, str_repeat('a', 37) . 'é😀…'];
		yield 'supplementary-only window' => [str_repeat('😀', 21), 40, str_repeat('😀', 20) . '…'];
		yield 'ASCII exact boundary' => [str_repeat('a', 200), 200, str_repeat('a', 200)];
		yield 'ASCII long prefix' => [str_repeat('a', 100_000), 200, str_repeat('a', 200) . '…'];
		yield 'ASCII includes NUL' => ["a\0b😀tail", 3, "a\0b…"];
		yield 'zero window' => ['😀', 0, '…'];
		yield 'empty source' => ['', 0, ''];
	}

	#[Test]
	#[DataProvider('diagnosticWindows')]
	public function nativeASCIIScansPreserveUTF16WindowBoundaries(string $source, int $length, string $expected): void
	{
		$this->assertSame($expected, Decoder::abbreviate($source, $length));
		$this->assertSame(1, preg_match('//u', $expected));
	}
	#[Test]
	public function diagnosticKeysUseJSONEscapesWithoutEscapingUnicodeOrSlashes(): void
	{
		for ($codePoint = 0; $codePoint < 128; $codePoint++) {
			$key = 'prefix' . chr($codePoint) . 'suffix';
			$expected = Soml::isBareKey($key) ? $key : json_encode($key, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
			$this->assertSame($expected, Decoder::describeKey($key));
		}

		$this->assertSame("\"é/😀\u{2028}\u{2029}\"", Decoder::describeKey("é/😀\u{2028}\u{2029}"));
	}

	#[Test]
	public function invalidUTF8IsLocatedExactlyAcrossChunkBoundaries(): void
	{
		foreach ([65_534, 65_535, 65_536, 65_537, 131_071] as $boundary) {
			foreach (["\xFF", "\xE0\x80\x80", "\xED\xA0\x80", "\xF4\x90\x80\x80", "\xF0\x9F"] as $invalid) {
				$prefix = '#' . str_repeat('é', intdiv($boundary, 2)) . '😀';

				try {
					Soml::validate($prefix . $invalid);
					$this->fail('An invalid UTF-8 sequence was accepted');
				} catch (DecodeException $exception) {
					$this->assertSame(strlen($prefix), $exception->offset);
					$this->assertSame(intdiv($boundary, 2) + 3, $exception->columnNumber);
					$this->assertSame(1, preg_match('//u', $exception->codeFrame));
					$this->assertStringStartsWith($invalid === "\xF0\x9F" ? 'Incomplete UTF-8' : 'Invalid UTF-8', $exception->reason);
				}
			}
		}
	}

	#[Test]
	public function plainDecimalOverflowDoesNotCopyItsEntireToken(): void
	{
		$source = 'a: -' . str_repeat('9', 4_000_000);
		Soml::validate('[]');
		$before = memory_get_usage();
		memory_reset_peak_usage();

		try {
			Soml::validate($source);
			$this->fail('An overflowing integer was accepted');
		} catch (DecodeException $exception) {
			$this->assertLessThan(1_000_000, memory_get_peak_usage() - $before);
			$this->assertSame(3, $exception->offset);
			$this->assertStringStartsWith('The integer -999', $exception->reason);
			$this->assertStringContainsString('outside the 64-bit range', $exception->reason);
		}
	}

}
