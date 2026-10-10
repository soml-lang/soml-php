<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\Duration;
use Soml\Instant;
use Soml\Soml;

final class ScalarHardeningTest extends TestCase
{
	#[Test]
	public function intervalConversionDoesNotReadUnrelatedPropertyHooks(): void
	{
		$interval = new class ('PT1S') extends \DateInterval {
			public string $secret {
				get => throw new \LogicException('An unrelated hook was executed');
			}
		};

		$this->assertSame(Duration::SECOND, Duration::fromDateInterval($interval)->nanoseconds);
		$this->assertSame('1s', Soml::encodeValue($interval));
	}

	#[Test]
	public function nativeTimestampConversionIsIndependentOfTheDefaultTimezone(): void
	{
		$previous = date_default_timezone_get();
		date_default_timezone_set('Pacific/Kiritimati');

		try {
			foreach ([Instant::MIN_EPOCH_SECONDS, -1, 0, Instant::MAX_EPOCH_SECONDS] as $seconds) {
				foreach ([0, 999, 1000, 999_999_999] as $nanoseconds) {
					$instant = new Instant($seconds, $nanoseconds);
					$dateTime = $instant->toDateTimeImmutable();
					$this->assertSame($seconds, $dateTime->getTimestamp());
					$this->assertSame(intdiv($nanoseconds, Duration::MICROSECOND), $dateTime->getMicrosecond());
					$this->assertSame('UTC', $dateTime->getTimezone()->getName());
					$this->assertEquals(new Instant($seconds, intdiv($nanoseconds, Duration::MICROSECOND) * Duration::MICROSECOND), Instant::fromDateTime($dateTime));
				}
			}
		} finally {
			date_default_timezone_set($previous);
		}
	}

	#[Test]
	public function negationRejectsTheOnlyUnrepresentableMagnitude(): void
	{
		foreach ([\PHP_INT_MIN + 1, -1, 0, 1, \PHP_INT_MAX] as $nanoseconds) {
			$duration = new Duration($nanoseconds);
			$this->assertSame(-$nanoseconds, $duration->negated()->nanoseconds);
			$this->assertSame($nanoseconds, $duration->negated()->negated()->nanoseconds);
		}

		$this->expectException(\OverflowException::class);
		$value = new Duration(\PHP_INT_MIN)->negated();
	}
}
