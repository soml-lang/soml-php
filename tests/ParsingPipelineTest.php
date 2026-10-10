<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Soml;
use Soml\SyntaxTree;

final class ParsingPipelineTest extends TestCase
{
	#[Test]
	public function emptyNestedContainersKeepTheirOwnSpansAndComments(): void
	{
		$source = "/* header */\nfirst: {inner: [[], {}, [0, {x: '[#]'}]]} # between\nsecond: [] /* footer */";
		$tree = SyntaxTree::parse($source);
		[$first, $second] = $tree->body->children;
		$this->assertSame('first', $first->key);
		$this->assertSame('second', $second->key);
		$this->assertNotNull($first->value);
		$this->assertNotNull($second->value);
		$this->assertSame('[]', substr($source, $second->value->start, $second->value->length));
		$inner = $first->value->children[0]->value;
		$this->assertNotNull($inner);
		$this->assertSame(['Array', 'Object', 'Array'], array_map(static fn ($node) => $node->type, $inner->children));
		$this->assertSame(['[]', '{}', "[0, {x: '[#]'}]"], array_map(static fn ($node) => substr($source, $node->start, $node->length), $inner->children));
		$this->assertSame(['/* header */', '# between', '/* footer */'], array_map(static fn ($node) => substr($source, $node->start, $node->length), $tree->comments));
		$this->assertSame(Soml::canonicalize($source), Soml::canonicalize(Soml::format($source)));
		$this->assertSame(42, Soml::get(Soml::decode(Soml::edit($source, ['first', 'inner', 2, 1, 'x'], 42)), ['first', 'inner', 2, 1, 'x']));
	}

	/** @return iterable<string, array{string, non-empty-string, int}> */
	public static function globalErrors(): iterable
	{
		yield 'UTF8 after a control wins' => ["a: 1\x7F\xFF", 'Invalid UTF-8', 5];
		yield 'UTF8 after a BOM wins' => ["\u{FEFF}a: 1\xFF", 'Invalid UTF-8', 7];
		yield 'truncated UTF8 after a BOM wins' => ["\u{FEFF}a: 1\xE2\x82", 'Incomplete UTF-8', 7];
		yield 'BOM before a control wins' => ["\u{FEFF}a: 1\x01", 'A byte order mark', 0];
		yield 'first control wins' => ["\x01\x02a: 1", 'A raw control character (U+0001)', 0];
	}

	/** @param non-empty-string $reason */
	#[Test]
	#[DataProvider('globalErrors')]
	public function combinedCharacterChecksPreserveErrorPrecedence(string $source, string $reason, int $offset): void
	{
		foreach ([Soml::validate(...), Soml::format(...)] as $operation) {
			try {
				$result = $operation($source);
				$this->fail('Invalid characters were accepted');
			} catch (DecodeException $exception) {
				$this->assertStringStartsWith($reason, $exception->reason);
				$this->assertSame($offset, $exception->offset);
			}
		}
	}

	/** @return iterable<string, array{string, int|float}> */
	public static function decimalSeparators(): iterable
	{
		yield 'integer' => ['1_2_3', 123];
		yield 'fraction' => ['1_2.3_4', 12.34];
		yield 'negative scientific' => ['-1_2.3_4e2', -1234.0];
		yield 'negative exponent' => ['1.2_5e-2', 0.0125];
		yield 'separated exponent' => ['1_0e1_0', 1e11];
		yield 'zero fraction' => ['0.0_0', 0.0];
		yield 'zero exponent' => ['1_2e0', 12.0];
		yield 'whole float' => ['1_2.0', 12.0];
	}

	#[Test]
	#[DataProvider('decimalSeparators')]
	public function nativeDigitRunsStopAtTheDecimalAndExponentBoundaries(string $source, int|float $expected): void
	{
		$this->assertSame($expected, Soml::decodeValue($source));
		Soml::validate('value: ' . $source);
		$this->assertSame('value: ' . $source . "\n", Soml::format('value: ' . $source));
	}
}
