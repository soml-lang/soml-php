<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tools/differential.php';
require_once __DIR__ . '/../../tools/process.php';

differentialArguments($argv, 20_000);

if (!is_file(__DIR__ . '/../../../soml-javascript/source/index.ts')) {
	echo "Skipping differential fuzzers: check out soml-javascript next to this repository.\n";
	exit(0);
}

// Optional seed and count arguments are passed to each fuzzer.
$arguments = array_slice($argv, 1);

foreach (['fuzz', 'respell', 'floats'] as $fuzzer) {
	echo "Running {$fuzzer}\n";
	try {
		$status = runProcess([PHP_BINARY, __DIR__ . "/{$fuzzer}.php", ...$arguments], timeoutSeconds: 900);
	} catch (RuntimeException $exception) {
		fwrite(STDERR, $exception->getMessage() . "\n");
		exit(1);
	}

	if ($status !== 0) {
		exit($status > 0 ? $status : 1);
	}
}
