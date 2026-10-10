<?php

declare(strict_types=1);

require_once __DIR__ . '/process.php';

/**
Run a reference process using private, unpredictable scratch files and direct argument passing. A failed process, incomplete write, malformed JSON, or truncated result list cannot be mistaken for a successful comparison. Generated files are retained for debugging.

@param non-empty-list<string> $command
@param list<mixed> $inputs
@return list<mixed>
*/
function runReference(array $command, array $inputs, float $timeoutSeconds = 300, int $maximumOutputBytes = 268_435_456): array
{
	if ($maximumOutputBytes < 1 || $maximumOutputBytes === PHP_INT_MAX) {
		throw new InvalidArgumentException('The output byte limit must be positive and below PHP_INT_MAX');
	}

	$directory = sys_get_temp_dir() . '/soml-reference-' . bin2hex(random_bytes(16));

	if (!mkdir($directory, 0700, true)) {
		throw new RuntimeException('Cannot create the reference scratch directory');
	}

	$input = $directory . '/inputs.json';
	$output = $directory . '/outputs.json';
	$json = json_encode($inputs, JSON_THROW_ON_ERROR);

	if (file_put_contents($input, $json) !== strlen($json)) {
		throw new RuntimeException('Cannot write the complete reference input');
	}

	unset($json);
	$status = runProcess([...$command, $input, $output], $timeoutSeconds);

	if ($status !== 0) {
		throw new RuntimeException("The reference process exited with status {$status}");
	}

	$json = @file_get_contents($output, length: $maximumOutputBytes + 1);

	if ($json === false) {
		throw new RuntimeException('Cannot read the reference output');
	}

	if (strlen($json) > $maximumOutputBytes) {
		throw new UnexpectedValueException('The reference output exceeds the byte limit');
	}

	// Decode associatively for the existing comparison records, while requiring an actual JSON array at the root.
	if (($json[strspn($json, " \t\r\n")] ?? '') !== '[') {
		throw new UnexpectedValueException('The reference output must be a JSON array');
	}

	$results = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);

	if (!is_array($results) || !array_is_list($results) || count($results) !== count($inputs)) {
		throw new UnexpectedValueException('The reference output count must match the input count');
	}

	return $results;
}
