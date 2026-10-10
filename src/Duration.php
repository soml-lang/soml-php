<?php

declare(strict_types=1);

namespace Soml;

use function abs;
use function get_mangled_object_vars;
use function intdiv;
use function is_finite;
use function is_int;
use function round;
use function var_export;

/**
A length of time exact to the nanosecond, such as `1h30m`: a signed 64-bit count of nanoseconds, about 292 years either way.

Unlike `DateInterval`, it has no variable-length units such as days or months. Converting to `DateInterval` truncates to microseconds.

Immutable, with `==`, `<`, and `<=>` comparing by length. Supports `serialize()` and `var_export()`, including Laravel's `config:cache`.

```
$timeout = Soml::decode('timeout: 1m30s')->timeout;

$timeout->nanoseconds;
//=> 90000000000

$timeout->toSeconds();
//=> 90.0

echo new Duration(1500 * Duration::MILLISECOND);
//=> 1.5s
```
*/
final readonly class Duration implements \Stringable, \JsonSerializable
{
	public const int NANOSECOND = 1;
	public const int MICROSECOND = 1000;
	public const int MILLISECOND = 1_000_000;
	public const int SECOND = 1_000_000_000;
	public const int MINUTE = 60_000_000_000;
	public const int HOUR = 3_600_000_000_000;

	/**
	Duration units in grammar order, with their nanosecond size, decimal fraction factor and power, and rank. Keeping the data together prevents conversion and ordering rules from drifting apart; the factor and power allow exact fractions without overflowing an int.

	@var array<string, array{positive-int, 1|60|3600, 0|3|6|9, int<0, 5>}>
	@internal
	*/
	public const array UNITS = [
		'h' => [self::HOUR, 3600, 9, 0],
		'm' => [self::MINUTE, 60, 9, 1],
		's' => [self::SECOND, 1, 9, 2],
		'ms' => [self::MILLISECOND, 1, 6, 3],
		'us' => [self::MICROSECOND, 1, 3, 4],
		'ns' => [self::NANOSECOND, 1, 0, 5],
	];

	/**
	@param int $nanoseconds The length in nanoseconds, which is negative for a negative duration.
	*/
	public function __construct(
		#[\SensitiveParameter] public int $nanoseconds,
	) {}

	/**
	Read a duration written as in a document, such as `1h30m` or `1.5s`, without surrounding whitespace.

	@throws DecodeException When `$text` is not a duration.
	*/
	public static function parse(#[\SensitiveParameter] string $text): self
	{
		$value = Decoder::decodeScalar($text);

		if (!$value instanceof self) {
			throw DecodeException::create('“' . Decoder::abbreviate($text) . '” is not a duration. A duration is a number with a unit of h, m, s, ms, us, or ns, as in 1h30m', $text, 0);
		}

		return $value;
	}

	/**
	Make a duration from seconds, rounded to the nearest nanosecond. Floats can lose precision; use the constructor or `parse()` for exact nanoseconds.

	@throws \InvalidArgumentException When `$seconds` is not finite or the duration is outside the 64-bit range of nanoseconds.
	*/
	#[\NoDiscard]
	public static function fromSeconds(#[\SensitiveParameter] int|float $seconds): self
	{
		if (
			$seconds <= -9_223_372_037
			|| $seconds >= 9_223_372_037
			|| !is_finite($seconds)
		) {
			self::failSeconds($seconds);
		}

		if (is_int($seconds)) {
			$nanoseconds = $seconds * self::SECOND;
		} else {
			$wholeSeconds = (int) $seconds;
			$wholeNanoseconds = $wholeSeconds * self::SECOND;
			$fractionalNanoseconds = (int) round(($seconds - $wholeSeconds) * self::SECOND, mode: \RoundingMode::HalfAwayFromZero);

			if (
				($fractionalNanoseconds > 0 && $wholeNanoseconds > \PHP_INT_MAX - $fractionalNanoseconds)
				|| ($fractionalNanoseconds < 0 && $wholeNanoseconds < \PHP_INT_MIN - $fractionalNanoseconds)
			) {
				self::failSeconds($seconds);
			}

			$nanoseconds = $wholeNanoseconds + $fractionalNanoseconds;
		}

		return new self($nanoseconds);
	}

	/**
	Make an exact duration from whole microseconds.

	@throws \OverflowException When the result does not fit in signed 64-bit nanoseconds.
	*/
	#[\NoDiscard]
	public static function fromMicroseconds(#[\SensitiveParameter] int $microseconds): self
	{
		return self::checked($microseconds * self::MICROSECOND);
	}

	/**
	Make an exact duration from whole milliseconds.

	@throws \OverflowException When the result does not fit in signed 64-bit nanoseconds.
	*/
	#[\NoDiscard]
	public static function fromMilliseconds(#[\SensitiveParameter] int $milliseconds): self
	{
		return self::checked($milliseconds * self::MILLISECOND);
	}

	/**
	Make an exact duration from whole minutes.

	@throws \OverflowException When the result does not fit in signed 64-bit nanoseconds.
	*/
	#[\NoDiscard]
	public static function fromMinutes(#[\SensitiveParameter] int $minutes): self
	{
		return self::checked($minutes * self::MINUTE);
	}

	/**
	Make an exact duration from whole hours.

	@throws \OverflowException When the result does not fit in signed 64-bit nanoseconds.
	*/
	#[\NoDiscard]
	public static function fromHours(#[\SensitiveParameter] int $hours): self
	{
		return self::checked($hours * self::HOUR);
	}

	/**
	Add a duration without losing nanosecond precision. Neither operand is changed.

	@throws \OverflowException When the result does not fit in signed 64-bit nanoseconds.
	*/
	#[\NoDiscard]
	public function plus(#[\SensitiveParameter] self $other): self
	{
		return self::checked($this->nanoseconds + $other->nanoseconds);
	}

	/**
	Subtract a duration without losing nanosecond precision. Neither operand is changed.

	@throws \OverflowException When the result does not fit in signed 64-bit nanoseconds.
	*/
	#[\NoDiscard]
	public function minus(#[\SensitiveParameter] self $other): self
	{
		return self::checked($this->nanoseconds - $other->nanoseconds);
	}

	/**
	Multiply by a whole-number factor without losing nanosecond precision.

	@throws \OverflowException When the result does not fit in signed 64-bit nanoseconds.
	*/
	#[\NoDiscard]
	public function multipliedBy(#[\SensitiveParameter] int $factor): self
	{
		return self::checked($this->nanoseconds * $factor);
	}

	/**
	Reverse the sign. The minimum signed 64-bit duration has no representable positive counterpart.

	@throws \OverflowException When the duration is PHP_INT_MIN nanoseconds.
	*/
	#[\NoDiscard]
	public function negated(): self
	{
		return self::checked(-$this->nanoseconds);
	}

	/**
	The non-negative magnitude without losing nanosecond precision.

	@throws \OverflowException When the duration is PHP_INT_MIN nanoseconds.
	*/
	#[\NoDiscard]
	public function absolute(): self
	{
		return $this->nanoseconds < 0 ? $this->negated() : $this;
	}

	/**
	PHP promotes overflowing integer arithmetic to float. Reject that promotion rather than silently rounding an exact duration.
	*/
	private static function checked(#[\SensitiveParameter] int|float $nanoseconds): self
	{
		if (!is_int($nanoseconds)) {
			throw new \OverflowException('The duration is outside the 64-bit range of nanoseconds');
		}

		return new self($nanoseconds);
	}

	/**
	Report seconds that cannot be converted to signed 64-bit nanoseconds.
	*/
	private static function failSeconds(#[\SensitiveParameter] int|float $seconds): never
	{
		throw new \InvalidArgumentException('The duration of ' . var_export($seconds, true) . ' seconds is not finite, or it is outside the 64-bit range of nanoseconds, about 292 years either way');
	}

	/**
	Make a duration by treating a `DateInterval`'s hours, minutes, seconds, and microseconds as fixed units.

	For `DateTimeInterface::diff()`, convert both dates to UTC before taking the difference, because an interval otherwise describes contextual clock changes rather than elapsed time.

	@throws \InvalidArgumentException When the interval has years, months, days, or negative components, is made from a relative date string, as by `DateInterval::createFromDateString()`, or is outside the 64-bit range of nanoseconds.
	*/
	#[\NoDiscard]
	public static function fromDateInterval(#[\SensitiveParameter] \DateInterval $interval): self
	{
		// `DateInterval::createFromDateString()` keeps the text, such as `next monday`, rather than a length. Its `from_string` is in its list of properties, but reading it with `->` or `isset()` does not find it, and on another interval reading it gives a warning. Read raw properties so unrelated property hooks on subclasses are not executed.
		if ((get_mangled_object_vars($interval)['from_string'] ?? false) === true) {
			throw new \InvalidArgumentException('A DateInterval made from a relative date string, as by DateInterval::createFromDateString(), cannot be a duration, because its length depends on the date it is added to. Use a DateInterval such as new DateInterval(\'PT1H30M\'), or Duration::parse(\'1h30m\')');
		}

		if ($interval->y !== 0 || $interval->m !== 0 || $interval->d !== 0) {
			throw new \InvalidArgumentException('A DateInterval with years, months, or days cannot be a duration, because they are not a fixed length. Use hours or smaller units');
		}

		if ($interval->h < 0 || $interval->i < 0 || $interval->s < 0 || $interval->f < 0) {
			throw new \InvalidArgumentException('A DateInterval with negative components cannot be a fixed duration. Convert the source dates to UTC before calling diff()');
		}

		$microseconds = (int) round($interval->f * 1_000_000, mode: \RoundingMode::HalfAwayFromZero);

		if ($microseconds < 0 || $microseconds > intdiv(\PHP_INT_MAX, self::MICROSECOND)) {
			throw new \InvalidArgumentException('The DateInterval is outside the 64-bit range of nanoseconds, about 292 years either way');
		}

		$fraction = $microseconds * self::MICROSECOND;
		// The most whole seconds that fit with the fraction. A negative duration goes one nanosecond further, to -2^63.
		$isNegative = $interval->invert !== 0;
		$maximumSeconds = $isNegative ? -intdiv(\PHP_INT_MIN + $fraction, self::SECOND) : intdiv(\PHP_INT_MAX - $fraction, self::SECOND);

		// In floats, which are exact for whole seconds of this size, because the sum in ints could overflow.
		if ($interval->h * 3600.0 + $interval->i * 60.0 + $interval->s > $maximumSeconds) {
			throw new \InvalidArgumentException('The DateInterval is outside the 64-bit range of nanoseconds, about 292 years either way');
		}

		$magnitude = (($interval->h * 60 + $interval->i) * 60 + $interval->s) * self::SECOND;
		return new self($isNegative ? -$magnitude - $fraction : $magnitude + $fraction);
	}

	/**
	The length in seconds, as a float, for timeout options.
	*/
	#[\NoDiscard]
	public function toSeconds(): float
	{
		return $this->nanoseconds / self::SECOND;
	}

	/**
	The duration as a `DateInterval` of hours, minutes, seconds, and microseconds, for `DateTimeImmutable::add()`. A `DateInterval` holds microseconds, so the nanoseconds below a microsecond are left out.
	*/
	#[\NoDiscard]
	public function toDateInterval(): \DateInterval
	{
		// Note: Not using a dynamically populated `DateInterval` constructor string because parsing its components benchmarked slower than assigning them.
		[$hours, $minutes, $seconds, $fraction] = $this->parts();
		$interval = new \DateInterval('PT0S');
		$interval->h = $hours;
		$interval->i = $minutes;
		$interval->s = $seconds;
		// PHP keeps it as whole microseconds by truncating `$f * 1e6`, which can be just below the int, so half a microsecond more makes the truncation exact.
		$interval->f = (intdiv($fraction, self::MICROSECOND) + 0.5) / 1_000_000;
		$interval->invert = $this->nanoseconds < 0 ? 1 : 0;
		return $interval;
	}

	/**
	The canonical spelling, such as `1h30m`, `1.5s`, `-0.25s`, or `0s`. So `90m` is written `1h30m`, and `-250ms` is written `-0.25s`.
	*/
	#[\Override]
	public function __toString(): string
	{
		if ($this->nanoseconds === 0) {
			return '0s';
		}

		[$hours, $minutes, $seconds, $fraction] = $this->parts();
		$text = $this->nanoseconds < 0 ? '-' : '';

		if ($hours > 0) {
			$text .= "{$hours}h";
		}

		if ($minutes > 0) {
			$text .= "{$minutes}m";
		}

		if ($seconds > 0 || $fraction > 0) {
			$text .= $seconds . Instant::formatFraction($fraction) . 's';
		}

		return $text;
	}

	/**
	The canonical spelling, so `json_encode()` writes a duration as a string.
	*/
	#[\Override]
	public function jsonSerialize(): string
	{
		return (string) $this;
	}

	/**
	Restore a duration from `var_export()`, including Laravel's `config:cache`.

	@param array{nanoseconds: int} $properties
	*/
	#[\NoDiscard]
	public static function __set_state(#[\SensitiveParameter] array $properties): self
	{
		return new self($properties['nanoseconds']);
	}

	/**
	The hours, minutes, seconds, and nanoseconds of the magnitude. Each part is computed before the sign is removed, because the magnitude of the smallest duration does not fit in an int.

	@return array{non-negative-int, int, int, int<0, 999999999>}
	*/
	private function parts(): array
	{
		$rest = abs($this->nanoseconds % self::HOUR);

		return [
			abs(intdiv($this->nanoseconds, self::HOUR)),
			intdiv($rest, self::MINUTE),
			intdiv($rest % self::MINUTE, self::SECOND),
			$rest % self::SECOND,
		];
	}
}
