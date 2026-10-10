<?php

declare(strict_types=1);

/**
Validate the shared reproducibility arguments before allocating cases or starting the reference runtime.

@param list<string> $arguments
@return array{int, positive-int}
*/
function differentialArguments(array $arguments, int $defaultCount): array
{
	if (count($arguments) > 3) {
		fwrite(STDERR, "Usage: php {$arguments[0]} [seed] [count]\n");
		exit(1);
	}

	$seed = filter_var($arguments[1] ?? '1', FILTER_VALIDATE_INT);

	if ($seed === false) {
		fwrite(STDERR, "The seed must be an integer.\n");
		exit(1);
	}

	$count = filter_var($arguments[2] ?? $defaultCount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

	if ($count === false) {
		fwrite(STDERR, "The case count must be a positive integer.\n");
		exit(1);
	}

	return [$seed, $count];
}
