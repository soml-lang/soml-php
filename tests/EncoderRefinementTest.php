<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\EncodeException;
use Soml\Soml;

final class EncoderRefinementTest extends TestCase
{
	#[Test]
	public function encodingRejectsObjectSubclassHooksBeforeTheyRun(): void
	{
		$value = new class extends \stdClass {
			public int $secret {
				get => throw new \LogicException('A property hook ran');
			}
		};

		foreach ([['nested' => $value], $value] as $document) {
			try {
				$result = Soml::encode($document);
				$this->fail('An unsupported subclass was encoded');
			} catch (EncodeException $exception) {
				$this->assertStringContainsString('subclass', $exception->reason);
			}
		}
	}

	#[Test]
	public function fragmentsRejectSubclassHooksInEveryLayout(): void
	{
		$value = new class extends \stdClass {
			public string $token {
				get => throw new \LogicException('A property hook ran');
			}
		};

		foreach ([false, true] as $canonical) {
			foreach ([false, true] as $oneLine) {
				foreach ([$value, ['items' => [$value]]] as $index => $fragment) {
					try {
						$result = Soml::encodeValue($fragment, canonical: $canonical, oneLine: $oneLine);
						$this->fail('An unsupported subclass was encoded');
					} catch (EncodeException $exception) {
						$this->assertStringContainsString('subclasses are unsupported', $exception->reason);
						$this->assertSame($index === 0 ? [] : ['items', 0], $exception->path);
					}
				}
			}
		}
	}

	/**
	@return iterable<string, array{mixed, list<string|int>, string}>
	*/
	public static function invalidUtf8Fragments(): iterable
	{
		yield 'root scalar' => ["\xFF", [], 'string'];
		yield 'split sequence across list items' => [["\xC3", "\xA9"], [0], 'string'];
		yield 'split sequence across object members' => [['first' => "\xC3", 'second' => "\xA9"], ['first'], 'string'];
		yield 'quoted numeric property' => [['items' => [(object) ['001' => "\xED\xA0\x80"]]], ['items', 0, '001'], 'string'];
		yield 'nested invalid key' => [['items' => [["\xF4\x90\x80\x80" => 1]]], ['items', 0], 'key'];
		yield 'root invalid key' => [["\xC0\xAF" => 1], [], 'key'];
		yield 'invalid string requiring escapes' => [["it's\t\xFF"], [0], 'string'];
	}

	/**
	@param list<string|int> $path
	*/
	#[Test]
	#[DataProvider('invalidUtf8Fragments')]
	public function fragmentUtf8ErrorsRetainTheirPathsInEveryLayout(mixed $value, array $path, string $kind): void
	{
		foreach ([false, true] as $canonical) {
			foreach ([false, true] as $oneLine) {
				try {
					$result = Soml::encodeValue($value, canonical: $canonical, oneLine: $oneLine);
					$this->fail('An invalid UTF-8 fragment was encoded');
				} catch (EncodeException $exception) {
					$this->assertSame("Cannot serialize a {$kind} that is not valid UTF-8", $exception->reason);
					$this->assertSame($path, $exception->path);
				}
			}
		}
	}

	#[Test]
	public function fragmentStringEscapingRoundTripsEveryControlCharacter(): void
	{
		$value = '';

		for ($code = 0; $code < 32; $code++) {
			if ($code !== 13) {
				$value .= chr($code);
			}
		}

		$value .= "\x7F'\"\\😀";
		$fragment = Soml::encodeValue($value, oneLine: true);
		$this->assertStringNotContainsString("\n", $fragment);
		$this->assertSame($value, Soml::decodeValue($fragment));
	}
}
