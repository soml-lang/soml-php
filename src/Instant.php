<?php

declare(strict_types=1);

namespace Soml;

use function gmdate;
use function intdiv;
use function is_int;
use function rtrim;
use function str_pad;

/**
A point in time exact to the nanosecond, such as `2026-09-19T14:00:00Z`.

`DateTimeImmutable` only holds microseconds. Convert with `toDateTimeImmutable()`. The original offset is not part of the value, so different spellings of the same instant are equal.

Immutable, with `==`, `<`, and `<=>` comparing by time. Supports `serialize()` and `var_export()`, including Laravel's `config:cache`.

```
$instant = Soml::decode('deployed: 2026-09-19T16:00:00.5+02:00')->deployed;

echo $instant;
//=> 2026-09-19T14:00:00.5Z

$instant->toDateTimeImmutable()->format('Y-m-d');
//=> '2026-09-19'
```
*/
final readonly class Instant implements \Stringable, \JsonSerializable
{
	/**
	The earliest instant, `0001-01-01T00:00:00Z`, in seconds since the Unix epoch.
	*/
	public const int MIN_EPOCH_SECONDS = -62_135_596_800;

	/**
	The second of the latest instant, `9999-12-31T23:59:59.999999999Z`, since the Unix epoch.
	*/
	public const int MAX_EPOCH_SECONDS = 253_402_300_799;

	/**
	@param int $epochSeconds Seconds since the Unix epoch, `1970-01-01T00:00:00Z`, rounded down, so an instant before the epoch has a negative number of seconds and a positive fraction.
	@param int $nanoseconds The fraction of the second, from 0 to 999999999.
	@throws \InvalidArgumentException When the instant is outside the years 0001 to 9999 in UTC, or `$nanoseconds` is outside 0 to 999999999.
	*/
	public function __construct(
		// PHP compares two objects of one class property by property, in this order, so the seconds must come first for `<` and `<=>` to compare by time.
		#[\SensitiveParameter] public int $epochSeconds,
		#[\SensitiveParameter] public int $nanoseconds = 0,
	) {
		if ($nanoseconds < 0 || $nanoseconds > 999_999_999) {
			throw new \InvalidArgumentException("The nanoseconds must be 0 to 999999999, got {$nanoseconds}");
		}

		if ($epochSeconds < self::MIN_EPOCH_SECONDS || $epochSeconds > self::MAX_EPOCH_SECONDS) {
			throw new \InvalidArgumentException("The instant {$epochSeconds} seconds from the Unix epoch is outside the years 0001 to 9999");
		}
	}

	/**
	Read an instant written as in a document, such as `2026-09-19T14:00:00Z`, without surrounding whitespace.

	@throws DecodeException When `$text` is not an instant.
	*/
	public static function parse(#[\SensitiveParameter] string $text): self
	{
		$value = Decoder::decodeScalar($text);

		if (!$value instanceof self) {
			throw DecodeException::create('“' . Decoder::abbreviate($text) . '” is not an instant. An instant is written as 2026-09-19T14:00:00Z', $text, 0);
		}

		return $value;
	}

	/**
	Make an instant from a `DateTimeInterface`, in any time zone.

	@throws \InvalidArgumentException When it is outside the years 0001 to 9999 in UTC.
	*/
	#[\NoDiscard]
	public static function fromDateTime(#[\SensitiveParameter] \DateTimeInterface $dateTime): self
	{
		// PHP can wrap an enormous civil year into an ordinary timestamp. The local year may extend one year beyond the UTC range because of its offset.
		$year = (int) $dateTime->format('Y');

		if ($year < 0 || $year > 10_000) {
			throw new \InvalidArgumentException('The instant is outside the years 0001 to 9999');
		}

		// Before the epoch too, `getTimestamp()` rounds down and the microseconds count up from it, as here.
		return new self($dateTime->getTimestamp(), $dateTime->getMicrosecond() * Duration::MICROSECOND);
	}

	/**
	Make an instant from whole milliseconds since the Unix epoch, including negative timestamps.

	@throws \InvalidArgumentException When the result is outside years 0001 to 9999 in UTC.
	*/
	#[\NoDiscard]
	public static function fromEpochMilliseconds(#[\SensitiveParameter] int $milliseconds): self
	{
		return self::fromEpochUnits($milliseconds, 1000);
	}

	/**
	Make an instant from whole microseconds since the Unix epoch, including negative timestamps.

	@throws \InvalidArgumentException When the result is outside years 0001 to 9999 in UTC.
	*/
	#[\NoDiscard]
	public static function fromEpochMicroseconds(#[\SensitiveParameter] int $microseconds): self
	{
		return self::fromEpochUnits($microseconds, 1_000_000);
	}

	/**
	Make an instant from signed 64-bit nanoseconds since the Unix epoch, without floating-point arithmetic.
	*/
	#[\NoDiscard]
	public static function fromEpochNanoseconds(#[\SensitiveParameter] int $nanoseconds): self
	{
		return self::fromEpochUnits($nanoseconds, Duration::SECOND);
	}

	/**
	Split an epoch timestamp into seconds rounded down and a non-negative fractional second.

	@param 1000|1000000|1000000000 $unitsPerSecond
	*/
	private static function fromEpochUnits(#[\SensitiveParameter] int $value, int $unitsPerSecond): self
	{
		$seconds = intdiv($value, $unitsPerSecond);
		$fraction = $value % $unitsPerSecond;

		if ($fraction < 0) {
			$seconds--;
			$fraction += $unitsPerSecond;
		}

		return new self($seconds, $fraction * intdiv(Duration::SECOND, $unitsPerSecond));
	}

	/**
	Whole milliseconds since the Unix epoch, rounded down. Sub-millisecond precision is discarded.
	*/
	#[\NoDiscard]
	public function toEpochMilliseconds(): int
	{
		return $this->epochSeconds * 1000 + intdiv($this->nanoseconds, Duration::MILLISECOND);
	}

	/**
	Whole microseconds since the Unix epoch, rounded down. Sub-microsecond precision is discarded.
	*/
	#[\NoDiscard]
	public function toEpochMicroseconds(): int
	{
		return $this->epochSeconds * 1_000_000 + intdiv($this->nanoseconds, Duration::MICROSECOND);
	}

	/**
	Exact nanoseconds since the Unix epoch.

	@throws \OverflowException When the timestamp does not fit in a signed 64-bit integer.
	*/
	#[\NoDiscard]
	public function toEpochNanoseconds(): int
	{
		return $this->differenceNanoseconds(0, 0);
	}

	/**
	Add a fixed duration with exact nanosecond precision, without calendar or time-zone adjustments.

	@throws \InvalidArgumentException When the result is outside years 0001 to 9999 in UTC.
	*/
	#[\NoDiscard]
	public function plus(#[\SensitiveParameter] Duration $duration): self
	{
		return $this->shift(intdiv($duration->nanoseconds, Duration::SECOND), $duration->nanoseconds % Duration::SECOND);
	}

	/**
	Subtract a fixed duration with exact nanosecond precision, including the minimum signed 64-bit duration.

	@throws \InvalidArgumentException When the result is outside years 0001 to 9999 in UTC.
	*/
	#[\NoDiscard]
	public function minus(#[\SensitiveParameter] Duration $duration): self
	{
		return $this->shift(-intdiv($duration->nanoseconds, Duration::SECOND), -($duration->nanoseconds % Duration::SECOND));
	}

	/**
	The signed elapsed duration from `$other` to this instant, exact to the nanosecond.

	@throws \OverflowException When the difference does not fit in signed 64-bit nanoseconds.
	*/
	#[\NoDiscard]
	public function difference(#[\SensitiveParameter] self $other): Duration
	{
		return new Duration($this->differenceNanoseconds($other->epochSeconds, $other->nanoseconds));
	}

	/**
	Calculate a signed nanosecond difference without allocating intermediate durations or an epoch instant.
	*/
	private function differenceNanoseconds(#[\SensitiveParameter] int $epochSeconds, #[\SensitiveParameter] int $nanoseconds): int
	{
		$seconds = $this->epochSeconds - $epochSeconds;
		$fraction = $this->nanoseconds - $nanoseconds;

		// Bring both components to the same sign before scaling, so a representable result never overflows in an intermediate calculation.
		if ($seconds > 0 && $fraction < 0) {
			$seconds--;
			$fraction += Duration::SECOND;
		} elseif ($seconds < 0 && $fraction > 0) {
			$seconds++;
			$fraction -= Duration::SECOND;
		}

		return self::checkedNanoseconds($seconds * Duration::SECOND + $fraction);
	}

	/**
	Reject PHP's promotion of overflowing integer arithmetic to float before returning an exact nanosecond difference.
	*/
	private static function checkedNanoseconds(#[\SensitiveParameter] int|float $nanoseconds): int
	{
		if (!is_int($nanoseconds)) {
			throw new \OverflowException('The duration is outside the 64-bit range of nanoseconds');
		}

		return $nanoseconds;
	}

	/**
	Apply signed second and subsecond adjustments and normalize the fractional second once.
	*/
	private function shift(#[\SensitiveParameter] int $seconds, #[\SensitiveParameter] int $nanoseconds): self
	{
		$seconds += $this->epochSeconds;
		$nanoseconds += $this->nanoseconds;

		if ($nanoseconds >= Duration::SECOND) {
			$seconds++;
			$nanoseconds -= Duration::SECOND;
		} elseif ($nanoseconds < 0) {
			$seconds--;
			$nanoseconds += Duration::SECOND;
		}

		return new self($seconds, $nanoseconds);
	}

	/**
	The instant as a `DateTimeImmutable` in UTC. A `DateTimeImmutable` holds microseconds, so the nanoseconds below a microsecond are left out.
	*/
	#[\NoDiscard]
	public function toDateTimeImmutable(): \DateTimeImmutable
	{
		static $utc;

		if (!$utc instanceof \DateTimeZone) {
			$utc = new \DateTimeZone('UTC');
		}

		$dateTime = \DateTimeImmutable::createFromTimestamp($this->epochSeconds)->setTimezone($utc);
		$microseconds = intdiv($this->nanoseconds, Duration::MICROSECOND);
		return $microseconds === 0 ? $dateTime : $dateTime->setMicrosecond($microseconds);
	}

	/**
	The canonical spelling, in UTC, such as `2026-09-19T14:00:00Z` or `2026-09-19T14:00:00.123456789Z`.

	PHP's UTC formatter supplies the calendar date, the inverse conversion of `daysFromCivil()`, and the time. The nanosecond fraction is kept separately so no precision is lost.
	*/
	#[\Override]
	public function __toString(): string
	{
		return gmdate('Y-m-d\TH:i:s', $this->epochSeconds) . self::formatFraction($this->nanoseconds) . 'Z';
	}

	/**
	The canonical spelling, so `json_encode()` writes an instant as a string.
	*/
	#[\Override]
	public function jsonSerialize(): string
	{
		return (string) $this;
	}

	/**
	Restore an instant from `var_export()`, including Laravel's `config:cache`.

	@param array{epochSeconds: int, nanoseconds: int} $properties
	*/
	#[\NoDiscard]
	public static function __set_state(#[\SensitiveParameter] array $properties): self
	{
		return new self($properties['epochSeconds'], $properties['nanoseconds']);
	}

	/**
	Check the instant when `unserialize()` makes it, which does not call the constructor.

	@param array{epochSeconds: int, nanoseconds: int} $data
	*/
	public function __unserialize(#[\SensitiveParameter] array $data): void
	{
		$this->__construct($data['epochSeconds'], $data['nanoseconds']);
	}

	/**
	@return array{epochSeconds: int, nanoseconds: int}
	*/
	public function __serialize(): array
	{
		return ['epochSeconds' => $this->epochSeconds, 'nanoseconds' => $this->nanoseconds];
	}

	/**
	The fraction of a second, from its nanoseconds, as canonical form writes it for an instant and a duration: nothing when it is zero, and otherwise a `.` and nine digits with the trailing zeros removed.

	@internal
	*/
	#[\NoDiscard]
	public static function formatFraction(int $nanoseconds): string
	{
		// Note: Not using `sprintf()` because numeric padding benchmarked slower than `str_pad()` for nonzero fractions.
		return $nanoseconds === 0 ? '' : '.' . rtrim(str_pad((string) $nanoseconds, 9, '0', \STR_PAD_LEFT), '0');
	}

	/**
	@internal
	@return int<28, 31>
	*/
	#[\NoDiscard]
	public static function daysInMonth(int $year, int $month): int
	{
		// Note: Not using `cal_days_in_month()` because it would add an optional calendar extension dependency.
		return match ($month) {
			2 => $year % 4 === 0 && ($year % 100 !== 0 || $year % 400 === 0) ? 29 : 28,
			4, 6, 9, 11 => 30,
			default => 31,
		};
	}

	/**
	The days from the Unix epoch to a date in the proleptic Gregorian calendar, by Howard Hinnant's algorithm.

	@internal
	*/
	#[\NoDiscard]
	public static function daysFromCivil(int $year, int $month, int $day): int
	{
		// Note: Not using `gmmktime()` because it remaps years 1 through 100, so year 0001 would become 2001.
		$year -= $month <= 2 ? 1 : 0;
		$era = intdiv($year >= 0 ? $year : $year - 399, 400);
		$yearOfEra = $year - $era * 400;
		$dayOfYear = intdiv(153 * ($month + ($month > 2 ? -3 : 9)) + 2, 5) + $day - 1;
		$dayOfEra = $yearOfEra * 365 + intdiv($yearOfEra, 4) - intdiv($yearOfEra, 100) + $dayOfYear;
		return $era * 146_097 + $dayOfEra - 719_468;
	}
}
