<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bench/measure.php';
require_once __DIR__ . '/../tools/reference.php';

final class ToolingTest extends TestCase
{
	#[Test]
	public function filteringScalarBenchmarksDoesNotLoadUnrelatedFixtures(): void
	{
		$process = proc_open([\PHP_BINARY, '-d', 'memory_limit=4M', __DIR__ . '/../bench/library.php', '--filter', 'values/instant-format', '--samples', '1', '--batch-ms', '1', '--json'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

		if (!is_resource($process)) {
			throw new \RuntimeException('Cannot start the benchmark');
		}

		try {
			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
		} finally {
			foreach ($pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}

			$status = proc_close($process);
		}

		$this->assertSame(0, $status, $error === false ? '' : $error);
		$this->assertIsString($output);
		$results = json_decode($output, associative: true, flags: \JSON_THROW_ON_ERROR);
		$this->assertIsArray($results);
		$this->assertIsArray($results['results']);
		$this->assertCount(1, $results['results']);
		$this->assertIsArray($results['results'][0]);
		$this->assertSame('values/instant-format', $results['results'][0]['name']);
		$this->assertSame(1, $results['results'][0]['samples']);
	}

	#[Test]
	public function benchmarkReportsAnOrderedDistributionAndActualWork(): void
	{
		$calls = 0;
		$measurement = \measure(static function () use (&$calls): void {
			$calls++;
		}, samples: 4, batchNanoseconds: 100_000);
		$this->assertSame(4, $measurement['samples']);
		$this->assertGreaterThanOrEqual(1, $measurement['runs']);
		$this->assertGreaterThanOrEqual(0.0, $measurement['minimum']);
		$this->assertGreaterThanOrEqual($measurement['minimum'], $measurement['median']);
		$this->assertGreaterThanOrEqual($measurement['median'], $measurement['maximum']);
		$this->assertGreaterThan($measurement['runs'] * $measurement['samples'], $calls);
	}

	#[Test]
	public function slowBenchmarksDoNotPretendToHaveCollectedMultipleSamples(): void
	{
		$calls = 0;
		$measurement = \measure(static function () use (&$calls): void {
			$calls++;
			$start = \cpuTime();

			while (\cpuTime() - $start < 100_000) {
				// Consume CPU time rather than sleep, which the benchmark intentionally excludes.
			}
		}, samples: 7, batchNanoseconds: 1000);
		$this->assertSame(1, $calls);
		$this->assertSame(1, $measurement['samples']);
		$this->assertSame($measurement['minimum'], $measurement['median']);
		$this->assertSame($measurement['median'], $measurement['maximum']);
	}

	/** @return iterable<string, array{int, int}> */
	public static function invalidMeasurementOptions(): iterable
	{
		yield 'zero samples' => [0, 1000];
		yield 'too many samples' => [101, 1000];
		yield 'zero CPU budget' => [1, 0];
		yield 'too large CPU budget' => [1, 1_000_000_001];
	}

	#[Test]
	#[DataProvider('invalidMeasurementOptions')]
	public function invalidBenchmarkOptionsFailBeforeRunningWork(int $samples, int $budget): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$result = \measure(static fn (): never => throw new \LogicException('The callback ran'), $samples, $budget);
	}

	/** @return iterable<string, array{string, class-string<\Throwable>}> */
	public static function invalidReferenceOutput(): iterable
	{
		yield 'malformed JSON' => ['[', \JsonException::class];
		yield 'wrong root' => ['{"0":"ok"}', \UnexpectedValueException::class];
		yield 'truncated results' => ['[]', \UnexpectedValueException::class];
		yield 'extra results' => ['["ok","extra"]', \UnexpectedValueException::class];
	}

	/** @param class-string<\Throwable> $exception */
	#[Test]
	#[DataProvider('invalidReferenceOutput')]
	public function malformedReferenceResultsCannotReportSuccess(string $output, string $exception): void
	{
		$file = $this->referenceScript('file_put_contents($argv[2], ' . var_export($output, true) . ');');

		try {
			$this->expectException($exception);
			$result = \runReference([\PHP_BINARY, $file], ['input']);
		} finally {
			unlink($file);
		}
	}

	#[Test]
	public function referenceProcessFailuresCannotUseAStaleOutput(): void
	{
		$file = $this->referenceScript('file_put_contents($argv[2], \'["ok"]\'); exit(3);');

		try {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage('exited with status 3');
			$result = \runReference([\PHP_BINARY, $file], ['input']);
		} finally {
			unlink($file);
		}
	}

	#[Test]
	public function referenceBridgeUsesPrivateScratchFilesAndLiteralArguments(): void
	{
		$file = $this->referenceScript('$input = json_decode(file_get_contents($argv[1]), flags: JSON_THROW_ON_ERROR); file_put_contents($argv[2], json_encode([[$input, fileperms(dirname($argv[1])) & 0777]], JSON_THROW_ON_ERROR));');

		try {
			$result = \runReference([\PHP_BINARY, $file], ["quote' $(never-run)\n😀"]);
			$this->assertSame([[["quote' $(never-run)\n😀"], 0700]], $result);
		} finally {
			unlink($file);
		}
	}

	/**
	Make a uniquely named PHP reference stub in the system temporary directory, avoiding shell evaluation of its contents.
	*/
	private function referenceScript(string $body): string
	{
		$file = sys_get_temp_dir() . '/soml-reference-' . bin2hex(random_bytes(8)) . ' "quoted".php';
		file_put_contents($file, "<?php\ndeclare(strict_types=1);\n" . $body);
		return $file;
	}
}
