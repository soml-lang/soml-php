<?php

declare(strict_types=1);

/**
Process CPU time in nanoseconds, excluding time spent waiting for other processes.
*/
function cpuTime(): float
{
	$usage = getrusage();

	if ($usage === false || !is_int($usage['ru_utime.tv_sec']) || !is_int($usage['ru_stime.tv_sec']) || !is_int($usage['ru_utime.tv_usec']) || !is_int($usage['ru_stime.tv_usec'])) {
		throw new RuntimeException('Process CPU usage is unavailable on this platform');
	}

	return ($usage['ru_utime.tv_sec'] + $usage['ru_stime.tv_sec']) * 1e9 + ($usage['ru_utime.tv_usec'] + $usage['ru_stime.tv_usec']) * 1e3;
}

/**
Warm up and calibrate geometrically, then report CPU nanoseconds per operation. The run count is bounded even for a timer that cannot resolve the operation. Sorting the independent samples gives a median as well as the least interrupted batch.

@param \Closure(): mixed $run
@return array{minimum: float, median: float, maximum: float, runs: int, samples: int}
*/
function measure(Closure $run, int $samples = 7, int $batchNanoseconds = 100_000_000): array
{
	if ($samples < 1 || $samples > 100 || $batchNanoseconds < 1 || $batchNanoseconds > 1_000_000_000) {
		throw new InvalidArgumentException('Use 1 to 100 samples and a batch of 1 to 1000000000 nanoseconds');
	}

	$runs = 1;

	while (true) {
		$start = cpuTime();

		for ($index = 0; $index < $runs; $index++) {
			$run();
		}

		$elapsed = cpuTime() - $start;

		if ($elapsed >= $batchNanoseconds || $runs >= 1_048_576) {
			break;
		}

		$runs *= 2;
	}

	// Slow comparison parsers already consume an entire batch in one operation.
	if ($elapsed >= $batchNanoseconds && $runs === 1) {
		$time = (float) $elapsed;
		return ['minimum' => $time, 'median' => $time, 'maximum' => $time, 'runs' => 1, 'samples' => 1];
	}

	$times = [];

	for ($sample = 0; $sample < $samples; $sample++) {
		$start = cpuTime();

		for ($index = 0; $index < $runs; $index++) {
			$run();
		}

		$times[] = (cpuTime() - $start) / $runs;
	}

	sort($times, SORT_NUMERIC);
	$middle = intdiv($samples, 2);
	$median = $samples % 2 === 0 ? ($times[$middle - 1] + $times[$middle]) / 2 : $times[$middle];
	return ['minimum' => $times[0], 'median' => $median, 'maximum' => $times[$samples - 1], 'runs' => $runs, 'samples' => $samples];
}

/**
Display a CPU duration using a locale-independent decimal spelling.
*/
function formatTime(float $nanoseconds): string
{
	return match (true) {
		$nanoseconds >= 1e9 => sprintf('%.3h s', $nanoseconds / 1e9),
		$nanoseconds >= 1e6 => sprintf('%.3h ms', $nanoseconds / 1e6),
		default => sprintf('%.3h µs', $nanoseconds / 1e3),
	};
}
