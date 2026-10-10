<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DifferentialTest extends TestCase
{
	/** @return iterable<string, array{string, string}> */
	public static function invalidCounts(): iterable
	{
		foreach (['fuzz', 'respell', 'floats'] as $fuzzer) {
			foreach (['0', '-1', 'invalid', '1.5', '5oops', '99999999999999999999999999999999'] as $count) {
				yield "{$fuzzer}: {$count}" => [$fuzzer, $count];
			}
		}
	}

	#[Test]
	#[DataProvider('invalidCounts')]
	public function invalidCaseCountsCannotReportSuccess(string $fuzzer, string $count): void
	{
		$command = escapeshellarg(\PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/differential/{$fuzzer}.php") . ' 1 ' . escapeshellarg($count);
		exec($command . ' 2>&1', $output, $status);

		$this->assertSame(1, $status);
		$this->assertSame(['The case count must be a positive integer.'], $output);
	}
}
