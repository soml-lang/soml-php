<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\EncodeException;
use Soml\Soml;

final class DiagnosticHardeningTest extends TestCase
{
	#[Test]
	public function mixedEncodingPathsKeepIntegerIndexesAndQuoteUnicodeKeys(): void
	{
		try {
			$text = Soml::encode(['root' => [["é😀\u{202E}" => \NAN]]]);
			$this->fail('A nonfinite value was encoded');
		} catch (EncodeException $exception) {
			$this->assertSame(['root', 0, "é😀\u{202E}"], $exception->path);
			$this->assertStringEndsWith('(at root[0]."é😀\\u{202e}")', $exception->getMessage());
		}
	}

	#[Test]
	public function dateOnlyHintsSuggestInstantsOnlyForExistingGregorianDates(): void
	{
		foreach (['0001-01-01' => true, '0069-12-31' => true, '1582-10-10' => true, '1600-02-29' => true, '1900-02-29' => false, '2000-02-29' => true, '2100-02-29' => false, '2400-02-29' => true, '0000-01-01' => false, '2026-00-10' => false, '2026-13-01' => false, '2026-01-00' => false, '2026-04-31' => false, '9999-12-31' => true] as $date => $exists) {
			try {
				$value = Soml::decodeValue($date);
				$this->fail('A date without a time was accepted as an instant');
			} catch (DecodeException $exception) {
				$expected = "{$date} is a date, not an instant. Write a date as a string, as in '{$date}'";
				$this->assertSame($expected . ($exists ? ". An instant needs a time and an offset, as in {$date}T00:00:00Z" : ''), $exception->reason);
			}
		}
	}

	#[Test]
	public function aLongBarePathIsAbbreviatedBeforeRenderingItsNestedSuffix(): void
	{
		$key = str_repeat('a', 100_000);

		try {
			$text = Soml::encode([$key => ['nested' => \NAN]]);
			$this->fail('A nonfinite value was encoded');
		} catch (EncodeException $exception) {
			$this->assertSame([$key, 'nested'], $exception->path);
			$this->assertStringEndsWith('(at ' . str_repeat('a', 40) . '….nested)', $exception->getMessage());
		}
	}
}
