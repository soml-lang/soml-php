<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Soml\Decoder;
use Soml\Duration;
use Soml\Instant;
use Soml\Soml;

/*
Random values survive encoding and decoding, and canonical form is a fixed point.
*/
final class RoundTripTest extends TestCase
{
	/*
	Characters chosen to hit every branch of string and key encoding.
	*/
	private const array INTERESTING_CHARACTERS = ['a', 'Z', '0', '_', '-', '.', ' ', '\'', '"', '\\', "\n", "\t", "\u{0}", "\u{1}", "\u{1F}", "\u{7F}", "\u{80}", "\u{A0}", 'é', "\u{2028}", "\u{D7FF}", "\u{E000}", "\u{FEFF}", "\u{FFFD}", "\u{FFFF}", '😀', "\u{10000}", "\u{10FFFF}", '#', '/*', '*/', ':', ',', '{', '}', '[', ']', "'''", '"""', 'true', 'null', 'infinity', '1h', '2026-09-19T14:00:00Z'];

	private Randomizer $random;

	#[Test]
	public function randomValues(): void
	{
		for ($seed = 1; $seed <= 500; $seed++) {
			$this->random = new Randomizer(new Mt19937($seed));
			$value = $this->randomCollection(4);
			$text = Soml::encode($value);
			$canonical = Soml::encode($value, canonical: true);

			$this->assertSame(self::describe($value), self::describe(Soml::decode($text)), "Seed {$seed}");
			$this->assertSame(self::describe($value), self::describe(Soml::decode($canonical)), "Seed {$seed}");
			$this->assertSame($canonical, Soml::encode(Soml::decode($text), canonical: true), "Seed {$seed}");
			$this->assertSame($canonical, Soml::encode(Soml::decode($canonical)), "Seed {$seed}");
			$this->assertSame(self::describe(Soml::decode($text, associative: true)), self::describe(Soml::decode(Soml::encode(Soml::decode($text, associative: true)), associative: true)), "Seed {$seed}");
		}
	}

	#[Test]
	public function floatBitPatterns(): void
	{
		$this->random = new Randomizer(new Mt19937(1));
		$floats = [];

		while (\count($floats) < 20_000) {
			$float = unpack('e', $this->random->getBytes(8))[1] ?? 0.0;
			$this->assertIsFloat($float);

			if (!is_nan($float)) {
				$floats[] = $float;
			}
		}

		$decoded = Soml::decode(Soml::encode($floats));
		$this->assertIsArray($decoded);

		foreach ($floats as $index => $float) {
			// Negative zero is the same value as zero.
			$this->assertSame(pack('e', $float === 0.0 ? 0.0 : $float), pack('e', $decoded[$index]));
		}
	}

	#[Test]
	public function integers(): void
	{
		$this->random = new Randomizer(new Mt19937(1));
		$integers = [\PHP_INT_MIN, \PHP_INT_MIN + 1, -1, 0, 1, \PHP_INT_MAX - 1, \PHP_INT_MAX, 2 ** 53 + 1, -(2 ** 53) - 1];

		for ($index = 0; $index < 20_000; $index++) {
			$integers[] = $this->random->getInt(\PHP_INT_MIN, \PHP_INT_MAX) >> $this->random->getInt(0, 62);
		}

		$this->assertSame($integers, Soml::decode(Soml::encode($integers)));
	}

	#[Test]
	public function instantsAndDurations(): void
	{
		$this->random = new Randomizer(new Mt19937(1));
		$values = [new Duration(\PHP_INT_MIN), new Duration(\PHP_INT_MAX), new Instant(Instant::MIN_EPOCH_SECONDS), new Instant(Instant::MAX_EPOCH_SECONDS, 999_999_999)];

		for ($index = 0; $index < 5000; $index++) {
			$values[] = new Duration($this->random->getInt(\PHP_INT_MIN, \PHP_INT_MAX) >> $this->random->getInt(0, 62));
			$values[] = new Instant($this->random->getInt(Instant::MIN_EPOCH_SECONDS, Instant::MAX_EPOCH_SECONDS), $this->random->getInt(0, 1) === 0 ? 0 : $this->random->getInt(0, 999_999_999));
		}

		$this->assertEquals($values, Soml::decode(Soml::encode($values)));
	}

	#[Test]
	public function everyScalarValueSurvivesAsAKeyAndAsAValue(): void
	{
		$strings = [];
		$chunk = '';

		for ($codePoint = 0; $codePoint <= 0x10FFFF; $codePoint++) {
			if ($codePoint === 0x0D || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
				continue;
			}

			$chunk .= Decoder::encodeCodePoint($codePoint);

			if (\strlen($chunk) >= 16_384) {
				$strings[] = $chunk;
				$chunk = '';
			}
		}

		$strings[] = $chunk;
		$this->assertSame($strings, Soml::decode(Soml::encode($strings)));

		$object = array_fill_keys($strings, 1);
		$this->assertSame($object, Soml::decode(Soml::encode($object), associative: true));
	}

	/**
	@return array<mixed>|\stdClass
	*/
	private function randomCollection(int $depth): array|\stdClass
	{
		$count = $this->random->getInt(0, 5);

		if ($this->random->getInt(0, 1) === 0) {
			$list = [];

			for ($index = 0; $index < $count; $index++) {
				$list[] = $this->randomValue($depth - 1);
			}

			return $list;
		}

		$object = new \stdClass();

		for ($index = 0; $index < $count; $index++) {
			$key = $this->randomString();

			// A `stdClass` property cannot start with NUL.
			if (!str_starts_with($key, "\0")) {
				$object->{$key} = $this->randomValue($depth - 1);
			}
		}

		return $object;
	}

	private function randomValue(int $depth): mixed
	{
		$kind = $this->random->getInt($depth > 0 ? 0 : 2, 10);

		return match ($kind) {
			0, 1 => $this->randomCollection($depth),
			2, 3 => $this->randomString(),
			4 => $this->random->getInt(\PHP_INT_MIN, \PHP_INT_MAX) >> $this->random->getInt(0, 62),
			5 => $this->randomFloat(),
			6 => $this->random->getInt(0, 1) === 1,
			7 => null,
			8 => new Instant($this->random->getInt(Instant::MIN_EPOCH_SECONDS, Instant::MAX_EPOCH_SECONDS), $this->random->getInt(0, 999_999_999)),
			default => new Duration($this->random->getInt(\PHP_INT_MIN, \PHP_INT_MAX) >> $this->random->getInt(0, 62)),
		};
	}

	private function randomString(): string
	{
		$string = '';

		for ($index = $this->random->getInt(0, 6); $index > 0; $index--) {
			$string .= self::INTERESTING_CHARACTERS[$this->random->getInt(0, \count(self::INTERESTING_CHARACTERS) - 1)];
		}

		return $string;
	}

	private function randomFloat(): float
	{
		$float = match ($this->random->getInt(0, 3)) {
			0 => unpack('e', $this->random->getBytes(8))[1] ?? 0.0,
			1 => $this->random->getInt(-1000, 1000) / 8,
			2 => \INF * ($this->random->getInt(0, 1) === 0 ? 1 : -1),
			default => $this->random->getFloat(-1e6, 1e6),
		};

		return \is_float($float) && !is_nan($float) ? $float : 0.0;
	}

	/*
	A value as a string that tells apart every difference that matters: types, `stdClass` from arrays, and float bits. The order of members does not matter.
	*/
	private static function describe(mixed $value): string
	{
		return match (true) {
			$value instanceof \stdClass => '{' . implode(',', array_map(static fn (string $key): string => json_encode((string) $key, \JSON_THROW_ON_ERROR) . ':' . self::describe($value->{$key}), self::sortedKeys(get_object_vars($value)))) . '}',
			\is_array($value) => '[' . implode(',', array_map(self::describe(...), $value)) . ']',
			\is_float($value) => 'float:' . bin2hex(pack('e', $value === 0.0 ? 0.0 : $value)),
			$value instanceof Instant => "instant:{$value}",
			$value instanceof Duration => "duration:{$value->nanoseconds}",
			default => get_debug_type($value) . ':' . json_encode($value, \JSON_THROW_ON_ERROR),
		};
	}

	/**
	@param array<mixed> $members
	@return list<string>
	*/
	private static function sortedKeys(array $members): array
	{
		$keys = array_map(strval(...), array_keys($members));
		sort($keys, \SORT_STRING);
		return $keys;
	}
}
