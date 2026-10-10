<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\Duration;
use Soml\Instant;

final class ValueOperationsTest extends TestCase
{
	/** @return iterable<string, array{\Closure(): mixed}> */
	public static function overflowingDurations(): iterable
	{
		yield 'positive addition' => [static fn () => new Duration(\PHP_INT_MAX)->plus(new Duration(1))];
		yield 'negative addition' => [static fn () => new Duration(\PHP_INT_MIN)->plus(new Duration(-1))];
		yield 'positive subtraction' => [static fn () => new Duration(\PHP_INT_MAX)->minus(new Duration(-1))];
		yield 'negative subtraction' => [static fn () => new Duration(\PHP_INT_MIN)->minus(new Duration(1))];
		yield 'multiplication' => [static fn () => new Duration(\PHP_INT_MAX)->multipliedBy(2)];
		yield 'negative multiplication' => [static fn () => new Duration(\PHP_INT_MIN)->multipliedBy(-1)];
		yield 'negation' => [static fn () => new Duration(\PHP_INT_MIN)->negated()];
		yield 'absolute magnitude' => [static fn () => new Duration(\PHP_INT_MIN)->absolute()];
		yield 'hours' => [static fn () => Duration::fromHours(\PHP_INT_MAX)];
		yield 'minutes' => [static fn () => Duration::fromMinutes(\PHP_INT_MIN)];
		yield 'milliseconds' => [static fn () => Duration::fromMilliseconds(\PHP_INT_MAX)];
		yield 'microseconds' => [static fn () => Duration::fromMicroseconds(\PHP_INT_MIN)];
	}

	#[Test]
	#[DataProvider('overflowingDurations')]
	public function exactArithmeticNeverPromotesAnOverflowToFloat(\Closure $operation): void
	{
		$this->expectException(\OverflowException::class);
		$result = $operation();
	}

	#[Test]
	public function exactOperationsPreserveBoundaryNanosecondsAndTheirOperands(): void
	{
		$minimum = new Duration(\PHP_INT_MIN);
		$maximum = new Duration(\PHP_INT_MAX);
		$this->assertSame(0, $minimum->minus($minimum)->nanoseconds);
		$this->assertSame(-1, $minimum->plus($maximum)->nanoseconds);
		$this->assertSame(\PHP_INT_MIN, $minimum->multipliedBy(1)->nanoseconds);
		$this->assertSame(0, $minimum->multipliedBy(0)->nanoseconds);
		$this->assertSame(\PHP_INT_MAX, $maximum->negated()->absolute()->nanoseconds);
		$this->assertSame(\PHP_INT_MIN, $minimum->nanoseconds);
		$this->assertSame(\PHP_INT_MAX, $maximum->nanoseconds);
	}

	#[Test]
	public function scaledFactoriesUseExactIntegerArithmetic(): void
	{
		$this->assertSame(1_234_567_890_123_000, Duration::fromMicroseconds(1_234_567_890_123)->nanoseconds);
		$this->assertSame(-1_234_567_890_000_000, Duration::fromMilliseconds(-1_234_567_890)->nanoseconds);
		$this->assertSame('1h30m', (string) Duration::fromMinutes(90));
		$this->assertSame('-2h', (string) Duration::fromHours(-2));
	}

	/** @return iterable<string, array{int}> */
	public static function epochNanoseconds(): iterable
	{
		foreach ([\PHP_INT_MIN, \PHP_INT_MIN + 1, -1_000_000_001, -1_000_000_000, -999_999_999, -1001, -1000, -999, -1, 0, 1, 999, 1000, 1001, 999_999_999, 1_000_000_000, 1_000_000_001, \PHP_INT_MAX - 1, \PHP_INT_MAX] as $value) {
			yield (string) $value => [$value];
		}
	}

	#[Test]
	#[DataProvider('epochNanoseconds')]
	public function epochConversionsAndShiftsRoundTripAcrossTheWholeIntegerRange(int $nanoseconds): void
	{
		$epoch = new Instant(0);
		$duration = new Duration($nanoseconds);
		$instant = Instant::fromEpochNanoseconds($nanoseconds);
		$this->assertSame($nanoseconds, $instant->toEpochNanoseconds());
		$this->assertEquals($instant, $epoch->plus($duration));
		$this->assertEquals($epoch, $instant->minus($duration));
		$this->assertSame($nanoseconds, $instant->difference($epoch)->nanoseconds);
		$this->assertGreaterThanOrEqual(0, $instant->nanoseconds);
		$this->assertLessThan(Duration::SECOND, $instant->nanoseconds);
	}

	#[Test]
	public function timestampTruncationRoundsDownBeforeTheEpoch(): void
	{
		$instant = new Instant(-1, 999_999_999);
		$this->assertSame(-1, $instant->toEpochMilliseconds());
		$this->assertSame(-1, $instant->toEpochMicroseconds());
		$this->assertSame('1969-12-31T23:59:59.999Z', (string) Instant::fromEpochMilliseconds(-1));
		$this->assertSame('1969-12-31T23:59:59.999999Z', (string) Instant::fromEpochMicroseconds(-1));

		foreach ([Instant::MIN_EPOCH_SECONDS, Instant::MAX_EPOCH_SECONDS] as $seconds) {
			$instant = new Instant($seconds, 999_999_999);
			$this->assertSame($seconds, Instant::fromEpochMilliseconds($instant->toEpochMilliseconds())->epochSeconds);
			$this->assertSame($seconds, Instant::fromEpochMicroseconds($instant->toEpochMicroseconds())->epochSeconds);
		}
	}

	/** @return iterable<string, array{\Closure(): mixed, class-string<\Throwable>}> */
	public static function unrepresentableInstants(): iterable
	{
		yield 'add past last nanosecond' => [static fn () => new Instant(Instant::MAX_EPOCH_SECONDS, 999_999_999)->plus(new Duration(1)), \InvalidArgumentException::class];
		yield 'subtract before first nanosecond' => [static fn () => new Instant(Instant::MIN_EPOCH_SECONDS)->minus(new Duration(1)), \InvalidArgumentException::class];
		yield 'millisecond timestamp outside civil range' => [static fn () => Instant::fromEpochMilliseconds(\PHP_INT_MAX), \InvalidArgumentException::class];
		yield 'microsecond timestamp outside civil range' => [static fn () => Instant::fromEpochMicroseconds(\PHP_INT_MIN), \InvalidArgumentException::class];
		yield 'difference beyond duration range' => [static fn () => new Instant(Instant::MAX_EPOCH_SECONDS)->difference(new Instant(Instant::MIN_EPOCH_SECONDS)), \OverflowException::class];
		yield 'nanosecond timestamp beyond integer range' => [static fn () => new Instant(Instant::MAX_EPOCH_SECONDS)->toEpochNanoseconds(), \OverflowException::class];
	}

	/** @param class-string<\Throwable> $exception */
	#[Test]
	#[DataProvider('unrepresentableInstants')]
	public function invalidResultsAreRejectedWithoutRounding(\Closure $operation, string $exception): void
	{
		$this->expectException($exception);
		$result = $operation();
	}

	#[Test]
	public function instantArithmeticAgreesWithIntegerNanosecondsForSeededValues(): void
	{
		$random = new \Random\Randomizer(new \Random\Engine\Mt19937(1313));

		for ($index = 0; $index < 500; $index++) {
			$before = $random->getInt(-1_000_000_000_000_000, 1_000_000_000_000_000);
			$delta = $random->getInt(-1_000_000_000_000_000, 1_000_000_000_000_000);
			$instant = Instant::fromEpochNanoseconds($before);
			$shifted = $instant->plus(new Duration($delta));
			$this->assertSame($before + $delta, $shifted->toEpochNanoseconds());
			$this->assertSame($delta, $shifted->difference($instant)->nanoseconds);
			$this->assertEquals($instant, $shifted->minus(new Duration($delta)));
		}
	}
}
