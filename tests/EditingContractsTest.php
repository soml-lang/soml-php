<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\Duration;
use Soml\EncodeException;
use Soml\Instant;
use Soml\Soml;

final class EditingContractsTest extends TestCase
{
	/** @return iterable<string, array{string, array<mixed>, string}> */
	public static function invalidPaths(): iterable
	{
		yield 'empty list' => ['{}', [], 'non-empty list'];
		yield 'associative path' => ['{}', ['key' => 'value'], 'non-empty list'];
		yield 'sparse path' => ['{}', [0 => 'a', 2 => 'b'], 'non-empty list'];
		yield 'negative index' => ['[]', [-1], 'non-negative integer'];
		yield 'null segment' => ['{}', [null], 'Each path segment'];
		yield 'boolean segment' => ['[]', [false], 'Each path segment'];
		yield 'float segment' => ['[]', [0.0], 'Each path segment'];
		yield 'object segment' => ['{}', [new \stdClass()], 'Each path segment'];
		yield 'resource segment' => ['{}', [\STDIN], 'Each path segment'];
		yield 'invalid UTF-8 segment' => ['{}', ["\xFF"], 'valid UTF-8'];
		yield 'encoded surrogate segment' => ['{}', ["\xED\xA0\x80"], 'valid UTF-8'];
		yield 'carriage return segment' => ['{}', ["a\rb"], 'without a carriage return'];
		yield 'string array index' => ['[1]', ['0'], 'An array requires an integer'];
		yield 'integer object key' => ['{0: 1}', [0], 'An object requires a string'];
		yield 'scalar traversal' => ['{a: false}', ['a', 'b'], 'not an object or an array'];
		yield 'missing array below object' => ['{}', ['a', 0], 'Cannot create an array index'];
		yield 'array gap' => ['[1]', [2], 'index at the end'];
		yield 'wrong type in nested container' => ['{a: [{b: 1}]}', ['a', 0, 0], 'An object requires a string'];
	}

	/** @param array<mixed> $path */
	#[Test]
	#[DataProvider('invalidPaths')]
	public function invalidPathsFailBeforeEncodingTheReplacement(string $source, array $path, string $reason): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($reason);
		// Invoke dynamically to exercise runtime validation of paths outside the documented type.
		$result = new \ReflectionMethod(Soml::class, 'edit')->invoke(null, $source, $path, \NAN);
	}

	/** @return iterable<string, array{string, mixed, string}> */
	public static function replacements(): iterable
	{
		yield 'empty array' => ['[]', [], '[]'];
		yield 'empty object' => ['{}', new \stdClass(), '{}'];
		yield 'nested numeric object' => ['[0]', (object) [0 => 'a', 1 => 'b'], "{0: 'a', 1: 'b'}"];
		yield 'nested sparse array as object' => ['{}', [1 => 'a', 3 => 'b'], "{1: 'a', 3: 'b'}"];
		yield 'mixed scalar array' => ['[]', [true, null, 1.0, 'a'], "[true, null, 1.0, 'a']"];
		yield 'array with object and list' => ['{}', [new \stdClass(), []], '[{}, []]'];
		yield 'string containing quote and syntax' => ['[]', "it's # /* [] */", '"it\'s # /* [] */"'];
		yield 'string with escaped controls' => ['{}', "\0\n\t\x1B", '"\u{0}\n\t\u{1b}"'];
		yield 'instant before Unix epoch' => ['[]', new Instant(-1, 999_999_999), '1969-12-31T23:59:59.999999999Z'];
		yield 'duration at minimum integer' => ['{}', new Duration(\PHP_INT_MIN), '-2562047h47m16.854775808s'];
		yield 'mutable datetime with offset' => ['[]', new \DateTime('2000-01-01T01:00:00.000001+01:00'), '2000-01-01T00:00:00.000001Z'];
		yield 'unnormalized interval' => ['{}', new \DateInterval('PT90M'), '1h30m'];
		yield 'empty key in object' => ['[]', (object) ['' => [1]], "{'': [1]}"];
		yield 'literal dot in object key' => ['[]', (object) ['a.b' => true], "{'a.b': true}"];
		yield 'non-BMP object key' => ['{}', (object) ['🚀' => 1], "{'🚀': 1}"];
	}

	#[Test]
	#[DataProvider('replacements')]
	public function containerEncodingStaysInlineWithoutTouchingSurroundingSource(string $container, mixed $value, string $expected): void
	{
		$source = "# header\nroot: {target: {$container}, sibling: 0x0_FF} /* tail */\n";
		$edited = Soml::edit($source, ['root', 'target'], $value);
		$this->assertSame("# header\nroot: {target: {$expected}, sibling: 0x0_FF} /* tail */\n", $edited);
		$decoded = Soml::decode($edited);
		$this->assertInstanceOf(\stdClass::class, $decoded);
		$this->assertInstanceOf(\stdClass::class, $decoded->root);
		$this->assertSame(255, $decoded->root->sibling);
		$this->assertSame(Soml::encode(['value' => $value], canonical: true), Soml::encode(['value' => $decoded->root->target], canonical: true));
	}

	#[Test]
	public function inlineObjectReplacementsSupportLeadingNulKeys(): void
	{
		$source = "root: {target: {}, sibling: 0x0_FF}";
		$edited = Soml::edit($source, ['root', 'target'], (object) ["\0key" => 1]);
		$this->assertSame('root: {target: {"\u{0}key": 1}, sibling: 0x0_FF}', $edited);
		$this->assertSame(['root' => ['target' => ["\0key" => 1], 'sibling' => 255]], Soml::decode($edited, associative: true));
	}

	#[Test]
	public function unsupportedStringableObjectsCannotExecuteSerializationCode(): void
	{
		$value = new class implements \Stringable {
			public function __toString(): string
			{
				throw new \LogicException('The encoder invoked user code');
			}
		};
		$this->expectException(EncodeException::class);
		$result = Soml::edit('a: {}', ['a', 'new'], $value);
	}

	#[Test]
	public function unsupportedJsonSerializableObjectsCannotExecuteSerializationCode(): void
	{
		$value = new class implements \JsonSerializable {
			public function jsonSerialize(): mixed
			{
				throw new \LogicException('The encoder invoked user code');
			}
		};
		$this->expectException(EncodeException::class);
		$result = Soml::encode(['a' => $value]);
	}
}
