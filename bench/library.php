<?php

declare(strict_types=1);

use Soml\Decoder;
use Soml\Duration;
use Soml\Encoder;
use Soml\Instant;
use Soml\Soml;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/measure.php';

/**
Build benchmarks from the shared fixtures and representative scalar operations.

@return Generator<string, Closure(): mixed>
*/
function benchmarkCases(string $filter): Generator
{
	$operations = ['decode', 'decode-associative', 'validate', 'canonicalize', 'format', 'encode', 'encode-canonical', 'encode-value', 'encode-inline', 'get', 'edit-noop', 'edit-replace', 'edit-append', 'edit-remove'];

	foreach (['service', 'platform', 'earthquakes'] as $fixture) {
		$selected = array_filter($operations, static fn (string $operation): bool => str_contains("{$fixture}/{$operation}", $filter));

		if ($selected === []) {
			continue;
		}

		$text = file_get_contents(__DIR__ . "/fixtures/{$fixture}.soml");

		if ($text === false) {
			throw new RuntimeException("Cannot read fixture {$fixture}");
		}

		$value = Soml::decode($text);
		$members = $value instanceof stdClass ? get_object_vars($value) : $value;
		$firstKey = array_key_first($members);

		if ($firstKey === null) {
			throw new LogicException('The shared benchmark fixtures must contain entries');
		}

		$path = [$value instanceof stdClass ? (string) $firstKey : $firstKey];
		$original = $members[$firstKey];
		$replacement = $original === true ? false : true;
		$appendPath = [$value instanceof stdClass ? '__benchmark__' : count($value)];

		foreach ($selected as $operation) {
			yield "{$fixture}/{$operation}" => match ($operation) {
				'decode' => static fn () => Soml::decode($text),
				'decode-associative' => static fn () => Soml::decode($text, associative: true),
				'validate' => static fn () => Soml::validate($text),
				'canonicalize' => static fn () => Soml::canonicalize($text),
				'format' => static fn () => Soml::format($text),
				'encode' => static fn () => Soml::encode($value),
				'encode-canonical' => static fn () => Soml::encode($value, canonical: true),
				'encode-value' => static fn () => Soml::encodeValue($value),
				'encode-inline' => static fn () => Soml::encodeValue($value, oneLine: true),
				'get' => static fn () => Soml::get($value, $path),
				'edit-noop' => static fn () => Soml::edit($text, $path, $original),
				'edit-replace' => static fn () => Soml::edit($text, $path, $replacement),
				'edit-append' => static fn () => Soml::edit($text, $appendPath, true),
				'edit-remove' => static fn () => Soml::edit($text, $path),
			};
		}

		unset($text, $value, $members, $original, $replacement);
	}

	$instant = new Instant(1_800_000_000, 999_999_999);
	$earlierInstant = new Instant(1_799_999_999);
	$duration = Duration::fromMinutes(90);
	$nanosecond = new Duration(1);
	$cases = [];
	$cases['values/instant-parse'] = static fn () => Instant::parse('2026-10-13T14:00:00.123456789+02:00');
	$cases['values/instant-format'] = static fn () => (string) $instant;
	$cases['values/instant-shift'] = static fn () => $instant->plus($duration);
	$cases['values/instant-difference'] = static fn () => $instant->difference($earlierInstant);
	$cases['values/epoch-nanoseconds'] = static fn () => $instant->toEpochNanoseconds();
	$cases['values/duration-parse'] = static fn () => Duration::parse('1h30m0.000000001s');
	$cases['values/duration-format'] = static fn () => (string) $duration;
	$cases['values/duration-arithmetic'] = static fn () => $duration->multipliedBy(2)->plus($nanosecond);
	$cases['values/float-format'] = static fn () => Encoder::formatFloat(5e-324);
	$cases['values/large-integer'] = static fn () => Soml::decodeValue('9223372036854775807');
	$cases['values/escaped-string'] = static fn () => Soml::decodeValue('"it\'s\\n\\u{1f680}"');

	foreach ($cases as $name => $run) {
		if (str_contains($name, $filter)) {
			yield $name => $run;
		}
	}
}

$samples = 7;
$batchMilliseconds = 100;
$filter = '';
$json = false;

try {
	$arguments = $argv ?? [];
	$index = 1;

	while (isset($arguments[$index])) {
		$option = $arguments[$index];
		$index++;

		if ($option === '--help') {
			echo "Usage: php bench/library.php [--samples N] [--batch-ms N] [--filter TEXT] [--json]\n";
			exit(0);
		}

		if ($option === '--json') {
			$json = true;
			continue;
		}

		if ($option === '--filter') {
			$filter = $arguments[$index++] ?? throw new InvalidArgumentException('--filter requires text');
			continue;
		}

		if ($option !== '--samples' && $option !== '--batch-ms') {
			throw new InvalidArgumentException('Unknown option ' . $option);
		}

		$value = $arguments[$index++] ?? null;
		$parsed = $value === null ? false : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $option === '--samples' ? 100 : 1000]]);

		if ($parsed === false) {
			throw new InvalidArgumentException($option . ' requires an integer in the supported range');
		}

		if ($option === '--samples') {
			$samples = $parsed;
		} else {
			$batchMilliseconds = $parsed;
		}
	}

	$results = [];

	foreach (benchmarkCases($filter) as $name => $run) {
		$timing = measure($run, $samples, $batchMilliseconds * 1_000_000);
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$result = $run();
		$peak = max(0, memory_get_peak_usage() - $before);
		unset($result);
		$results[] = ['name' => $name, ...$timing, 'peakBytes' => $peak];
		unset($run);
	}

	if ($results === []) {
		throw new InvalidArgumentException('No benchmark names matched the filter');
	}

	$environment = ['php' => PHP_VERSION, 'integerBits' => PHP_INT_SIZE * 8, 'opcache' => ini_get('opcache.enable_cli'), 'jit' => ini_get('opcache.jit'), 'jitBuffer' => ini_get('opcache.jit_buffer_size'), 'pcre' => PCRE_VERSION, 'batchMilliseconds' => $batchMilliseconds];

	if ($json) {
		echo json_encode(['environment' => $environment, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
		exit(0);
	}

	echo "| Operation | Minimum | Median | Maximum | Peak bytes |\n|---|--:|--:|--:|--:|\n";

	foreach ($results as $result) {
		echo '| ', $result['name'], ' | ', formatTime($result['minimum']), ' | ', formatTime($result['median']), ' | ', formatTime($result['maximum']), ' | ', $result['peakBytes'], " |\n";
	}

	echo "\nPHP ", $environment['php'], ', ', $environment['integerBits'], "-bit; CPU time per operation.\n";
} catch (Throwable $exception) {
	fwrite(STDERR, Decoder::describeText($exception->getMessage()) . "\n");
	exit(1);
}
