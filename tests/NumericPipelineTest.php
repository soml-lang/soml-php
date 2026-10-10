<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Soml;

final class NumericPipelineTest extends TestCase
{
	/** @return iterable<string, array{string}> */
	public static function radixPrefixes(): iterable
	{
		yield 'hexadecimal' => ['0x'];
		yield 'octal' => ['0o'];
		yield 'binary' => ['0b'];
	}

	#[Test]
	#[DataProvider('radixPrefixes')]
	public function radixLeadingZerosDoNotNeedAnUnderscoreFreeCopy(string $prefix): void
	{
		$source = 'a: ' . $prefix . str_repeat('0_', 2_000_000) . '1';
		Soml::validate('[]');
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$value = Soml::decode($source, associative: true);
		$this->assertLessThan(5_000_000, memory_get_peak_usage() - $before);
		$this->assertSame(['a' => 1], $value);
	}

	/** @return iterable<string, array{string, int}> */
	public static function validRadixIntegers(): iterable
	{
		foreach (['0x' => '7FFFFFFFFFFFFFFF', '0o' => '777777777777777777777', '0b' => str_repeat('1', 63)] as $prefix => $maximum) {
			yield $prefix . ' zero' => [$prefix . '0', 0];
			yield $prefix . ' zero separators' => [$prefix . '0_0_0', 0];
			yield $prefix . ' maximum' => [$prefix . $maximum, \PHP_INT_MAX];
			yield $prefix . ' separated maximum' => [$prefix . implode('_', str_split($maximum)), \PHP_INT_MAX];
			yield $prefix . ' leading zeros and maximum' => [$prefix . str_repeat('0_', 200) . $maximum, \PHP_INT_MAX];
		}
	}

	#[Test]
	#[DataProvider('validRadixIntegers')]
	public function radixConversionsPreserveExactSignedIntegerBoundaries(string $source, int $expected): void
	{
		$this->assertSame($expected, Soml::decodeValue($source));
		Soml::validate('value: ' . $source);
		$this->assertSame('value: ' . $expected . "\n", Soml::canonicalize('value: ' . $source));
	}

	/** @return iterable<string, array{string}> */
	public static function overflowingRadixIntegers(): iterable
	{
		foreach (['0x' => '8000000000000000', '0o' => '1000000000000000000000', '0b' => '1' . str_repeat('0', 63)] as $prefix => $digits) {
			yield $prefix . ' next integer' => [$prefix . $digits];
			yield $prefix . ' separated overflow' => [$prefix . implode('_', str_split($digits))];
			yield $prefix . ' leading zeros and overflow' => [$prefix . str_repeat('0_', 200) . $digits];
			yield $prefix . ' enormous overflow' => [$prefix . str_repeat('1_', 100_000) . '1'];
		}
	}

	#[Test]
	#[DataProvider('overflowingRadixIntegers')]
	public function radixOverflowNeverSaturatesOrConvertsToFloat(string $source): void
	{
		$this->expectException(DecodeException::class);
		$this->expectExceptionMessage('outside the 64-bit range');
		$result = Soml::decodeValue($source);
	}

	/** @return iterable<string, array{string, string}> */
	public static function malformedRadixIntegers(): iterable
	{
		yield 'hex invalid digit after oversized prefix' => ['0x' . str_repeat('F_', 100) . 'G', 'Invalid hexadecimal digit'];
		yield 'octal invalid digit after oversized prefix' => ['0o' . str_repeat('7_', 100) . '8', 'Invalid octal digit'];
		yield 'binary invalid digit after oversized prefix' => ['0b' . str_repeat('1_', 100) . '2', 'Invalid binary digit'];
		yield 'hex lowercase takes precedence' => ['0x' . str_repeat('F_', 100) . 'a', 'Hexadecimal digits are uppercase'];
		yield 'hex trailing separator' => ['0x' . str_repeat('F_', 100), 'An underscore in a number must be between two digits'];
		yield 'octal doubled separator' => ['0o' . str_repeat('7_', 100) . '_7', 'An underscore in a number must be between two digits'];
		yield 'binary leading separator' => ['0b_1', 'An underscore in a number must be between two digits'];
		yield 'signed hexadecimal' => ['-0x1', 'A hexadecimal integer cannot have a sign'];
		yield 'signed octal' => ['-0o1', 'An octal integer cannot have a sign'];
		yield 'signed binary' => ['-0b1', 'A binary integer cannot have a sign'];
	}

	#[Test]
	#[DataProvider('malformedRadixIntegers')]
	public function radixSyntaxErrorsTakePrecedenceOverRangeErrors(string $source, string $reason): void
	{
		$this->expectException(DecodeException::class);
		$this->expectExceptionMessage($reason);
		$result = Soml::decodeValue($source);
	}
}
