<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\EncodeException;
use Soml\Soml;

final class EncoderHardeningTest extends TestCase
{
	#[Test]
	public function anInvalidUtf8RetryDoesNotRetainTheFirstLargeOutput(): void
	{
		$value = ['payload' => str_repeat('x', 5_000_000), 'bad' => "\xFF"];
		$this->assertSame("ok: 1\n", Soml::encode(['ok' => 1]));
		$before = memory_get_usage();
		memory_reset_peak_usage();

		try {
			$output = Soml::encode($value);
			$this->fail('An invalid UTF-8 value was encoded');
		} catch (EncodeException $exception) {
			$this->assertSame(['bad'], $exception->path);
			$this->assertLessThan(12_000_000, memory_get_peak_usage() - $before);
		}
	}

	#[Test]
	public function utf8RetryReturnsTheValidatedOutputWhenASupportedDateTimeChangesItsContainer(): void
	{
		foreach ([false, true] as $canonical) {
			$value = (object) ['a' => "\xFF"];
			$value->date = new class ($value) extends \DateTimeImmutable {
				public function __construct(private \stdClass $container)
				{
					parent::__construct('2000-01-01T00:00:00Z');
				}

				public function format(string $format): string
				{
					$this->container->a = 'fixed';
					return parent::format($format);
				}
			};

			$output = Soml::encode($value, canonical: $canonical);
			$this->assertSame(1, preg_match('//u', $output));
			$this->assertSame('fixed', Soml::get(Soml::decode($output), ['a']));
		}
	}
}
