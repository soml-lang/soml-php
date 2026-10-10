<?php

declare(strict_types=1);

/*
Times reading and writing the shared fixtures in `fixtures/` with soml-php and with the most used JSON and TOML libraries of PHP, and prints a Markdown table of the time per document.

	composer install
	php -d opcache.enable_cli=1 benchmark.php

Each cell is CPU time, the fastest of several batches, which is the measurement least disturbed by other processes. A batch repeats the work until it takes at least 100 ms. Each library writes the value that it read, in its own types.
*/

require __DIR__ . '/vendor/autoload.php';

// internal/toml needs more than the default 128 MB for the largest fixture.
ini_set('memory_limit', '4G');

use Devium\Toml\Toml as DeviumToml;
use Internal\Toml\Toml as InternalToml;
use Soml\Soml;

const FIXTURES = ['service', 'platform', 'earthquakes'];

$fixtures = array_slice($argv, 1) ?: FIXTURES;

foreach ($fixtures as $fixture) {
	if (!in_array($fixture, FIXTURES, true)) {
		fwrite(STDERR, "Choose fixtures from service, platform, and earthquakes.\n");
		exit(1);
	}
}
// devium/toml takes time in proportion to the square of the size, so the largest fixture would take many minutes.
const DEVIUM_MAXIMUM_BYTES = 1_000_000;

$jsonFlags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

/*
The libraries, each with its format, a reader, and a writer of what the reader gives.
*/
$libraries = [
	'soml-php' => ['soml', static fn (string $text) => Soml::decode($text), static fn ($value) => Soml::encode($value)],
	'ext/json' => ['json', static fn (string $text) => json_decode($text, flags: JSON_THROW_ON_ERROR), static fn ($value) => json_encode($value, $jsonFlags)],
	'internal/toml' => ['toml', static fn (string $text) => InternalToml::parseToArray($text), static fn ($value) => (string) InternalToml::encode($value)],
	'devium/toml' => ['toml', static fn (string $text) => DeviumToml::decode($text, asFloat: true), static fn ($value) => DeviumToml::encode($value)],
];

/*
The CPU time of the process in nanoseconds, which other processes on a busy machine change much less than the time on the clock.

The CPU time of one run is calibrated and sampled by the shared measurement functions.
*/
require __DIR__ . '/measure.php';

$rows = [];

foreach (['Read', 'Write'] as $operation) {
	foreach ($fixtures as $fixture) {
		$cells = [];

		foreach ($libraries as $name => [$format, $read, $write]) {
			$text = file_get_contents(__DIR__ . "/fixtures/{$fixture}.{$format}");

			if ($text === false) {
				throw new RuntimeException("Cannot read fixture {$fixture}.{$format}");
			}

			if ($name === 'devium/toml' && strlen($text) > DEVIUM_MAXIMUM_BYTES) {
				$cells[] = '';
				continue;
			}

			try {
				// Read once before timing, which also checks that the library accepts the fixture.
				$value = $read($text);
				$nanoseconds = $operation === 'Read' ? measure(static fn () => $read($text)) : measure(static fn () => $write($value));
				$cells[] = formatTime($nanoseconds['minimum']);
			} catch (Throwable $throwable) {
				fwrite(STDERR, "{$name} cannot {$operation} {$fixture}: {$throwable->getMessage()}\n");
				$cells[] = 'fails';
			}
		}

		$row = "| {$operation} {$fixture} | " . implode(' | ', $cells) . ' |';
		$rows[] = $row;
		fwrite(STDERR, $row . "\n");
	}
}

echo '| | ' . implode(' | ', array_keys($libraries)) . " |\n";
echo '|---|' . str_repeat('--:|', count($libraries)) . "\n";
echo implode("\n", $rows), "\n";
echo "\nPHP ", PHP_VERSION, ', opcache ', ini_get('opcache.enable_cli') === '1' ? 'on' : 'off', ', JIT ', in_array(ini_get('opcache.jit'), ['', '0', 'off', 'disable'], true) || ini_get('opcache.enable_cli') !== '1' ? 'off' : ini_get('opcache.jit'), "\n";
