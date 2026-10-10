<?php

declare(strict_types=1);

// Differential fuzzing against the JavaScript reference: `php fuzz.php <seed> <count>`.

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../tools/reference.php';
require_once __DIR__ . '/../../tools/differential.php';

use Soml\DecodeException;
use Soml\Soml;

[$seed, $count] = differentialArguments($argv, 20000);
$random = new Random\Randomizer(new Random\Engine\Mt19937($seed));
$corpus = [];

$paths = glob(__DIR__ . '/../conformance/{valid,invalid}/*/*.soml', GLOB_BRACE);

if ($paths === false || $paths === []) {
	throw new RuntimeException('The differential corpus must contain conformance fixtures');
}

foreach ($paths as $path) {
	$text = file_get_contents($path);

	if ($text === false) {
		throw new RuntimeException("Cannot read corpus fixture {$path}");
	}

	$corpus[] = $text;
}

$tokens = ['{', '}', '[', ']', ':', ',', "\n", ' ', "\t", '#', '/*', '*/', "'", '"', "'''", '"""', '\\', '\\u{', 'a', 'key', '404', '-', '+', '.', '0', '1', '9', '0x', '0o', '0b', 'e', 'E', '_', 'h', 'm', 's', 'ms', 'us', 'ns', 'T', 'Z', 'z', '2026-09-19', 'T14:00:00', '+02:00', 'true', 'false', 'null', 'infinity', 'nan', '1.5', '1e5', 'é', '😀', "\u{A0}", "\u{200B}", "\u{2028}", "\u{FEFF}", "\x00", "\r", "\x7F", "\xFF", "\xC3", '|', '//', '=', '$', 'µs', '1d', '00', '-0', '0.', '.5', 'x', 'y z'];

$pick = static fn (array $items) => $items[$random->getInt(0, count($items) - 1)];

$mutate = static function (string $text) use ($random, $pick, $tokens): string {
	for ($mutations = $random->getInt(1, 3); $mutations > 0; $mutations--) {
		$length = strlen($text);
		$offset = $length === 0 ? 0 : $random->getInt(0, $length);

		$text = match ($random->getInt(0, 4)) {
			0 => substr($text, 0, $offset) . $pick($tokens) . substr($text, $offset),
			1 => substr($text, 0, $offset) . substr($text, $offset + $random->getInt(1, 4)),
			2 => substr($text, 0, $offset) . chr($random->getInt(0, 255)) . substr($text, $offset),
			3 => substr($text, 0, $offset) . substr($text, max(0, $offset - $random->getInt(1, 20)), $random->getInt(1, 20)) . substr($text, $offset),
			default => substr($text, 0, $offset) . $pick($tokens) . $pick($tokens) . substr($text, $offset),
		};
	}

	return $text;
};

$inputs = [];

for ($index = 0; $index < $count; $index++) {
	if ($random->getInt(0, 3) === 0) {
		$text = '';

		for ($length = $random->getInt(1, 20); $length > 0; $length--) {
			$text .= $pick($tokens);
		}

		$inputs[] = $text;
	} else {
		$inputs[] = $mutate($pick($corpus));
	}
}

$outputs = runReference(['node', __DIR__ . '/reference.mjs'], array_map(base64_encode(...), $inputs));
$mismatches = 0;
$validCount = 0;

foreach ($inputs as $index => $input) {
	$expected = $outputs[$index];

	// PHP also sanitizes invisible format characters and Unicode line separators in diagnostics.
	if (isset($expected['reason'])) {
		$expected['reason'] = preg_replace('/[\p{Cf}\p{Zl}\p{Zp}]/u', "\u{FFFD}", $expected['reason']);
	}

	try {
		$actual = ['canonical' => Soml::encode(Soml::decode($input), canonical: true)];
	} catch (DecodeException $exception) {
		$actual = ['reason' => $exception->reason, 'line' => $exception->lineNumber, 'column' => $exception->columnNumber];

		// A key that starts with NUL cannot be a property, which is a documented limit of objects.
		if (str_starts_with($exception->reason, 'A key that starts with U+0000') && isset($expected['canonical'])) {
			continue;
		}
	} catch (Throwable $throwable) {
		$actual = ['crash' => $throwable::class . ': ' . $throwable->getMessage() . ' at ' . $throwable->getFile() . ':' . $throwable->getLine()];
	}

	if (isset($actual['canonical'])) {
		$validCount++;

		try {
			$formatted = Soml::format($input);

			if (Soml::encode(Soml::decode($formatted), canonical: true) !== $actual['canonical']) {
				throw new LogicException('Formatting changed the document value');
			}

			if (Soml::format($formatted) !== $formatted) {
				throw new LogicException('Formatting is not idempotent');
			}
		} catch (Throwable $throwable) {
			$actual = ['crash' => $throwable::class . ': ' . $throwable->getMessage() . ' at ' . $throwable->getFile() . ':' . $throwable->getLine()];
		}
	}

	if ($actual !== $expected) {
		$mismatches++;

		if ($mismatches <= 15) {
			echo "Input: ", json_encode($input, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE), "\n  JS:  ", json_encode($expected, JSON_UNESCAPED_UNICODE), "\n  PHP: ", json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
		}
	}
}

echo "{$mismatches} mismatches in {$count} inputs, of which {$validCount} are valid\n";
exit($mismatches === 0 ? 0 : 1);
