<?php

declare(strict_types=1);

// Writes random values in random legal spellings, and checks that this package and the JavaScript reference both read each document back to the canonical form of the value: `php respell.php <seed> <count>`.

require __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../tools/reference.php';
require_once __DIR__ . '/../../tools/differential.php';

use Soml\Decoder;
use Soml\Duration;
use Soml\Encoder;
use Soml\Instant;
use Soml\Soml;

[$seed, $count] = differentialArguments($argv, 2000);

$random = new Random\Randomizer(new Random\Engine\Mt19937($seed));

$characters = ['a', 'Z', '0', '_', '-', '.', ' ', "'", '"', '\\', "\n", "\t", "\u{0}", "\u{1}", "\u{1F}", "\u{7F}", "\u{80}", "\u{A0}", 'é', "\u{2028}", "\u{FEFF}", '😀', "\u{10FFFF}", '#', '/*', '*/', ':', ',', '{', '}', '[', ']', "'''", '"""', 'x', 'y', '  ', "\t\t"];

$chance = static fn (int $percent): bool => $random->getInt(1, 100) <= $percent;
$pick = static fn (array $items) => $items[$random->getInt(0, count($items) - 1)];

$randomString = static function () use ($random, $pick, $characters): string {
	$string = '';

	for ($length = $random->getInt(0, 8); $length > 0; $length--) {
		$string .= $pick($characters);
	}

	return $string;
};

$randomValue = static function (int $depth) use (&$randomValue, $random, $chance, $randomString): mixed {
	$kind = $random->getInt($depth > 0 ? 0 : 2, 11);

	if ($kind <= 1) {
		$count = $random->getInt($depth >= 4 ? 2 : 0, 5);

		if ($chance(50)) {
			return array_map(static fn () => $randomValue($depth - 1), $count === 0 ? [] : range(1, $count));
		}

		$object = new stdClass();

		for (; $count > 0; $count--) {
			$key = $chance(30) ? $randomString() : substr($random->shuffleBytes('abcdefghij-_0123'), 0, $random->getInt(1, 5));

			if (!str_starts_with($key, "\0")) {
				$object->{$key} = $randomValue($depth - 1);
			}
		}

		return $object;
	}

	return match ($kind) {
		2, 3 => $randomString(),
		4 => $chance(50) ? $random->getInt(-1000, 100_000) : $random->getInt(PHP_INT_MIN, PHP_INT_MAX) >> $random->getInt(0, 62),
		5 => match ($random->getInt(0, 3)) {
			0 => $random->getInt(-100_000, 100_000) / 16,
			1 => (float) ($random->getInt(1, 99_999) . 'e' . $random->getInt(-30, 30)),
			2 => $chance(50) ? INF : -INF,
			default => (static function () use ($random): float {
				do {
					$float = unpack('E', $random->getBytes(8))[1];
				} while (is_nan($float));

				return $float;
			})(),
		},
		6 => $chance(50),
		7 => null,
		8, 9 => new Instant($random->getInt(Instant::MIN_EPOCH_SECONDS + 86_400, Instant::MAX_EPOCH_SECONDS - 86_400), $chance(50) ? 0 : $random->getInt(0, 999_999_999)),
		default => new Duration($chance(30) ? $random->getInt(PHP_INT_MIN, PHP_INT_MAX) >> $random->getInt(0, 62) : $random->getInt(-100, 100) * $random->getInt(1, 3) * [1, Duration::MILLISECOND, Duration::SECOND, Duration::MINUTE][$random->getInt(0, 3)]),
	};
};

// Underscores between some of the digits.
$withUnderscores = static function (string $digits) use ($chance): string {
	if (!$chance(30) || strlen($digits) < 2) {
		return $digits;
	}

	$result = $digits[0];

	for ($index = 1; $index < strlen($digits); $index++) {
		$result .= ($chance(30) ? '_' : '') . $digits[$index];
	}

	return $result;
};

$escape = static function (string $string, bool $isBlock = false) use ($chance): string {
	$result = '';

	foreach (preg_split('//u', $string, -1, PREG_SPLIT_NO_EMPTY) as $character) {
		$codePoint = Decoder::codePointOf($character);

		$result .= match (true) {
			$character === '\\' => '\\\\',
			$character === '"' => $isBlock && $chance(50) ? '"' : '\"',
			$character === "\n" => $isBlock ? "\n" : '\n',
			$character === "\t" => $chance(50) ? '\t' : ($chance(50) ? "\t" : '\u{9}'),
			$codePoint < 0x20 || $codePoint === 0x7F || $chance(5) => '\u{' . dechex($codePoint) . '}',
			default => $character,
		};
	}

	return $result;
};

// A block string, or `null` when the value cannot be one: its first and last lines must have content, and a line of only spaces and tabs would become empty.
$blockString = static function (string $string, string $indent) use ($random, $chance, $escape): ?string {
	$lines = explode("\n", $string);

	if (trim($lines[0], " \t") === '' || trim($lines[count($lines) - 1], " \t") === '') {
		return null;
	}

	$isLiteral = $chance(50) && strcspn($string, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0B\x0C\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F") === strlen($string);

	foreach ($lines as $line) {
		if ($line !== '' && trim($line, " \t") === '' && $isLiteral) {
			return null;
		}
	}

	$quote = $isLiteral ? "'" : '"';
	$content = [];
	$longestRun = 2;

	foreach ($lines as $line) {
		if ($isLiteral) {
			$text = $line;
		} else {
			// A line of only spaces and tabs is written with escapes, so that it is not blank.
			$text = trim($line, " \t") === '' && $line !== '' ? str_replace([' ', "\t"], ['\u{20}', '\t'], $line) : $escape($line, true);
			$text = str_replace("\n", '\n', $text);
		}

		if (preg_match('/^[ \t]*(' . preg_quote($quote, '/') . '+)/', $text, $match) === 1) {
			$longestRun = max($longestRun, strlen($match[1]));
		}

		$content[] = $text === '' ? ($chance(30) ? $indent . ' ' : '') : $indent . $text;
	}

	$delimiter = str_repeat($quote, $longestRun + 1);
	$blankBefore = $chance(20) ? "\n" : '';
	$blankAfter = $chance(20) ? "\n" : '';
	return "{$delimiter}\n{$blankBefore}" . implode("\n", $content) . "\n{$blankAfter}{$indent}{$delimiter}";
};

$writeString = static function (string $string, string $indent, bool $isKey) use ($chance, $escape, $blockString): string {
	if ($isKey && Soml::isBareKey($string) && $chance(80)) {
		return $string;
	}

	if (!$isKey && str_contains($string, "\n") && $chance(60)) {
		$block = $blockString($string, $indent);

		if ($block !== null) {
			return $block;
		}
	}

	if (strcspn($string, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x7F'") === strlen($string) && $chance(60)) {
		return "'{$string}'";
	}

	return '"' . $escape($string) . '"';
};

$writeInteger = static function (int $value) use ($random, $chance, $withUnderscores): string {
	if ($value >= 0 && $chance(40)) {
		[$prefix, $digits] = match ($random->getInt(0, 2)) {
			0 => ['0x', strtoupper(dechex($value))],
			1 => ['0o', decoct($value)],
			default => ['0b', decbin($value)],
		};

		return $prefix . $withUnderscores(str_repeat('0', $chance(30) ? $random->getInt(1, 3) : 0) . $digits);
	}

	return ($value < 0 ? '-' : '') . $withUnderscores(ltrim((string) $value, '-'));
};

$writeFloat = static function (float $value) use ($random, $chance, $withUnderscores): string {
	$canonical = Encoder::formatFloat($value);

	if (is_infinite($value) || !$chance(70)) {
		return $canonical;
	}

	// The digits and the exponent of the value, from its shortest spelling.
	$sign = str_starts_with($canonical, '-') ? '-' : '';
	$text = ltrim($canonical, '-');
	$exponent = 0;

	if (str_contains($text, 'e')) {
		[$text, $exponentText] = explode('e', $text);
		$exponent = (int) $exponentText;
	}

	[$integerPart, $fraction] = explode('.', $text . (str_contains($text, '.') ? '' : '.'));
	$digits = ltrim($integerPart . $fraction, '0');
	$exponent += strlen($integerPart) - (strlen($integerPart . $fraction) - strlen($digits)) - 1;
	$digits = rtrim($digits, '0');

	if ($digits === '') {
		return $chance(50) ? '0.0' : ($chance(50) ? '-0.0' : '0e0');
	}

	$digits .= str_repeat('0', $chance(30) ? $random->getInt(1, 3) : 0);
	// Move the point: the value is 0.`$digits` × 10^($exponent + 1).
	$shift = $random->getInt(1, strlen($digits));
	$newExponent = $exponent + 1 - $shift;
	$mantissa = $withUnderscores(substr($digits, 0, $shift)) . ($shift < strlen($digits) ? '.' . $withUnderscores(substr($digits, $shift)) : '');

	if ($newExponent === 0 && str_contains($mantissa, '.')) {
		return $sign . $mantissa;
	}

	return $sign . $mantissa . 'e' . $newExponent;
};

$writeInstant = static function (Instant $instant) use ($random, $chance): string {
	$offsetMinutes = $chance(50) ? 0 : $random->getInt(-23 * 60 - 59, 23 * 60 + 59);
	$local = new Instant($instant->epochSeconds + $offsetMinutes * 60, $instant->nanoseconds);
	$text = substr((string) $local, 0, 19);
	$fraction = $instant->nanoseconds === 0 ? '' : rtrim(str_pad((string) $instant->nanoseconds, 9, '0', STR_PAD_LEFT), '0');

	if ($fraction !== '' || $chance(20)) {
		$fraction = '.' . str_pad($fraction === '' ? '0' : $fraction, $random->getInt(max(1, strlen($fraction)), 9), '0');
	}

	if ($offsetMinutes === 0 && $chance(70)) {
		return "{$text}{$fraction}Z";
	}

	$absolute = abs($offsetMinutes);
	return $text . $fraction . ($offsetMinutes < 0 ? '-' : '+') . sprintf('%02d:%02d', intdiv($absolute, 60), $absolute % 60);
};

$writeDuration = static function (Duration $duration) use ($random, $chance): string {
	$nanoseconds = $duration->nanoseconds;

	if ($nanoseconds === 0) {
		return ['0s', '0ns', '0h', '0m0s', '0.0s'][$random->getInt(0, 4)];
	}

	// The magnitude, which is written with a sign in front. The smallest duration has no positive int, so it is written in canonical form.
	if ($nanoseconds === PHP_INT_MIN || !$chance(80)) {
		return (string) $duration;
	}

	$magnitude = abs($nanoseconds);
	$units = ['h' => Duration::HOUR, 'm' => Duration::MINUTE, 's' => Duration::SECOND, 'ms' => Duration::MILLISECOND, 'us' => Duration::MICROSECOND, 'ns' => 1];
	$text = '';
	$rest = $magnitude;

	foreach ($units as $unit => $size) {
		if ($rest === 0) {
			break;
		}

		$isLast = $unit === 'ns' || $chance(25);

		if ($isLast) {
			// The rest in this unit, with a fraction when it is not whole, for the units that are a power of ten of nanoseconds.
			if ($rest % $size === 0) {
				$text .= intdiv($rest, $size) . $unit;
			} elseif (in_array($unit, ['s', 'ms', 'us'], true)) {
				$places = strlen((string) $size) - 1;
				$text .= intdiv($rest, $size) . '.' . rtrim(str_pad((string) ($rest % $size), $places, '0', STR_PAD_LEFT), '0') . $unit;
			} else {
				continue;
			}

			$rest = 0;
			break;
		}

		$whole = intdiv($rest, $size);

		if ($whole > 0 && $chance(70)) {
			$part = $chance(30) ? $random->getInt(0, $whole) : $whole;

			if ($part > 0) {
				$text .= "{$part}{$unit}";
				$rest -= $part * $size;
			}
		}
	}

	if ($rest !== 0) {
		$text .= "{$rest}ns";
	}

	return ($nanoseconds < 0 ? '-' : '') . $text;
};

$comment = static fn (): string => $pick([' # note', ' /* note */', '', '', '']);

$writeValue = static function (mixed $value, string $indent) use (&$writeValue, $random, $chance, $pick, $writeString, $writeInteger, $writeFloat, $writeInstant, $writeDuration, $comment): string {
	if ($value instanceof stdClass || is_array($value)) {
		$isObject = $value instanceof stdClass;
		$members = $isObject ? get_object_vars($value) : $value;
		$open = $isObject ? '{' : '[';
		$close = $isObject ? '}' : ']';

		if ($members === []) {
			return $open . ($chance(20) ? ' /* empty */ ' : '') . $close;
		}

		$keys = array_keys($members);

		if ($isObject && $chance(50)) {
			$keys = $random->shuffleArray($keys);
		}

		$isOneLine = $chance(30);
		$inner = $indent . $pick(["\t", '  ', ' ', "\t\t"]);
		$items = [];

		foreach ($keys as $key) {
			$item = $writeValue($members[$key], $isOneLine ? $indent : $inner);
			$items[] = ($isObject ? $writeString((string) $key, $indent, true) . ':' . ($chance(80) ? ' ' : '') : '') . $item;
		}

		if ($isOneLine && !str_contains(implode('', $items), "\n")) {
			return $open . ($chance(30) ? ' ' : '') . implode($pick([', ', ',', ' , ']), $items) . ($chance(30) ? ',' : '') . $close;
		}

		$lines = [];

		foreach ($items as $index => $item) {
			$isLast = $index === count($items) - 1;
			$lines[] = $inner . ($chance(10) ? '/* before */ ' : '') . $item . ($chance(30) && (!$isLast || $chance(50)) ? ',' : '') . $comment();

			if ($chance(10)) {
				$lines[] = $chance(50) ? '' : "{$inner}# A comment on its own line";
			}
		}

		return "{$open}\n" . implode("\n", $lines) . "\n{$indent}{$close}";
	}

	return match (true) {
		is_string($value) => $writeString($value, $indent, false),
		is_int($value) => $writeInteger($value),
		is_float($value) => $writeFloat($value),
		is_bool($value) => $value ? 'true' : 'false',
		$value === null => 'null',
		$value instanceof Instant => $writeInstant($value),
		$value instanceof Duration => $writeDuration($value),
	};
};

$writeDocument = static function (array|stdClass $value) use ($writeValue, $writeString, $chance, $comment): string {
	$members = $value instanceof stdClass ? get_object_vars($value) : null;

	if ($members === null || $members === [] || $chance(20)) {
		return ($chance(20) ? "# Leading comment\n\n" : '') . $writeValue($value, '') . $comment() . "\n";
	}

	$lines = [];

	foreach ($members as $key => $member) {
		$lines[] = $writeString((string) $key, '', true) . ': ' . $writeValue($member, '') . $comment();

		if ($chance(10)) {
			$lines[] = '';
		}
	}

	return implode("\n", $lines) . "\n";
};

$documents = [];
$expected = [];

for ($index = 0; $index < $count; $index++) {
	do {
		$value = $randomValue(4);
	} while (!is_array($value) && !$value instanceof stdClass);

	$documents[] = $writeDocument($value);
	$expected[] = Soml::encode($value, canonical: true);
}

$outputs = runReference(['node', __DIR__ . '/reference.mjs'], array_map(base64_encode(...), $documents));
$mismatches = 0;

foreach ($documents as $index => $document) {
	try {
		$actual = Soml::encode(Soml::decode($document), canonical: true);
		$formatted = Soml::format($document);

		if (Soml::encode(Soml::decode($formatted), canonical: true) !== $actual) {
			throw new LogicException('Formatting changed the document value');
		}

		if (Soml::format($formatted) !== $formatted) {
			throw new LogicException('Formatting is not idempotent');
		}
	} catch (Throwable $throwable) {
		$actual = $throwable::class . ': ' . $throwable->getMessage();
	}

	$reference = $outputs[$index]['canonical'] ?? json_encode($outputs[$index], JSON_UNESCAPED_UNICODE);

	if ($actual !== $expected[$index] || $reference !== $expected[$index]) {
		$mismatches++;

		if ($mismatches <= 5) {
			echo "Document:\n{$document}\nExpected:\n{$expected[$index]}\nPHP:\n{$actual}\nJS:\n{$reference}\n\n";
		}
	}
}

$blocks = count(array_filter($documents, static fn (string $document): bool => preg_match("/(\'{3,}|\"{3,})\n/", $document) === 1));
$offsets = count(array_filter($documents, static fn (string $document): bool => preg_match('/T[0-9:.]+[+-][0-9]{2}:[0-9]{2}/', $document) === 1));
echo "{$mismatches} mismatches in {$count} documents ({$blocks} with block strings, {$offsets} with instant offsets)\n";
exit($mismatches === 0 ? 0 : 1);
