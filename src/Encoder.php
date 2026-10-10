<?php

declare(strict_types=1);

namespace Soml;

use function array_is_list;
use function explode;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_object;
use function is_string;
use function ksort;
use function preg_match;
use function rtrim;
use function spl_object_id;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strcmp;
use function strcspn;
use function strlen;
use function strtr;
use function substr;

/**
Writes SOML by appending to one string, avoiding repeated copies of nested output. Canonical mode also sorts each object's keys.

@internal
*/
final class Encoder
{
	/*
	The characters that make `formatString()` write a `"..."` escaped string: every C0 control character, `'`, and DEL. A `'...'` string may hold a tab, but canonical form writes it as `\t`.
	*/
	private const string NEEDS_ESCAPED_STRING = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F'\x7F";

	/*
	How an escaped string writes each character that it escapes.
	*/
	private const array ESCAPES = [
		'\\' => '\\\\',
		'"' => '\"',
		"\x00" => '\u{0}',
		"\x01" => '\u{1}',
		"\x02" => '\u{2}',
		"\x03" => '\u{3}',
		"\x04" => '\u{4}',
		"\x05" => '\u{5}',
		"\x06" => '\u{6}',
		"\x07" => '\u{7}',
		"\x08" => '\u{8}',
		"\x09" => '\t',
		"\x0A" => '\n',
		"\x0B" => '\u{b}',
		"\x0C" => '\u{c}',
		"\x0D" => '\u{d}',
		"\x0E" => '\u{e}',
		"\x0F" => '\u{f}',
		"\x10" => '\u{10}',
		"\x11" => '\u{11}',
		"\x12" => '\u{12}',
		"\x13" => '\u{13}',
		"\x14" => '\u{14}',
		"\x15" => '\u{15}',
		"\x16" => '\u{16}',
		"\x17" => '\u{17}',
		"\x18" => '\u{18}',
		"\x19" => '\u{19}',
		"\x1A" => '\u{1a}',
		"\x1B" => '\u{1b}',
		"\x1C" => '\u{1c}',
		"\x1D" => '\u{1d}',
		"\x1E" => '\u{1e}',
		"\x1F" => '\u{1f}',
		"\x7F" => '\u{7f}',
	];

	private string $output = '';

	/**
	The `stdClass` objects that contain the value being written, by object ID, to find a circular structure.

	@var array<int, true>
	*/
	private array $ancestors = [];

	private function __construct(
		private readonly bool $isCanonical,
		private readonly bool $checksUtf8 = false,
		private readonly bool $isOneLine = false,
	) {}

	/**
	Writes a replacement value in the layout and at the depth of its destination in a document.
	*/
	#[\NoDiscard]
	public static function encodeValue(#[\SensitiveParameter] mixed $value, int $depth, string $indent, bool $isOneLine, bool $isCanonical = false): string
	{
		$encoder = new self($isCanonical, isOneLine: $isOneLine);
		$encoder->writeValue($value, $depth, $indent);

		// As with a whole document, validate once on the common path and find the offending value only when needed.
		if (preg_match('//u', $encoder->output) !== 1) {
			$encoder = new self($isCanonical, true, $isOneLine);
			$encoder->writeValue($value, $depth, $indent);
		}

		return $encoder->output;
	}

	/**
	@param array<mixed>|\stdClass $value
	*/
	#[\NoDiscard]
	public static function encode(#[\SensitiveParameter] array|\stdClass $value, bool $isCanonical): string
	{
		$output = new self($isCanonical)->writeDocument($value);

		// Every string is between ASCII delimiters in the output, so one check of the whole output finds any string that is not valid UTF-8. Writing again with a check of each string then says where it is.
		if (preg_match('//u', $output) !== 1) {
			unset($output);
			return new self($isCanonical, true)->writeDocument($value);
		}

		return $output;
	}

	/**
	A key as it must be written in a document: bare when it can be, and otherwise as a `'...'` or `"..."` string.

	@return non-empty-string
	*/
	#[\NoDiscard]
	public static function formatKey(#[\SensitiveParameter] string $key): string
	{
		return Soml::isBareKey($key) ? $key : self::formatString($key, 'key');
	}

	/**
	A string as it must be written in a document: a `'...'` literal string when it has none of the characters that canonical form escapes, and otherwise a `"..."` escaped string. `$kind` is for the error message.

	@param 'string'|'key' $kind
	@return non-empty-string
	*/
	#[\NoDiscard]
	public static function formatString(#[\SensitiveParameter] string $string, string $kind = 'string'): string
	{
		// Note: Not using `addcslashes()` because it emits C octal escapes that SOML does not support.
		// One scan settles the common case: no quote and no control character.
		if (strcspn($string, self::NEEDS_ESCAPED_STRING) === strlen($string)) {
			return "'{$string}'";
		}

		if (str_contains($string, "\r")) {
			throw EncodeException::create("Cannot serialize a {$kind} containing a carriage return (U+000D), which is not representable");
		}

		return '"' . strtr($string, self::ESCAPES) . '"';
	}

	/**
	The shortest round-tripping decimal, laid out as ECMAScript `Number::toString` without `+` in exponents. Uses `infinity` and `-infinity`, and adds `.0` when needed to preserve the float type.

	@return non-empty-string
	*/
	#[\NoDiscard]
	public static function formatFloat(#[\SensitiveParameter] float $value): string
	{
		if (is_nan($value)) {
			throw EncodeException::create('Cannot serialize NaN, which is not representable. Use null for a missing value');
		}

		if (is_infinite($value)) {
			return $value > 0 ? 'infinity' : '-infinity';
		}

		// Covers -0.0 too, since zero has one value whatever its sign.
		if ($value === 0.0) {
			return '0.0';
		}

		// Note: Not using `json_encode()`, `(string)`, or `var_export()` because their output depends on `precision` or `serialize_precision`, or `%G` because it depends on the locale. `%.*H` with precision -1 produces the shortest round-tripping digits, such as `1.5`, `1.0E+21`, or `1.0E-7`.
		$text = sprintf('%.*H', -1, $value);

		// Without an exponent, PHP writes the digits as the spec lays them out, except for the `.0` of a whole number.
		if (!str_contains($text, 'E')) {
			return str_contains($text, '.') ? $text : "{$text}.0";
		}

		$sign = '';

		if ($text[0] === '-') {
			$sign = '-';
			$text = substr($text, 1);
		}

		[$mantissa, $exponent] = explode('E', $text);
		$digits = rtrim(str_replace('.', '', $mantissa), '0');
		// PHP's exponent form has one digit before the decimal point. The value is 0.`$digits` × 10^`$pointPosition`.
		$pointPosition = (int) $exponent + 1;
		$digitCount = strlen($digits);

		if ($digitCount <= $pointPosition && $pointPosition <= 21) {
			return $sign . $digits . str_repeat('0', $pointPosition - $digitCount) . '.0';
		}

		if ($pointPosition > 0 && $pointPosition < $digitCount) {
			return $sign . substr($digits, 0, $pointPosition) . '.' . substr($digits, $pointPosition);
		}

		if ($pointPosition > -6 && $pointPosition <= 0) {
			return $sign . '0.' . str_repeat('0', -$pointPosition) . $digits;
		}

		return $sign . $digits[0] . ($digitCount > 1 ? '.' . substr($digits, 1) : '') . 'e' . ($pointPosition - 1);
	}

	/**
	The shared depth error for encoding and creating missing objects during an edit.
	*/
	#[\NoDiscard]
	public static function depthException(): EncodeException
	{
		return EncodeException::create('Cannot serialize a value nested more than ' . Decoder::MAX_DEPTH . ' levels deep');
	}

	/**
	@param array<mixed>|\stdClass $value
	*/
	private function writeDocument(#[\SensitiveParameter] array|\stdClass $value): string
	{
		if (is_array($value)) {
			if ($value === []) {
				return "[]\n";
			}

			if (array_is_list($value)) {
				$this->writeContainer($value, 1, '', false);
				return "{$this->output}\n";
			}

			$members = $value;
		} else {
			$members = self::objectMembers($value);

			// A brace-less object needs at least one entry, so the empty object keeps its braces, like an array.
			if ($members === []) {
				return "{}\n";
			}

			$this->ancestors[spl_object_id($value)] = true;
		}

		// The document's own object is level 1, so a value in it is at level 2, as a value in a braced object is.
		$this->writeMembers($members, 2, '');
		return $this->output;
	}

	/**
	Writes a value at a place whose container depth is `$depth`, without a line break after it. `$indent` is the indentation of the line the value starts on. The items of a container are indented one tab more, and its closing bracket by `$indent`.
	*/
	private function writeValue(#[\SensitiveParameter] mixed $value, int $depth, string $indent, #[\SensitiveParameter] string|int|null $segment = null): void
	{
		try {
			if (is_string($value)) {
				if ($this->checksUtf8) {
					self::checkUtf8($value, 'string');
				}

				$this->output .= self::formatString($value);
			} elseif (is_int($value)) {
				$this->output .= $value;
			} elseif (is_float($value)) {
				$this->output .= self::formatFloat($value);
			} elseif (is_bool($value)) {
				$this->output .= $value ? 'true' : 'false';
			} elseif ($value === null) {
				$this->output .= 'null';
			} elseif (is_array($value)) {
				$this->writeContainer($value, $depth, $indent, !array_is_list($value));
			} elseif ($value instanceof \stdClass) {
				// Only an object can be circular, because PHP copies arrays. An array that holds a reference to itself ends at the depth limit instead.
				$id = spl_object_id($value);

				if (isset($this->ancestors[$id])) {
					throw EncodeException::create('Cannot serialize a circular structure');
				}

				$members = self::objectMembers($value);
				$this->ancestors[$id] = true;
				$this->writeContainer($members, $depth, $indent, true);
				unset($this->ancestors[$id]);
			} elseif ($value instanceof Instant || $value instanceof Duration) {
				$this->output .= $value;
			} elseif ($value instanceof \DateTimeInterface) {
				try {
					$this->output .= Instant::fromDateTime($value);
				} catch (\InvalidArgumentException) {
					throw EncodeException::create('Cannot serialize the instant ' . $value->format(\DATE_RFC3339_EXTENDED) . ', because it is outside the years 0001 to 9999');
				}
			} elseif ($value instanceof \DateInterval) {
				// The reason of the conversion, such as for days, which are not a fixed length, is the reason of the error.
				try {
					$this->output .= Duration::fromDateInterval($value);
				} catch (\InvalidArgumentException $exception) {
					throw EncodeException::create($exception->getMessage());
				}
			} elseif ($value instanceof \BackedEnum) {
				$this->writeValue($value->value, $depth, $indent);
			} elseif ($value instanceof \UnitEnum) {
				throw EncodeException::create('Cannot serialize the enum case ' . $value::class . "::{$value->name}, because it has no value. Use a backed enum");
			} else {
				throw EncodeException::create('Cannot serialize ' . self::describeType($value) . '. Only arrays, stdClass objects, scalars, null, Instant, Duration, DateTimeInterface, DateInterval, and backed enums can be serialized');
			}
		} catch (EncodeException $exception) {
			if ($segment !== null) {
				$exception->prependPathSegment($segment);
			}

			throw $exception;
		}
	}

	/**
	@param array<mixed> $items
	*/
	private function writeContainer(#[\SensitiveParameter] array $items, int $depth, string $indent, bool $isObject): void
	{
		if ($depth > Decoder::MAX_DEPTH) {
			throw self::depthException();
		}

		$opening = $isObject ? '{' : '[';
		$closing = $isObject ? '}' : ']';

		if ($items === []) {
			$this->output .= $opening . $closing;
			return;
		}

		$innerIndent = $this->isOneLine ? '' : "{$indent}\t";
		$lineEnd = $this->isOneLine ? '' : "\n";
		$this->output .= $opening . $lineEnd;

		if ($isObject) {
			$this->writeMembers($items, $depth + 1, $innerIndent);
			$this->output .= ($this->isOneLine ? '' : $indent) . $closing;
			return;
		}

		foreach ($items as $index => $item) {
			if ($this->isOneLine && $index > 0) {
				$this->output .= ', ';
			}

			// A string that needs no escapes and an int, the most common values, inline.
			if (is_string($item) && !$this->checksUtf8 && strcspn($item, self::NEEDS_ESCAPED_STRING) === strlen($item)) {
				$this->output .= "{$innerIndent}'{$item}'{$lineEnd}";
			} elseif (is_int($item)) {
				$this->output .= "{$innerIndent}{$item}{$lineEnd}";
			} else {
				$this->output .= $innerIndent;

				$this->writeValue($item, $depth + 1, $innerIndent, $index);

				$this->output .= $lineEnd;
			}
		}

		$this->output .= ($this->isOneLine ? '' : $indent) . $closing;
	}

	/**
	@param array<mixed> $members
	*/
	private function writeMembers(#[\SensitiveParameter] array $members, int $depth, string $indent): void
	{
		// A byte comparison, because UTF-8 byte order is code point order. A PHP array has an int key for a key such as `404`, and `SORT_STRING` compares it as a string too.
		if ($this->isCanonical) {
			$previousKey = '';

			// Check order in O(n) to avoid the O(n log n) sort and copy for already ordered members.
			foreach ($members as $key => $_) {
				$key = (string) $key;

				if (strcmp($previousKey, $key) > 0) {
					ksort($members, \SORT_STRING);
					break;
				}

				$previousKey = $key;
			}
		}

		$lineEnd = $this->isOneLine ? '' : "\n";
		$isFirst = true;

		foreach ($members as $key => $member) {
			if ($this->isOneLine && !$isFirst) {
				$this->output .= ', ';
			}

			$isFirst = false;
			// PHP makes an int of a key such as `404`, but only of the canonical decimal spelling, so the string of the int is the key as it was.
			$key = (string) $key;

			// An error about the key itself is reported at the path of the object.
			if ($this->checksUtf8) {
				self::checkUtf8($key, 'key');
			}

			// Bare keys inline; the predicate and string formatter are shared with replacements in the editor.
			$prefix = $indent . self::formatKey($key) . ': ';

			// A string that needs no escapes and an int, the most common values, inline.
			if (is_string($member) && !$this->checksUtf8 && strcspn($member, self::NEEDS_ESCAPED_STRING) === strlen($member)) {
				$this->output .= "{$prefix}'{$member}'{$lineEnd}";
			} elseif (is_int($member)) {
				$this->output .= "{$prefix}{$member}{$lineEnd}";
			} else {
				$this->output .= $prefix;

				$this->writeValue($member, $depth, $indent, $key);

				$this->output .= $lineEnd;
			}
		}
	}

	/**
	Reads only plain objects, because inspecting a subclass can execute its property hooks.

	@return array<string|int, mixed>
	*/
	private static function objectMembers(#[\SensitiveParameter] \stdClass $value): array
	{
		if ($value::class !== \stdClass::class) {
			throw EncodeException::create('Cannot serialize a stdClass subclass; subclasses are unsupported');
		}

		return (array) $value;
	}

	/**
	@param 'string'|'key' $kind
	*/
	private static function checkUtf8(#[\SensitiveParameter] string $string, string $kind): void
	{
		if (preg_match('//u', $string) !== 1) {
			throw EncodeException::create("Cannot serialize a {$kind} that is not valid UTF-8");
		}
	}

	private static function describeType(#[\SensitiveParameter] mixed $value): string
	{
		return is_object($value) ? 'an instance of ' . Decoder::describeText($value::class) : 'a ' . get_debug_type($value);
	}
}
