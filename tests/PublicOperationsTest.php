<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Duration;
use Soml\Instant;
use Soml\Soml;

final class PublicOperationsTest extends TestCase
{
	#[Test]
	public function validationAcceptsNulKeysWithoutRetainingLargeValues(): void
	{
		$source = '"\u{0}key": [' . str_repeat("'" . str_repeat('x', 100_000) . "',", 40) . ']';
		Soml::validate('[]');
		$before = memory_get_usage();
		memory_reset_peak_usage();
		Soml::validate($source);
		$this->assertLessThan(1_000_000, memory_get_peak_usage() - $before);
		$this->expectException(DecodeException::class);
		Soml::validate('"\u{0}key": null' . "\n" . '"\u{0}key": 1');
	}

	/** @return iterable<string, array{mixed}> */
	public static function fragments(): iterable
	{
		yield 'null' => [null];
		yield 'boolean' => [false];
		yield 'integer boundary' => [\PHP_INT_MIN];
		yield 'float boundary' => [5e-324];
		yield 'infinity' => [\INF];
		yield 'escaped string' => ["it's\n\0😀"];
		yield 'empty array' => [[]];
		yield 'empty object' => [new \stdClass()];
		yield 'numeric object' => [(object) ['1' => null, '0' => []]];
		yield 'mixed containers' => [[(object) ['b' => null, 'a' => [1, 1.0]]]];
		yield 'instant' => [new Instant(-1, 999_999_999)];
		yield 'duration' => [new Duration(\PHP_INT_MIN)];
	}

	#[Test]
	#[DataProvider('fragments')]
	public function fragmentEncodingPreservesEverySupportedValueType(mixed $value): void
	{
		foreach ([false, true] as $oneLine) {
			$fragment = Soml::encodeValue($value, canonical: true, oneLine: $oneLine);
			$this->assertSame(Soml::encode(['value' => $value], canonical: true), Soml::encode(['value' => Soml::decodeValue($fragment)], canonical: true));
			$this->assertFalse(str_ends_with($fragment, "\n"));

			if ($oneLine) {
				$this->assertStringNotContainsString("\n", $fragment);
			}
		}
	}

	/** @return iterable<string, array{string}> */
	public static function invalidFragments(): iterable
	{
		foreach (['', ' 1', "1\n", '1 # comment', '1 2', 'key: 1', '01', 'NaN', '1e-400', "'\xFF'", '"\u{d}"'] as $fragment) {
			yield 'bytes ' . bin2hex($fragment) => [$fragment];
		}
	}

	#[Test]
	#[DataProvider('invalidFragments')]
	public function fragmentsRequireExactlyOneValidValue(string $fragment): void
	{
		$this->expectException(DecodeException::class);
		$result = Soml::decodeValue($fragment);
	}

	#[Test]
	public function canonicalizationPreservesNumericObjectsAndNormalizesEquivalentSpellings(): void
	{
		$source = "# comment\nb: {1: null, 0: []}\na: 0x10\nduration: 90m";
		$expected = "a: 16\nb: {\n\t0: []\n\t1: null\n}\nduration: 1h30m\n";
		$this->assertSame($expected, Soml::canonicalize($source));
		$this->assertSame($expected, Soml::canonicalize($expected));
		$this->assertSame('{' . "\n\ta: 1\n\tb: 2\n}", Soml::encodeValue((object) ['b' => 2, 'a' => 1], canonical: true));
	}

	#[Test]
	public function lookupDistinguishesNullFromMissingAndPreservesValueTypes(): void
	{
		$value = Soml::decode("root: {items: [{0: null, '': 1.0, 'a.b': 2}]}\n");
		$this->assertNull(Soml::get($value, ['root', 'items', 0, '0']));
		$this->assertSame(1.0, Soml::get($value, ['root', 'items', 0, '']));
		$this->assertSame(2, Soml::get($value, ['root', 'items', 0, 'a.b']));
		$this->assertSame(3, Soml::get(["\0key" => [3]], ["\0key", 0]));
		$this->expectException(\OutOfBoundsException::class);
		$result = Soml::get($value, ['root', 'missing']);
	}

	#[Test]
	public function lookupSharesTheEditPathValidationContract(): void
	{
		$value = Soml::decode('a: 1');
		foreach ([[], [-1], ["bad\r"], ['a', 'child'], [0]] as $path) {
			try {
				$result = Soml::get($value, $path);
				$this->fail('An invalid lookup path was accepted');
			} catch (\InvalidArgumentException $exception) {
				$this->assertNotSame('', $exception->getMessage());
			}
		}
	}

	#[Test]
	public function lookupSupportsPlainObjectsAndRejectsSubclassesBeforeAccessorsRun(): void
	{
		$this->assertSame(1, Soml::get((object) ["\0key" => 1], ["\0key"]));
		$value = new class extends \stdClass {
			public int $stored = 1;
			public int $hooked = 2 {
				get {
					throw new \LogicException('A property hook was invoked with ' . $this->hooked);
				}
			}

			public function __get(string $name): never
			{
				throw new \LogicException('A magic accessor was invoked');
			}
		};
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('subclasses are unsupported');
		$result = Soml::get($value, ['stored']);
	}
}
