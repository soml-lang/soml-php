<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\Duration;
use Soml\Instant;
use Soml\Soml;

final class ScalarRefinementTest extends TestCase
{
	#[Test]
	public function extremeDifferencesRemainExactFarFromTheEpoch(): void
	{
		$origin = new Instant(100_000_000_000, 123_456_789);

		foreach ([\PHP_INT_MIN, \PHP_INT_MIN + 1, -1, 0, 1, \PHP_INT_MAX - 1, \PHP_INT_MAX] as $nanoseconds) {
			$shifted = $origin->plus(new Duration($nanoseconds));
			$this->assertSame($nanoseconds, $shifted->difference($origin)->nanoseconds);
			$this->assertEquals($origin, $shifted->minus(new Duration($nanoseconds)));
		}
	}

	#[Test]
	public function differencesRejectOneNanosecondPastEitherIntegerBoundary(): void
	{
		$origin = new Instant(100_000_000_000, 123_456_789);

		foreach ([[\PHP_INT_MIN, -1], [\PHP_INT_MAX, 1]] as [$boundary, $step]) {
			$outside = $origin->plus(new Duration($boundary))->plus(new Duration($step));

			try {
				$duration = $outside->difference($origin);
				$this->fail("An unrepresentable difference became {$duration}");
			} catch (\OverflowException $exception) {
				$this->assertSame('The duration is outside the 64-bit range of nanoseconds', $exception->getMessage());
			}
		}
	}

	#[Test]
	public function allNonzeroIntervalSignsIncludeTheFraction(): void
	{
		foreach ([1, 2, -1, 1000] as $inversion) {
			$interval = new \DateInterval('PT1S');
			$interval->f = 0.25;
			$interval->invert = $inversion;
			$this->assertSame(-1_250_000_000, Duration::fromDateInterval($interval)->nanoseconds);
			$this->assertSame('-1.25s', Soml::encodeValue($interval));
		}
	}

	#[Test]
	public function lookupPreservesNativePropertyNamesAndObjectIdentity(): void
	{
		$shared = (object) ['value' => null];
		$value = (object) ['01' => $shared, '1' => 1, "\0leading" => 2, "middle\0nul" => 3, '' => 4];
		$this->assertSame($shared, Soml::get($value, ['01']));
		$this->assertNull(Soml::get($value, ['01', 'value']));
		$this->assertSame(1, Soml::get($value, ['1']));
		$this->assertSame(2, Soml::get($value, ["\0leading"]));
		$this->assertSame(3, Soml::get($value, ["middle\0nul"]));
		$this->assertSame(4, Soml::get($value, ['']));
		$this->assertSame($shared, $value->{'01'});
	}
}
