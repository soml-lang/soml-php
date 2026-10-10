<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../tools/reference.php';

final class DevelopmentHardeningTest extends TestCase
{
	#[Test]
	public function referenceTimeoutCannotReportACompletedResult(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('timed out');
		\runReference([\PHP_BINARY, '-r', 'usleep(100000); file_put_contents($argv[2], \'["late"]\');'], ['input'], 0.01);
	}

	#[Test]
	public function referenceOutputLimitIsCheckedBeforeJsonDecoding(): void
	{
		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('byte limit');
		\runReference([\PHP_BINARY, '-r', 'file_put_contents($argv[2], \'["\' . str_repeat("x", 128) . \'"]\');'], ['input'], 300, 32);
	}

	#[Test]
	public function missingReferenceOutputHasAClearFailureWithoutPhpWarnings(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Cannot read the reference output');
		\runReference([\PHP_BINARY, '-r', 'exit(0);'], ['input']);
	}

	#[Test]
	public function malformedFuzzerSeedCannotSilentlyBecomeZero(): void
	{
		foreach (['fuzz', 'respell', 'floats'] as $fuzzer) {
			$process = proc_open([\PHP_BINARY, __DIR__ . "/differential/{$fuzzer}.php", 'invalid', '1'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

			if (!is_resource($process)) {
				throw new \RuntimeException('Cannot start the differential fuzzer');
			}

			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$status = proc_close($process);
			$this->assertSame(1, $status, (string) $output);
			$this->assertSame("The seed must be an integer.\n", $error);
		}
	}

	#[Test]
	public function referenceOutputAcceptsTheExactByteLimit(): void
	{
		$result = \runReference([\PHP_BINARY, '-r', 'file_put_contents($argv[2], \'["é"]\');'], ['input'], maximumOutputBytes: 6);
		$this->assertSame(['é'], $result);
	}

	/** @return iterable<string, array{float}> */
	public static function invalidTimeouts(): iterable
	{
		yield 'zero' => [0.0];
		yield 'negative' => [-1.0];
		yield 'infinite' => [\INF];
		yield 'not a number' => [\NAN];
	}

	#[Test]
	#[DataProvider('invalidTimeouts')]
	public function invalidProcessTimeoutsFailBeforeStartingWork(float $timeout): void
	{
		$this->expectException(\InvalidArgumentException::class);
		\runProcess([\PHP_BINARY, '-r', 'exit(99);'], $timeout);
	}

	#[Test]
	public function referenceCommandsCannotConsumeInteractiveInput(): void
	{
		$result = \runReference([\PHP_BINARY, '-r', 'file_put_contents($argv[2], json_encode([stream_get_contents(STDIN)], JSON_THROW_ON_ERROR));'], ['input']);
		$this->assertSame([''], $result);
	}
}
