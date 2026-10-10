<?php

declare(strict_types=1);

// Compares the float spelling with the JavaScript reference on random bit patterns and on decimal values with few digits: `php floats.php <seed> <count>`.

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../tools/reference.php';
require_once __DIR__ . '/../../tools/differential.php';

use Soml\Encoder;

[$seed, $count] = differentialArguments($argv, 100000);

$random = new Random\Randomizer(new Random\Engine\Mt19937($seed));
$floats = [];

while (count($floats) < $count) {
	$float = match ($random->getInt(0, 2)) {
		0 => unpack('E', $random->getBytes(8))[1],
		1 => (float) ($random->getInt(1, 999_999) . 'e' . $random->getInt(-330, 310)),
		default => $random->getInt(-1_000_000, 1_000_000) / (10 ** $random->getInt(0, 8)),
	};

	if (!is_nan($float)) {
		$floats[] = $float;
	}
}

$expected = runReference(['node', __DIR__ . '/floats.mjs'], array_map(static fn (float $float): string => bin2hex(pack('E', $float)), $floats));
$mismatches = 0;

foreach ($floats as $index => $float) {
	$actual = Encoder::formatFloat($float);

	if ($actual !== $expected[$index]) {
		$mismatches++;

		if ($mismatches <= 15) {
			echo bin2hex(pack('E', $float)), ": JS {$expected[$index]}, PHP {$actual}\n";
		}
	}
}

echo "{$mismatches} mismatches in {$count} floats\n";
exit($mismatches === 0 ? 0 : 1);
