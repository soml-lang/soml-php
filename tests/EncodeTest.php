<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\Duration;
use Soml\EncodeException;
use Soml\Encoder;
use Soml\Instant;
use Soml\Soml;

enum Level: string
{
	case Debug = 'debug';
}

enum Priority: int
{
	case High = 1;
}

enum Color
{
	case Red;
}

final class EncodeTest extends TestCase
{
	#[Test]
	public function canonicalEncodingSortsALateInversionAfterALongOrderedPrefix(): void
	{
		$value = [];

		for ($index = 0; $index < 1000; $index++) {
			$value['k' . str_pad((string) $index, 4, '0', STR_PAD_LEFT)] = $index;
		}

		$value['a'] = -1;
		$text = Soml::encode($value, canonical: true);
		$this->assertSame("a: -1\n" . Soml::encode(array_slice($value, 0, 1000, true)), $text);
		$this->assertSame('a', array_key_last($value));
	}

	#[Test]
	public function canonicalNumericLookingKeysAreSortedLexically(): void
	{
		$value = ['1e2' => 1, 100 => 2, '01' => 3, -1 => 4, '-0' => 5];
		$this->assertSame("-0: 5\n-1: 4\n01: 3\n100: 2\n1e2: 1\n", Soml::encode($value, canonical: true));
	}

	#[Test]
	public function selfReferentialArraysReportTheDepthLimit(): void
	{
		$value = [];
		$value['self'] = &$value;

		try {
			$text = Soml::encode($value);
			$this->fail('A self-referential array was encoded');
		} catch (EncodeException $exception) {
			$this->assertStringContainsString('nested more than 100 levels', $exception->reason);
			$this->assertSame(array_fill(0, 100, 'self'), $exception->path);
		}
	}

	#[Test]
	public function encodingAfterACircularFailureDoesNotRetainAncestorState(): void
	{
		$value = (object) ['name' => 'shared'];
		$value->self = $value;

		try {
			$text = Soml::encode(['root' => $value]);
			$this->fail('A circular object was encoded');
		} catch (EncodeException $exception) {
			$this->assertSame(['root', 'self'], $exception->path);
		}

		unset($value->self);
		$this->assertSame("a: {\n\tname: 'shared'\n}\nb: {\n\tname: 'shared'\n}\n", Soml::encode(['a' => $value, 'b' => $value]));
	}

	#[Test]
	public function numericPropertiesRemainObjectsInsideArrays(): void
	{
		$value = [(object) ['first', 'second'], ['first', 'second']];
		$decoded = Soml::decode(Soml::encode($value));
		$this->assertIsArray($decoded);
		$this->assertEquals($value, $decoded);
		$this->assertInstanceOf(\stdClass::class, $decoded[0]);
		$this->assertSame(['first', 'second'], $decoded[1]);
	}

	#[Test]
	public function encodingDateIntervalsDoesNotNormalizeTheCallersComponents(): void
	{
		$interval = new \DateInterval('PT90M');
		$properties = get_object_vars($interval);
		$this->assertSame("timeout: 1h30m\n", Soml::encode(['timeout' => $interval]));
		$this->assertSame($properties, get_object_vars($interval));
	}

	#[Test]
	public function membersKeepTheirOrder(): void
	{
		$this->assertSame("name: 'api'\ndescription: 'The edge service'\n", Soml::encode(['name' => 'api', 'description' => 'The edge service']));
	}

	#[Test]
	public function canonicalSortsMembersByCodePoint(): void
	{
		$value = ['b' => 1, 10 => 2, 9 => 3, 'a' => ['z' => 1, 'y' => 2], 'é' => 4, '😀' => 5, 'ﬀ' => 6, 'B' => 7];

		$this->assertSame("10: 2\n9: 3\nB: 7\na: {\n\ty: 2\n\tz: 1\n}\nb: 1\n'é': 4\n'ﬀ': 6\n'😀': 5\n", Soml::encode($value, canonical: true));
	}

	#[Test]
	public function canonicalOrderChecksUseStringComparisonAndLeaveInputUnchanged(): void
	{
		$ordered = ['' => 0, 10 => 1, 9 => 2, 'A' => 3, 'é' => 4, '😀' => 5];
		$unordered = ['' => 0, 9 => 2, 10 => 1, 'A' => 3, 'é' => 4, '😀' => 5];
		$object = (object) ['é' => 4, 'A' => 3];
		$value = ['a' => $ordered, 'b' => $unordered, 'c' => $object];
		$original = $value;
		$originalProperties = get_object_vars($object);

		$this->assertSame(Soml::encode(['a' => $ordered, 'b' => $ordered, 'c' => (object) ['A' => 3, 'é' => 4]]), Soml::encode($value, canonical: true));
		$this->assertSame($original, $value);
		$this->assertSame($originalProperties, get_object_vars($object));
	}

	#[Test]
	public function layout(): void
	{
		$value = [
			'name' => 'api',
			'ports' => [80, 443],
			'pool' => ['min' => 2, 'max' => 16],
			'empty-list' => [],
			'empty-object' => new \stdClass(),
			'records' => [['name' => 'a'], (object) ['name' => 'b']],
		];

		$this->assertSame(<<<'SOML'
			name: 'api'
			ports: [
				80
				443
			]
			pool: {
				min: 2
				max: 16
			}
			empty-list: []
			empty-object: {}
			records: [
				{
					name: 'a'
				}
				{
					name: 'b'
				}
			]

			SOML, Soml::encode($value));
	}

	#[Test]
	public function topLevel(): void
	{
		$this->assertSame("[]\n", Soml::encode([]));
		$this->assertSame("{}\n", Soml::encode(new \stdClass()));
		$this->assertSame("[\n\t1\n]\n", Soml::encode([1]));
		$this->assertSame("a: 1\n", Soml::encode((object) ['a' => 1]));
		$this->assertSame("0: 'a'\n", Soml::encode((object) ['a']), 'A stdClass is always an object');
		$this->assertSame("1: 'a'\n", Soml::encode([1 => 'a']), 'An array that is not a list is an object');
	}

	#[Test]
	public function keys(): void
	{
		$this->assertSame("content-type: 1\n404: 2\n'a.b': 3\n'': 4\n'it''s': 5\n", str_replace("\"it's\"", "'it''s'", Soml::encode(['content-type' => 1, 404 => 2, 'a.b' => 3, '' => 4, "it's" => 5])));
		$this->assertSame("\"a\\nb\": 1\n", Soml::encode(["a\nb" => 1]));
	}

	#[Test]
	public function strings(): void
	{
		$value = ['C:\Users\n', 'say "hi"', "it's", "line\nbreak\ttab", "\0\x1B\x7F", "\u{2028}\u{FEFF}😀", ''];

		$this->assertSame("[\n\t'C:\\Users\\n'\n\t'say \"hi\"'\n\t\"it's\"\n\t\"line\\nbreak\\ttab\"\n\t\"\\u{0}\\u{1b}\\u{7f}\"\n\t'\u{2028}\u{FEFF}😀'\n\t''\n]\n", Soml::encode($value));
	}

	/**
	@return array<string, array{float, string}>
	*/
	public static function floats(): array
	{
		return [
			'zero' => [0.0, '0.0'],
			'negative zero' => [-0.0, '0.0'],
			'one' => [1.0, '1.0'],
			'fraction' => [1.5, '1.5'],
			'pi' => [3.14, '3.14'],
			'negative' => [-2.5, '-2.5'],
			'0.1 + 0.2' => [0.1 + 0.2, '0.30000000000000004'],
			'1e20' => [1e20, '100000000000000000000.0'],
			'1e21' => [1e21, '1e21'],
			'large' => [123456789012345680000.0, '123456789012345680000.0'],
			'1.5e300' => [1.5e300, '1.5e300'],
			'0.000001' => [0.000001, '0.000001'],
			'1e-7' => [1e-7, '1e-7'],
			'1.5e-7' => [1.5e-7, '1.5e-7'],
			'0.0015' => [0.0015, '0.0015'],
			'smallest subnormal' => [5e-324, '5e-324'],
			'largest' => [1.7976931348623157e308, '1.7976931348623157e308'],
			'2^53' => [9007199254740992.0, '9007199254740992.0'],
			'infinity' => [\INF, 'infinity'],
			'negative infinity' => [-\INF, '-infinity'],
		];
	}

	#[Test]
	#[DataProvider('floats')]
	public function floatsAreShortestAndCanonical(float $value, string $expected): void
	{
		$this->assertSame($expected, Encoder::formatFloat($value));
		$this->assertSame([$value], Soml::decode("[{$expected}]"));
	}

	#[Test]
	public function floatsDoNotDependOnPrecisionSettings(): void
	{
		$precision = (string) ini_get('precision');
		$serializePrecision = (string) ini_get('serialize_precision');

		try {
			ini_set('precision', '5');
			ini_set('serialize_precision', '17');
			$this->assertSame("[\n\t0.1\n\t0.30000000000000004\n]\n", Soml::encode([0.1, 0.1 + 0.2]));
		} finally {
			ini_set('precision', $precision);
			ini_set('serialize_precision', $serializePrecision);
		}
	}

	#[Test]
	public function instantsAndDurations(): void
	{
		$value = [
			new Instant(1_789_826_400, 500_000_000),
			new \DateTimeImmutable('2026-09-19T16:00:00.25+02:00'),
			new \DateTime('1969-12-31T23:59:59.5Z'),
			new Duration(90 * Duration::MINUTE),
			new Duration(-250 * Duration::MILLISECOND),
			new Duration(0),
			new \DateInterval('PT1H30M'),
		];

		$this->assertSame("[\n\t2026-09-19T14:00:00.5Z\n\t2026-09-19T14:00:00.25Z\n\t1969-12-31T23:59:59.5Z\n\t1h30m\n\t-0.25s\n\t0s\n\t1h30m\n]\n", Soml::encode($value));
	}

	#[Test]
	public function backedEnumsAreTheirValue(): void
	{
		$this->assertSame("level: 'debug'\npriority: 1\n", Soml::encode(['level' => Level::Debug, 'priority' => Priority::High]));
	}

	#[Test]
	public function enumNamesCannotInjectFormattingCharactersIntoDiagnostics(): void
	{
		$enumName = 'Unsafe' . "\u{202E}" . 'Enum';
		$caseName = 'Case' . "\u{202E}";
		eval('namespace Soml\\Tests; enum ' . $enumName . ' { case ' . $caseName . '; }');
		$value = constant(__NAMESPACE__ . '\\' . $enumName . '::' . $caseName);

		try {
			$text = Soml::encode(['a' => $value]);
			$this->fail("Encoded as {$text}");
		} catch (EncodeException $exception) {
			$reason = 'Cannot serialize the enum case Soml\\Tests\\Unsafe\\u{202e}Enum::Case\\u{202e}, because it has no value. Use a backed enum';
			$this->assertSame($reason, $exception->reason);
			$this->assertSame($reason . ' (at a)', $exception->getMessage());
		}
	}

	#[Test]
	public function dateTimeWithAWrappedTimestampCannotBeEncoded(): void
	{
		$dateTime = new \DateTimeImmutable('2000-01-01T00:00:00Z')->setDate(\PHP_INT_MIN + 1970, 1, 1);
		$this->expectException(EncodeException::class);
		$text = Soml::encode(['date' => $dateTime]);
	}

	#[Test]
	public function oversizedDateIntervalFractionReportsItsPath(): void
	{
		$interval = new \DateInterval('PT0S');
		$interval->f = 10_000_000_000;

		try {
			$text = Soml::encode(['timeout' => $interval]);
			$this->fail('An oversized interval was encoded');
		} catch (EncodeException $exception) {
			$this->assertSame(['timeout'], $exception->path);
			$this->assertStringContainsString('outside the 64-bit range', $exception->reason);
		}
	}

	#[Test]
	public function int64Edges(): void
	{
		$this->assertSame("[\n\t9223372036854775807\n\t-9223372036854775808\n]\n", Soml::encode([\PHP_INT_MAX, \PHP_INT_MIN]));
	}

	/**
	@return array<string, array{array<mixed>|\stdClass, string, list<string|int>}>
	*/
	public static function errors(): array
	{
		$circular = new \stdClass();
		$circular->self = $circular;
		$deep = [];

		for ($index = 0; $index < 100; $index++) {
			$deep = [$deep, 1];
		}

		return [
			'NaN' => [['servers' => [['ratio' => \NAN]]], 'Cannot serialize NaN, which is not representable. Use null for a missing value (at servers[0].ratio)', ['servers', 0, 'ratio']],
			'carriage return' => [['a' => "x\ry"], 'Cannot serialize a string containing a carriage return (U+000D), which is not representable (at a)', ['a']],
			'carriage return in a key' => [['a' => ["x\r" => 1]], 'Cannot serialize a key containing a carriage return (U+000D), which is not representable (at a)', ['a']],
			'invalid UTF-8' => [['a' => [1, "\xFF"]], 'Cannot serialize a string that is not valid UTF-8 (at a[1])', ['a', 1]],
			'invalid UTF-8 in a key' => [['a' => ["\xC3" => 1]], 'Cannot serialize a key that is not valid UTF-8 (at a)', ['a']],
			'circular' => [['a' => $circular], 'Cannot serialize a circular structure (at a.self)', ['a', 'self']],
			'deep' => [$deep, 'Cannot serialize a value nested more than 100 levels deep (at ' . substr(str_repeat('[0]', 100), 0, 200) . '…)', array_fill(0, 100, 0)],
			'object' => [['a' => new \ArrayObject()], 'Cannot serialize an instance of ArrayObject. Only arrays, stdClass objects, scalars, null, Instant, Duration, DateTimeInterface, DateInterval, and backed enums can be serialized (at a)', ['a']],
			'resource' => [['a' => \STDIN], 'Cannot serialize a resource (stream). Only arrays, stdClass objects, scalars, null, Instant, Duration, DateTimeInterface, DateInterval, and backed enums can be serialized (at a)', ['a']],
			'closure' => [['a' => static fn () => 1], 'Cannot serialize an instance of Closure. Only arrays, stdClass objects, scalars, null, Instant, Duration, DateTimeInterface, DateInterval, and backed enums can be serialized (at a)', ['a']],
			'pure enum' => [['a' => Color::Red], 'Cannot serialize the enum case Soml\Tests\Color::Red, because it has no value. Use a backed enum (at a)', ['a']],
			'interval with days' => [['a' => new \DateInterval('P1D')], 'A DateInterval with years, months, or days cannot be a duration, because they are not a fixed length. Use hours or smaller units (at a)', ['a']],
			'instant out of range' => [['a' => new \DateTimeImmutable('@253402300800')], 'Cannot serialize the instant 10000-01-01T00:00:00.000+00:00, because it is outside the years 0001 to 9999 (at a)', ['a']],
			'key that is not bare' => [['a b' => ['c' => \NAN]], 'Cannot serialize NaN, which is not representable. Use null for a missing value (at "a b".c)', ['a b', 'c']],
		];
	}

	/**
	@param array<mixed>|\stdClass $value
	@param list<string|int> $path
	*/
	#[Test]
	#[DataProvider('errors')]
	public function encodeErrors(array|\stdClass $value, string $message, array $path): void
	{
		try {
			$text = Soml::encode($value);
			$this->fail("Encoded as {$text}");
		} catch (EncodeException $exception) {
			$this->assertSame($message, $exception->getMessage());
			$this->assertSame($path, $exception->path);
		}
	}

	#[Test]
	public function bothExceptionsAreSomlExceptions(): void
	{
		foreach ([static fn () => Soml::decode('nope'), static fn () => Soml::encode([\NAN])] as $run) {
			try {
				$value = $run();
				$this->fail('Succeeded with ' . get_debug_type($value));
			} catch (\Soml\SomlException $exception) {
				$this->assertContains($exception::class, [\Soml\DecodeException::class, EncodeException::class]);
			}
		}
	}

	#[Test]
	public function stackTracesDoNotShowTheValues(): void
	{
		try {
			$text = Soml::encode(['a' => ["hunter2\r"]]);
			$this->fail("Encoded as {$text}");
		} catch (EncodeException $exception) {
			$this->assertStringNotContainsString('hunt', $exception->getTraceAsString());
		}
	}

	#[Test]
	public function nestingOf100LevelsIsAllowed(): void
	{
		$value = [];

		for ($index = 1; $index < 100; $index++) {
			$value = [$value];
		}

		$text = Soml::encode($value);
		$this->assertSame($value, Soml::decode($text));
	}

	#[Test]
	public function aSharedObjectIsNotCircular(): void
	{
		$shared = (object) ['a' => 1];

		$this->assertSame("x: {\n\ta: 1\n}\ny: {\n\ta: 1\n}\n", Soml::encode(['x' => $shared, 'y' => $shared]));
	}

	#[Test]
	public function aTopLevelErrorHasNoPath(): void
	{
		try {
			$text = Soml::encode([\NAN]);
			$this->fail("Encoded as {$text}");
		} catch (EncodeException $exception) {
			$this->assertSame('Cannot serialize NaN, which is not representable. Use null for a missing value (at [0])', $exception->getMessage());
			$this->assertSame('Cannot serialize NaN, which is not representable. Use null for a missing value', $exception->reason);
		}

		try {
			$text = Soml::encode(["\xFF" => 1]);
			$this->fail("Encoded as {$text}");
		} catch (EncodeException $exception) {
			$this->assertSame('Cannot serialize a key that is not valid UTF-8', $exception->getMessage());
			$this->assertSame([], $exception->path);
		}
	}

	#[Test]
	public function invalidUtf8AfterALongBarePrefixKeepsTheErrorPathQuoted(): void
	{
		$key = str_repeat('a', 200) . "\xFF";

		try {
			$text = Soml::encode([$key => \NAN]);
			$this->fail('NaN was encoded');
		} catch (EncodeException $exception) {
			$this->assertSame([$key], $exception->path);
			$this->assertStringEndsWith('(at "' . str_repeat('a', 40) . '…")', $exception->getMessage());
		}
	}

	#[Test]
	public function thePathEscapesCharactersThatATerminalActsOn(): void
	{
		try {
			$text = Soml::encode(["\u{1B}[31m\u{202E}" => \NAN]);
			$this->fail("Encoded as {$text}");
		} catch (EncodeException $exception) {
			$this->assertSame('Cannot serialize NaN, which is not representable. Use null for a missing value (at "\u001b[31m\u{202e}")', $exception->getMessage());
		}
	}
}
