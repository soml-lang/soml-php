<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Duration;

final class DurationTest extends TestCase
{
	/** @return iterable<string, array{string, int}> */
	public static function longExactFractions(): iterable
	{
		$zeros = str_repeat('0', 100_000);
		yield 'hours at thirteen digits' => ['0.0000000000025' . $zeros . 'h', 9];
		yield 'minutes with underscores' => ['0.000_000_000_05' . str_repeat('_0', 100_000) . 'm', 3];
		yield 'seconds' => ['1.000000001' . $zeros . 's', 1_000_000_001];
		yield 'milliseconds' => ['0.000001' . $zeros . 'ms', 1];
		yield 'microseconds' => ['0.001' . $zeros . 'us', 1];
		yield 'whole nanoseconds' => ['1.' . $zeros . 'ns', 1];
		yield 'positive zero' => ['0.' . $zeros . 's', 0];
		yield 'negative hours' => ['-0.0000000000025' . $zeros . 'h', -9];
		yield 'maximum separated integer' => ['9_223_372_036_854_775_807ns', \PHP_INT_MAX];
		yield 'minimum separated integer' => ['-9_223_372_036_854_775_808ns', \PHP_INT_MIN];
	}

	#[Test]
	#[DataProvider('longExactFractions')]
	public function boundedFractionStorageKeepsExactNanoseconds(string $text, int $nanoseconds): void
	{
		$duration = Duration::parse($text);
		$this->assertSame($nanoseconds, $duration->nanoseconds);
		$this->assertSame($nanoseconds, Duration::parse((string) $duration)->nanoseconds);
	}

	#[Test]
	public function aLateNonzeroDigitCannotBeLostWhenTrimmingFractionZeros(): void
	{
		$this->expectException(DecodeException::class);
		$this->expectExceptionMessage('not a whole number of nanoseconds');
		Duration::parse('1.000000001' . str_repeat('0', 100_000) . '1s');
	}

	#[Test]
	public function aHugeFractionStillReportsItsGrammarErrorBeforePrecision(): void
	{
		$this->expectException(DecodeException::class);
		$this->expectExceptionMessage('only the last part may have a fraction');
		Duration::parse('1.' . str_repeat('0', 100_000) . '1h1s');
	}

	#[Test]
	public function halfNanosecondTiesRoundAwayFromZero(): void
	{
		$this->assertSame(1, Duration::fromSeconds(0.5e-9)->nanoseconds);
		$this->assertSame(-1, Duration::fromSeconds(-0.5e-9)->nanoseconds);
		$this->assertSame(0, Duration::fromSeconds(0.499e-9)->nanoseconds);
		$this->assertSame(0, Duration::fromSeconds(-0.499e-9)->nanoseconds);
	}

	#[Test]
	public function submicrosecondIntervalsTruncateToZeroForBothSigns(): void
	{
		foreach ([999, -999] as $nanoseconds) {
			$interval = new Duration($nanoseconds)->toDateInterval();
			$this->assertSame(0.0, $interval->f);
			$this->assertSame(0, Duration::fromDateInterval($interval)->nanoseconds);
			$this->assertEquals(new \DateTimeImmutable('@0'), new \DateTimeImmutable('@0')->add($interval));
		}
	}

	#[Test]
	public function negativeIntervalSecondsCannotBeCancelledByPositiveFractions(): void
	{
		$interval = new \DateInterval('PT0S');
		$interval->s = -1;
		$interval->f = 0.5;
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('A DateInterval with negative components');
		$value = Duration::fromDateInterval($interval);
	}

	#[Test]
	public function intervalSignMatchesNativeDateArithmetic(): void
	{
		foreach ([0, 1, 2, -1] as $inversion) {
			$interval = new \DateInterval('PT1S');
			$interval->invert = $inversion;
			$epoch = new \DateTimeImmutable('@0');
			$this->assertSame($epoch->add($interval)->getTimestamp() * Duration::SECOND, Duration::fromDateInterval($interval)->nanoseconds);
		}
	}

	#[Test]
	public function canonicalSpelling(): void
	{
		$cases = [
			'0s' => 0,
			'0.000000001s' => 1,
			'0.00025s' => 250 * Duration::MICROSECOND,
			'1.5s' => 1500 * Duration::MILLISECOND,
			'1h30m' => 90 * Duration::MINUTE,
			'-0.25s' => -250 * Duration::MILLISECOND,
			'1h0.5s' => Duration::HOUR + 500 * Duration::MILLISECOND,
			'2562047h47m16.854775807s' => \PHP_INT_MAX,
			'-2562047h47m16.854775808s' => \PHP_INT_MIN,
		];

		foreach ($cases as $text => $nanoseconds) {
			$this->assertSame($text, (string) new Duration($nanoseconds));
			$this->assertEquals(new Duration($nanoseconds), Duration::parse($text));
		}

		$this->assertSame('"1h30m"', json_encode(new Duration(90 * Duration::MINUTE)));
	}

	#[Test]
	public function parse(): void
	{
		$this->assertEquals(new Duration(90 * Duration::MINUTE), Duration::parse('90m'));
		$this->assertEquals(new Duration(90 * Duration::MINUTE), Duration::parse('1.5h'));

		try {
			$duration = Duration::parse('90');
			$this->fail("Parsed as {$duration}");
		} catch (DecodeException $exception) {
			$this->assertSame('“90” is not a duration. A duration is a number with a unit of h, m, s, ms, us, or ns, as in 1h30m', $exception->reason);
		}

		try {
			$duration = Duration::parse('1d');
			$this->fail("Parsed as {$duration}");
		} catch (DecodeException $exception) {
			$this->assertSame('Invalid duration 1d: there is no day unit, because a day is not a fixed length. Write 24h for a fixed 24 hours', $exception->reason);
		}

		try {
			$duration = Duration::parse('1h ');
			$this->fail("Parsed as {$duration}");
		} catch (DecodeException $exception) {
			$this->assertSame('Unexpected “ ” after the value', $exception->reason);
			$this->assertSame(3, $exception->columnNumber);
		}
	}

	#[Test]
	public function comparison(): void
	{
		$this->assertEquals(Duration::parse('90m'), Duration::parse('1h30m'));
		$this->assertTrue(Duration::parse('1m') < Duration::parse('61s'));
		$this->assertTrue(Duration::parse('-1h') < Duration::parse('0s'));
	}

	#[Test]
	public function seconds(): void
	{
		$this->assertSame(90.0, Duration::parse('1m30s')->toSeconds());
		$this->assertSame(-0.25, Duration::parse('-250ms')->toSeconds());
		$this->assertEquals(new Duration(90 * Duration::SECOND), Duration::fromSeconds(90));
		$this->assertEquals(new Duration(100 * Duration::MILLISECOND), Duration::fromSeconds(0.1));
		$this->assertEquals(new Duration(-1500 * Duration::MILLISECOND), Duration::fromSeconds(-1.5));

		foreach ([\INF, \NAN, 1e10, -1e10, 9_223_372_037] as $seconds) {
			try {
				$duration = Duration::fromSeconds($seconds);
				$this->fail("Made {$duration}");
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	#[Test]
	public function largeFractionalSecondsRoundToTheNearestNanosecond(): void
	{
		// At one billion seconds, the next float is 119.20928955078125 nanoseconds later.
		$this->assertSame(1_000_000_000_000_000_119, Duration::fromSeconds(1_000_000_000.0000001)->nanoseconds);
		$this->assertSame(-1_000_000_000_000_000_119, Duration::fromSeconds(-1_000_000_000.0000001)->nanoseconds);
	}

	#[Test]
	public function secondsNearTheInt64Limits(): void
	{
		$this->assertSame(9_223_372_036_000_000_000, Duration::fromSeconds(9_223_372_036)->nanoseconds);
		$this->assertSame(-9_223_372_036_000_000_000, Duration::fromSeconds(-9_223_372_036)->nanoseconds);
		$this->assertSame(9_223_372_036_854_774_475, Duration::fromSeconds(9_223_372_036.854774)->nanoseconds);
		$this->assertSame(-9_223_372_036_854_774_475, Duration::fromSeconds(-9_223_372_036.854774)->nanoseconds);

		foreach ([9_223_372_037, -9_223_372_037, 9_223_372_036.854776, -9_223_372_036.854776] as $seconds) {
			try {
				$value = Duration::fromSeconds($seconds);
				$this->fail("Accepted {$seconds} seconds");
			} catch (\InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}

	#[Test]
	public function dateIntervalKeepsEveryMicrosecond(): void
	{
		// PHP keeps `DateInterval::$f` as whole microseconds by truncating `$f * 1e6`, which is just below the int for about 1% of the values.
		for ($microseconds = 0; $microseconds < 1_000_000; $microseconds++) {
			foreach ([1, -1] as $sign) {
				$nanoseconds = $sign * (Duration::SECOND + $microseconds * Duration::MICROSECOND + 999);

				if (Duration::fromDateInterval(new Duration($nanoseconds)->toDateInterval())->nanoseconds !== $nanoseconds - $sign * 999) {
					$this->fail("{$nanoseconds} ns lost a microsecond");
				}
			}
		}

		$this->assertSame('2026-01-01T00:00:01.128097', new \DateTimeImmutable('2026-01-01T00:00:00Z')->add(new Duration(1_128_097_591)->toDateInterval())->format('Y-m-d\TH:i:s.u'));
	}

	#[Test]
	public function dateIntervalKeepsTheWholeRange(): void
	{
		foreach ([\PHP_INT_MAX, \PHP_INT_MIN, 9_223_372_036 * Duration::SECOND, -9_223_372_036 * Duration::SECOND] as $nanoseconds) {
			$expected = intdiv($nanoseconds, Duration::MICROSECOND) * Duration::MICROSECOND;
			$this->assertSame($expected, Duration::fromDateInterval(new Duration($nanoseconds)->toDateInterval())->nanoseconds, (string) $nanoseconds);
		}

		$interval = new \DateInterval('PT9223372037S');
		$this->expectException(\InvalidArgumentException::class);
		$duration = Duration::fromDateInterval($interval);
		$this->fail("Made {$duration}");
	}

	#[Test]
	public function exportAndSerialize(): void
	{
		$duration = new Duration(\PHP_INT_MIN);

		$this->assertEquals($duration, eval('return ' . var_export($duration, true) . ';'));
		$this->assertEquals($duration, unserialize(serialize($duration)));
	}

	#[Test]
	public function dateIntervalAcrossDstIsRejectedOrExact(): void
	{
		foreach ([
			['Europe/Dublin', '2026-10-25T01:30:00+01:00', '2026-10-25T01:00:00+00:00'],
			['Australia/Lord_Howe', '2026-10-04T01:30:00+10:30', '2026-10-04T02:30:00+11:00'],
		] as [$identifier, $startText, $endText]) {
			$zone = new \DateTimeZone($identifier);
			$start = new \DateTimeImmutable($startText)->setTimezone($zone);
			$end = new \DateTimeImmutable($endText)->setTimezone($zone);

			foreach ([[$start, $end], [$end, $start]] as [$from, $to]) {
				$elapsed = ($to->getTimestamp() - $from->getTimestamp()) * Duration::SECOND;
				$utc = new \DateTimeZone('UTC');
				$this->assertSame($elapsed, Duration::fromDateInterval($from->setTimezone($utc)->diff($to->setTimezone($utc)))->nanoseconds);

				// Whether a local diff has negative components depends on the PHP build and its timezone database, so it must be either rejected or exact, never a wrong length.
				try {
					$this->assertSame($elapsed, Duration::fromDateInterval($from->diff($to))->nanoseconds);
				} catch (\InvalidArgumentException $exception) {
					$this->assertStringStartsWith('A DateInterval with negative components', $exception->getMessage());
				}
			}
		}
	}

	#[Test]
	public function conversionTraceHidesRelativeIntervalText(): void
	{
		$ignoreArguments = (string) ini_get('zend.exception_ignore_args');
		ini_set('zend.exception_ignore_args', '0');

		try {
			$value = Duration::fromDateInterval(\DateInterval::createFromDateString('1 hour'));
			$this->fail('Converted a relative interval');
		} catch (\InvalidArgumentException $exception) {
			$frames = array_values(array_filter($exception->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? '') === Duration::class));
			$this->assertNotEmpty($frames);

			foreach ($frames as $frame) {
				$arguments = $frame['args'] ?? [];
				$this->assertNotEmpty($arguments);
				$this->assertInstanceOf(\SensitiveParameterValue::class, $arguments[0]);
				$this->assertStringNotContainsString('1 hour', print_r($arguments, true));
			}
		} finally {
			ini_set('zend.exception_ignore_args', $ignoreArguments);
		}
	}

	#[Test]
	public function secondsConversionTraceHidesValue(): void
	{
		$ignoreArguments = (string) ini_get('zend.exception_ignore_args');
		ini_set('zend.exception_ignore_args', '0');

		try {
			$value = Duration::fromSeconds(\INF);
			$this->fail('Converted infinite seconds');
		} catch (\InvalidArgumentException $exception) {
			$frames = array_values(array_filter($exception->getTrace(), static fn (array $frame): bool => ($frame['class'] ?? '') === Duration::class));
			$this->assertNotEmpty($frames);

			foreach ($frames as $frame) {
				$arguments = $frame['args'] ?? [];
				$this->assertNotEmpty($arguments);
				$this->assertInstanceOf(\SensitiveParameterValue::class, $arguments[0]);
			}
		} finally {
			ini_set('zend.exception_ignore_args', $ignoreArguments);
		}
	}

	#[Test]
	public function oversizedDateIntervalFractionIsRejected(): void
	{
		$interval = new \DateInterval('PT0S');
		$interval->f = 10_000_000_000;
		$this->expectException(\InvalidArgumentException::class);
		$value = Duration::fromDateInterval($interval);
	}

	#[Test]
	public function dateIntervalConversion(): void
	{
		$this->assertEquals(new Duration(90 * Duration::MINUTE), Duration::fromDateInterval(new \DateInterval('PT1H30M')));
		$this->assertEquals(new Duration(36 * Duration::HOUR), Duration::fromDateInterval(new \DateInterval('PT36H')));

		$interval = new \DateTimeImmutable('2026-01-01T00:00:00.5Z')->diff(new \DateTimeImmutable('2026-01-01T00:00:00Z'));
		$this->assertEquals(new Duration(-500 * Duration::MILLISECOND), Duration::fromDateInterval($interval));

		$interval = Duration::parse('-1h30m0.000001999s')->toDateInterval();
		$this->assertSame([1, 30, 0, 0.000001, 1], [$interval->h, $interval->i, $interval->s, $interval->f, $interval->invert]);
		$this->assertSame('2026-01-01T01:29:59.999999', new \DateTimeImmutable('2026-01-01T03:00:00Z')->add($interval)->format('Y-m-d\TH:i:s.u'));

		foreach (['next monday', '1 hour'] as $text) {
			try {
				$duration = Duration::fromDateInterval(\DateInterval::createFromDateString($text));
				$this->fail("Made {$duration}");
			} catch (\InvalidArgumentException $exception) {
				$this->assertStringStartsWith('A DateInterval made from a relative date string', $exception->getMessage());
			}
		}

		$this->expectException(\InvalidArgumentException::class);
		$duration = Duration::fromDateInterval(new \DateInterval('P1M'));
		$this->fail("Made {$duration}");
	}
}
