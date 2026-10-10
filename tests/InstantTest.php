<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Instant;

final class InstantTest extends TestCase
{
	#[Test]
	public function microsecondTruncationDoesNotMoveInstantsAcrossTheEpoch(): void
	{
		foreach ([
			[new Instant(-1, 999_999_999), '1969-12-31T23:59:59.999999Z'],
			[new Instant(0, 999), '1970-01-01T00:00:00.000000Z'],
			[new Instant(1, 1001), '1970-01-01T00:00:01.000001Z'],
		] as [$instant, $expected]) {
			$date = $instant->toDateTimeImmutable();
			$this->assertSame($expected, $date->format('Y-m-d\TH:i:s.u\Z'));
			$this->assertSame($instant->epochSeconds, $date->getTimestamp());
		}
	}

	#[Test]
	public function convertingAMutableDateTimeTakesAnIndependentSnapshot(): void
	{
		$date = new \DateTime('1970-01-01T01:59:59.999999+02:00');
		$original = clone $date;
		$instant = Instant::fromDateTime($date);
		$this->assertEquals($original, $date);
		$date->modify('+1 day');
		$this->assertSame('1969-12-31T23:59:59.999999Z', (string) $instant);
		$this->assertSame('1970-01-01T23:59:59.999999Z', (string) Instant::fromDateTime($date));
	}

	#[Test]
	public function unserializationValidatesTheNanosecondFraction(): void
	{
		$serialized = str_replace('"nanoseconds";i:0;', '"nanoseconds";i:1000000000;', serialize(new Instant(0)));
		$this->assertStringContainsString('i:1000000000;', $serialized);
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The nanoseconds must be 0 to 999999999');
		$value = unserialize($serialized);
	}

	#[Test]
	public function canonicalSpelling(): void
	{
		$this->assertSame('2026-09-19T14:00:00Z', (string) new Instant(1_789_826_400));
		$this->assertSame('2026-09-19T14:00:00.5Z', (string) new Instant(1_789_826_400, 500_000_000));
		$this->assertSame('2026-09-19T14:00:00.000000001Z', (string) new Instant(1_789_826_400, 1));
		$this->assertSame('1970-01-01T00:00:00Z', (string) new Instant(0));
		$this->assertSame('1969-12-31T23:59:59.5Z', (string) new Instant(-1, 500_000_000));
		$this->assertSame('0001-01-01T00:00:00Z', (string) new Instant(Instant::MIN_EPOCH_SECONDS));
		$this->assertSame('9999-12-31T23:59:59.999999999Z', (string) new Instant(Instant::MAX_EPOCH_SECONDS, 999_999_999));
		$this->assertSame('"2026-09-19T14:00:00Z"', json_encode(new Instant(1_789_826_400)));
	}

	#[Test]
	public function formattingPreservesHistoricYearsAndNanosecondsInEveryDefaultTimezone(): void
	{
		$previousTimezone = date_default_timezone_get();

		try {
			foreach (['Pacific/Kiritimati', 'America/Los_Angeles'] as $timezone) {
				date_default_timezone_set($timezone);

				foreach (['0001-01-01T00:00:00', '0069-12-31T23:59:59', '0070-01-01T00:00:00', '0099-12-31T23:59:59', '0100-03-01T00:00:00', '1500-03-01T00:00:00', '1582-10-10T12:34:56', '1600-02-29T23:59:59', '1900-03-01T00:00:00', '1969-12-31T23:59:59', '1970-01-01T00:00:00', '2000-02-29T12:34:56', '9999-12-31T23:59:59'] as $dateTime) {
					foreach (['', '.000000001', '.123456789', '.999999999'] as $fraction) {
						$expected = $dateTime . $fraction . 'Z';
						$this->assertSame($expected, (string) Instant::parse($expected));
					}
				}
			}
		} finally {
			date_default_timezone_set($previousTimezone);
		}
	}

	#[Test]
	public function parse(): void
	{
		$this->assertEquals(new Instant(1_789_826_400, 123_456_789), Instant::parse('2026-09-19T16:00:00.123456789+02:00'));

		try {
			$instant = Instant::parse('1h30m');
			$this->fail("Parsed as {$instant}");
		} catch (DecodeException $exception) {
			$this->assertSame('“1h30m” is not an instant. An instant is written as 2026-09-19T14:00:00Z', $exception->reason);
		}

		try {
			$instant = Instant::parse('2026-02-30T00:00:00Z');
			$this->fail("Parsed as {$instant}");
		} catch (DecodeException $exception) {
			$this->assertSame('Invalid instant 2026-02-30T00:00:00Z: the day must be 01 to 28 in that month', $exception->reason);
		}

		$this->expectException(DecodeException::class);
		$instant = Instant::parse('');
		$this->fail("Parsed as {$instant}");
	}

	#[Test]
	public function comparison(): void
	{
		$this->assertEquals(Instant::parse('2026-09-19T14:00:00Z'), Instant::parse('2026-09-19T16:00:00+02:00'));
		$this->assertTrue(new Instant(1, 5) < new Instant(2, 0));
		$this->assertTrue(new Instant(1, 5) > new Instant(1, 4));
		$this->assertSame(0, new Instant(1, 5) <=> new Instant(1, 5));
	}

	#[Test]
	public function dateTimeConversion(): void
	{
		$dateTime = new \DateTimeImmutable('2026-09-19T16:00:00.123456+02:00');
		$instant = Instant::fromDateTime($dateTime);

		$this->assertEquals(new Instant(1_789_826_400, 123_456_000), $instant);
		$this->assertSame('2026-09-19T14:00:00.123456+00:00', $instant->toDateTimeImmutable()->format('Y-m-d\TH:i:s.uP'));
		$this->assertSame('UTC', $instant->toDateTimeImmutable()->getTimezone()->getName());
		$this->assertSame('1969-12-31T23:59:59.500999Z', new Instant(-1, 500_999_999)->toDateTimeImmutable()->format('Y-m-d\TH:i:s.u\Z'));
		$this->assertEquals(new Instant(-1, 500_000_000), Instant::fromDateTime(new \DateTime('1969-12-31T23:59:59.5Z')));
		$this->assertSame('0001-01-01', new Instant(Instant::MIN_EPOCH_SECONDS)->toDateTimeImmutable()->format('Y-m-d'));
	}

	#[Test]
	public function dateTimeWithAWrappedTimestampIsRejected(): void
	{
		$dateTime = new \DateTimeImmutable('2000-01-01T00:00:00Z')->setDate(\PHP_INT_MIN + 1970, 1, 1);
		$this->assertGreaterThanOrEqual(Instant::MIN_EPOCH_SECONDS, $dateTime->getTimestamp());
		$this->assertLessThanOrEqual(Instant::MAX_EPOCH_SECONDS, $dateTime->getTimestamp());
		$this->expectException(\InvalidArgumentException::class);
		$value = Instant::fromDateTime($dateTime);
	}

	#[Test]
	public function dateTimeLocalYearCanBeOutsideTheUtcRange(): void
	{
		foreach (['0000-12-31T23:30:00-01:00' => '0001-01-01T00:30:00Z', '+10000-01-01T00:30:00+01:00' => '9999-12-31T23:30:00Z'] as $text => $expected) {
			$this->assertSame($expected, (string) Instant::fromDateTime(new \DateTimeImmutable($text)));
			$this->assertSame($expected, (string) Instant::fromDateTime(new \DateTime($text)));
		}
	}

	#[Test]
	public function invalidArguments(): void
	{
		foreach ([[0, -1], [0, 1_000_000_000], [Instant::MIN_EPOCH_SECONDS - 1, 0], [Instant::MAX_EPOCH_SECONDS + 1, 0]] as [$seconds, $nanoseconds]) {
			try {
				$instant = new Instant($seconds, $nanoseconds);
				$this->fail("Made {$instant}");
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}

		$this->expectException(\InvalidArgumentException::class);
		$instant = Instant::fromDateTime(new \DateTimeImmutable('@253402300800'));
		$this->fail("Made {$instant}");
	}

	#[Test]
	public function constructionTraceHidesValues(): void
	{
		$ignoreArguments = (string) ini_get('zend.exception_ignore_args');
		ini_set('zend.exception_ignore_args', '0');

		try {
			foreach ([
				static fn () => new Instant(0, -1),
				static fn () => Instant::fromDateTime(new \DateTimeImmutable('+10000-01-01T00:00:00Z')),
				static fn () => Instant::__set_state(['epochSeconds' => Instant::MAX_EPOCH_SECONDS + 1, 'nanoseconds' => 0]),
				static fn () => unserialize(str_replace('i:0;', 'i:999999999999999;', serialize(new Instant(0)))),
			] as $construct) {
				try {
					$construct();
					$this->fail('Constructed an invalid instant');
				} catch (\InvalidArgumentException $exception) {
					$frames = array_values(array_filter($exception->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? '') === Instant::class));
					$this->assertNotEmpty($frames);

					foreach ($frames as $frame) {
						$arguments = $frame['args'] ?? [];
						$this->assertNotEmpty($arguments);

						foreach ($arguments as $argument) {
							$this->assertInstanceOf(\SensitiveParameterValue::class, $argument);
						}
					}
				}
			}
		} finally {
			ini_set('zend.exception_ignore_args', $ignoreArguments);
		}
	}

	#[Test]
	public function exportAndSerialize(): void
	{
		$instant = new Instant(-1, 500_000_000);

		$this->assertEquals($instant, eval('return ' . var_export($instant, true) . ';'));
		$this->assertEquals($instant, unserialize(serialize($instant)));

		$this->expectException(\InvalidArgumentException::class);
		$value = unserialize(str_replace('i:-1;', 'i:999999999999999;', serialize($instant)));
		$this->fail('Made ' . get_debug_type($value));
	}

	#[Test]
	public function everyDayMatchesTheCalendar(): void
	{
		// Each day of the first and the last years, and every 61st day in between, compared with the calendar of `DateTimeImmutable`.
		$days = [...range(Instant::MIN_EPOCH_SECONDS, Instant::MIN_EPOCH_SECONDS + 400 * 86_400, 86_400), ...range(Instant::MIN_EPOCH_SECONDS, Instant::MAX_EPOCH_SECONDS, 61 * 86_400), ...range(Instant::MAX_EPOCH_SECONDS - 400 * 86_400, Instant::MAX_EPOCH_SECONDS, 86_400)];

		foreach ($days as $seconds) {
			$text = (string) new Instant($seconds);
			$this->assertSame(new \DateTimeImmutable("@{$seconds}")->format('Y-m-d\TH:i:s\Z'), $text);
			$this->assertEquals(new Instant($seconds), Instant::parse($text));
		}
	}
}
