<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Soml;

final class NativeScanningTest extends TestCase
{
	/** @return iterable<string, array{string, non-empty-string, int}> */
	public static function diagnosticBoundaries(): iterable
	{
		foreach ([0, 1, 997, 998, 999, 1000] as $length) {
			yield 'dotted suffix ' . $length => ['a.' . str_repeat('b', $length) . ': 1', $length > 998 || $length === 0 ? 'A key cannot contain “.” unless it is quoted' : 'A bare key cannot contain “.”', 1];
			$colonReason = match (true) {
				$length === 0 => 'Expected a value, but found “:”',
				$length > 997 => 'Expected a value, but found the key',
				default => 'A key that contains “:” must be quoted',
			};
			yield 'colon suffix ' . $length => ['a:' . str_repeat('b', $length) . ': x', $colonReason, $length === 0 || $length > 997 ? 2 : 0];
		}

		yield 'dot before a non-bare character' => ['a.b😀: 1', 'A key cannot contain “.” unless it is quoted', 1];
		yield 'colon at end of input' => ['a:b:', 'A key that contains “:” must be quoted', 0];
		yield 'colon before a line break' => ["a:b:\n", 'A key that contains “:” must be quoted', 0];
		yield 'colon before a tab' => ["a:b:\ttrue", 'A key that contains “:” must be quoted', 0];
		yield 'dense colons at the exact bound' => ['a:b' . str_repeat(':', 997) . ' true', 'A key that contains “:” must be quoted', 0];
		yield 'dense colons beyond the bound' => ['a:b' . str_repeat(':', 998) . ' true', 'Expected a value, but found the key', 2];
		yield 'quoted key cannot merge with the value' => ["'a':b: true", 'Expected a value, but found the key', 4];
	}

	/** @param non-empty-string $reason */
	#[Test]
	#[DataProvider('diagnosticBoundaries')]
	public function diagnosticsKeepTheSameBoundariesAndErrorPositions(string $source, string $reason, int $offset): void
	{
		try {
			Soml::validate($source);
			$this->fail('An invalid key was accepted');
		} catch (DecodeException $exception) {
			$this->assertStringStartsWith($reason, $exception->reason);
			$this->assertSame($offset, $exception->offset);
		}
	}

	#[Test]
	public function removingTheLastItemSkipsBlankTriviaAndPreservesStandaloneComments(): void
	{
		foreach (["# retained\n", "/* retained */\n", "/* first\nsecond */\n"] as $comment) {
			$gap = str_repeat(" \t\n", 100_000);
			$this->assertSame("[\n1,\n" . $gap . $comment . ']', Soml::edit("[\n1,\n2\n" . $gap . $comment . ']', [1]));
		}
	}
}
