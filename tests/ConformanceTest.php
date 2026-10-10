<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Duration;
use Soml\Encoder;
use Soml\Instant;
use Soml\Soml;

/*
Runs the language-neutral conformance suite in `conformance/`, a copy of the one in the `soml` spec repository. Edit it there, and update the copy with `../soml/sync-conformance.sh tests/conformance`.

- `valid/**\/name.soml` must decode to the tagged value in `name.json`, and encode exactly to `name.canonical.soml` with `canonical: true`.
- `valid/**\/name.formatted.soml` is the exact formatted form, which preserves the value and is a fixed point.
- `edit/*.json` specifies the document, path, optional tagged replacement value, and expected document or path error.
- `invalid/**\/name.soml` must be rejected with the same reason, line, and column as the JavaScript reference, which are in `conformance/invalid-reasons.json`.
*/
final class ConformanceTest extends TestCase
{
	private const string ROOT = __DIR__ . '/conformance';

	/**
	@return array<string, array{string}>
	*/
	public static function validCases(): array
	{
		return self::cases('valid');
	}

	/**
	@return array<string, array{string}>
	*/
	public static function invalidCases(): array
	{
		return self::cases('invalid');
	}

	/**
	@return array<string, array{string}>
	*/
	public static function editCases(): array
	{
		$cases = [];

		foreach (glob(self::ROOT . '/edit/*.json') ?: [] as $path) {
			$name = basename($path, '.json');
			$cases[$name] = [$name];
		}

		ksort($cases);
		return $cases;
	}

	/**
	@return array<string, array{string}>
	*/
	private static function cases(string $directory): array
	{
		$root = self::ROOT . "/{$directory}/";
		$cases = [];

		foreach (glob("{$root}*/*.soml") ?: [] as $path) {
			if (str_ends_with($path, '.canonical.soml') || str_ends_with($path, '.formatted.soml')) {
				continue;
			}

			$name = substr($path, \strlen($root), -\strlen('.soml'));
			$cases[$name] = [$name];
		}

		ksort($cases);
		return $cases;
	}

	/**
	@return array<mixed>
	*/
	private static function reasons(): array
	{
		static $reasons = null;
		$reasons ??= json_decode((string) file_get_contents(self::ROOT . '/invalid-reasons.json'), true, flags: \JSON_THROW_ON_ERROR);
		return \is_array($reasons) ? $reasons : [];
	}

	#[Test]
	public function theSuiteIsNotEmpty(): void
	{
		$reasons = self::reasons();
		$this->assertGreaterThan(400, \count(self::validCases()));
		$this->assertGreaterThan(400, \count(self::invalidCases()));
		$this->assertGreaterThan(50, \count(self::editCases()));
		$expected = array_keys($reasons);
		sort($expected);
		$this->assertSame(array_keys(self::invalidCases()), $expected);
	}

	#[Test]
	#[DataProvider('validCases')]
	public function valid(string $name): void
	{
		$base = self::ROOT . "/valid/{$name}";
		$source = (string) file_get_contents("{$base}.soml");
		$expected = json_decode((string) file_get_contents("{$base}.json"), false, 512, \JSON_THROW_ON_ERROR);
		$canonical = (string) file_get_contents("{$base}.canonical.soml");

		$value = Soml::decode($source);
		$this->assertTaggedEquals($expected, self::tag($value));

		// With arrays for objects, `{}` and `[]` are the same, and PHP makes an int of a key such as `'1'`, so the comparison is of the tagged values in arrays too.
		$associative = Soml::decode($source, associative: true);
		$this->assertTaggedEquals(self::toArrays($expected), self::tag($associative));

		$this->assertSame($canonical, Soml::encode($value, canonical: true));

		// Canonical form is a valid document with the same value, and a fixed point. Its members are sorted already, so the default, which keeps their order, writes it too.
		$fromCanonical = Soml::decode($canonical);
		$this->assertTaggedEquals($expected, self::tag($fromCanonical));
		$this->assertSame($canonical, Soml::encode($fromCanonical, canonical: true));
		$this->assertSame($canonical, Soml::encode($fromCanonical));
	}

	#[Test]
	#[DataProvider('invalidCases')]
	public function invalid(string $name): void
	{
		$reasons = self::reasons();
		$source = (string) file_get_contents(self::ROOT . "/invalid/{$name}.soml");

		foreach ([false, true] as $associative) {
			try {
				$value = Soml::decode($source, $associative);
				$this->fail("{$name} was accepted as " . get_debug_type($value));
			} catch (DecodeException $exception) {
				$this->assertSame($reasons[$name], ['reason' => $exception->reason, 'line' => $exception->lineNumber, 'column' => $exception->columnNumber]);
			}
		}
	}

	#[Test]
	#[DataProvider('validCases')]
	public function format(string $name): void
	{
		$base = self::ROOT . "/valid/{$name}";
		$source = (string) file_get_contents("{$base}.soml");
		$expected = (string) file_get_contents("{$base}.formatted.soml");
		$formatted = Soml::format($source);

		$this->assertSame($expected, $formatted);
		$this->assertSame($formatted, Soml::format($formatted));
		$this->assertTaggedEquals(self::tag(Soml::decode($source)), self::tag(Soml::decode($formatted)));
	}

	#[Test]
	#[DataProvider('invalidCases')]
	public function formatRejectsInvalidDocuments(string $name): void
	{
		$source = (string) file_get_contents(self::ROOT . "/invalid/{$name}.soml");

		try {
			$formatted = Soml::format($source);
			$this->fail("{$name} was accepted by the formatter");
		} catch (DecodeException $exception) {
			$this->assertSame(self::reasons()[$name], ['reason' => $exception->reason, 'line' => $exception->lineNumber, 'column' => $exception->columnNumber]);
		}
	}

	#[Test]
	#[DataProvider('validCases')]
	public function appendedValuesCanBeReadAndRemoved(string $name): void
	{
		$base = self::ROOT . "/valid/{$name}";
		$source = (string) file_get_contents("{$base}.formatted.soml");
		$original = Soml::decode($source);

		if (\is_array($original)) {
			$key = \count($original);
		} else {
			$key = 'added';

			while (property_exists($original, $key)) {
				$key .= '_';
			}
		}

		$replacement = [null, true, '😀'];
		$edited = Soml::edit($source, [$key], $replacement);
		$decoded = Soml::decode($edited);
		$this->assertSame($replacement, \is_array($decoded) ? $decoded[$key] : $decoded->{$key});
		$this->assertSame($edited, Soml::format($edited));

		$removed = Soml::edit($edited, [$key]);
		$this->assertSame((string) file_get_contents("{$base}.canonical.soml"), Soml::encode(Soml::decode($removed), canonical: true));
		$this->assertSame($removed, Soml::format($removed));
	}

	#[Test]
	#[DataProvider('editCases')]
	public function edit(string $name): void
	{
		/** @var object{document: string, path: list<string|int>, value?: mixed, expected?: string, error?: bool} $case */
		$case = json_decode((string) file_get_contents(self::ROOT . "/edit/{$name}.json"), false, 512, \JSON_THROW_ON_ERROR);

		if ($case->error ?? false) {
			$this->expectException(\InvalidArgumentException::class);
		}

		$actual = property_exists($case, 'value')
			? Soml::edit($case->document, $case->path, self::untag($case->value))
			: Soml::edit($case->document, $case->path);

		$expected = $case->expected ?? throw new \LogicException('Missing expected document');
		$this->assertSame($expected, $actual);
		$this->assertTaggedEquals(self::tag(Soml::decode($expected)), self::tag(Soml::decode($actual)));
	}

	/*
	Restore the suite's tagged values without losing integer precision, empty objects, or special scalar types.
	*/
	private static function untag(mixed $value): mixed
	{
		if (\is_array($value)) {
			return array_map(self::untag(...), $value);
		}

		if (!$value instanceof \stdClass) {
			throw new \LogicException('Expected a tagged value');
		}

		if (!self::isLeaf($value)) {
			return (object) array_map(self::untag(...), get_object_vars($value));
		}

		if (!\is_string($value->type) || !\is_string($value->value)) {
			throw new \LogicException('Expected string type and value tags');
		}

		return match ($value->type) {
			'string' => $value->value,
			'int' => (int) $value->value,
			'float' => match ($value->value) {
				'infinity' => \INF,
				'-infinity' => -\INF,
				default => (float) $value->value,
			},
			'bool' => $value->value === 'true',
			'null' => null,
			'instant' => Instant::parse($value->value),
			'duration' => new Duration((int) $value->value),
			default => throw new \LogicException('Unknown tagged type: ' . $value->type),
		};
	}

	/*
	A decoded value in the tagged form of the suite, with `stdClass` objects and lists as they are.
	*/
	private static function tag(mixed $value): mixed
	{
		return match (true) {
			\is_string($value) => self::leaf('string', $value),
			\is_int($value) => self::leaf('int', (string) $value),
			\is_float($value) => self::leaf('float', Encoder::formatFloat($value)),
			\is_bool($value) => self::leaf('bool', $value ? 'true' : 'false'),
			$value === null => self::leaf('null', 'null'),
			$value instanceof Instant => self::leaf('instant', (string) $value),
			$value instanceof Duration => self::leaf('duration', (string) $value->nanoseconds),
			$value instanceof \stdClass => (object) array_map(self::tag(...), get_object_vars($value)),
			\is_array($value) => array_map(self::tag(...), $value),
			default => throw new \LogicException('Unexpected value of type ' . get_debug_type($value)),
		};
	}

	private static function leaf(string $type, string $value): \stdClass
	{
		return (object) ['type' => $type, 'value' => $value];
	}

	/*
	The tagged value with every object an array, as `associative: true` gives it, except the leaves.
	*/
	private static function toArrays(mixed $value): mixed
	{
		if ($value instanceof \stdClass) {
			return self::isLeaf($value) ? $value : array_map(self::toArrays(...), get_object_vars($value));
		}

		return \is_array($value) ? array_map(self::toArrays(...), $value) : $value;
	}

	/*
	A leaf is an object with exactly the two string members `type` and `value`, because a document can have a member named `type`.
	*/
	private static function isLeaf(\stdClass $value): bool
	{
		$members = get_object_vars($value);
		return \count($members) === 2 && \is_string($members['type'] ?? null) && \is_string($members['value'] ?? null);
	}

	/*
	Compares two tagged values. The order of members does not matter, and everything else is compared strictly.
	*/
	private function assertTaggedEquals(mixed $expected, mixed $actual): void
	{
		$this->assertSame(self::normalize($expected), self::normalize($actual));
	}

	private static function normalize(mixed $value): mixed
	{
		if ($value instanceof \stdClass) {
			$members = get_object_vars($value);
			ksort($members, \SORT_STRING);
			return ['object' => array_map(self::normalize(...), $members)];
		}

		if (\is_array($value) && !array_is_list($value)) {
			ksort($value, \SORT_STRING);
			return ['object' => array_map(self::normalize(...), $value)];
		}

		return \is_array($value) ? array_map(self::normalize(...), $value) : $value;
	}
}
