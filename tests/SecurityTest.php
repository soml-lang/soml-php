<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\DecodeException;
use Soml\Soml;

final class SecurityTest extends TestCase
{
	#[Test]
	public function failedFileReadRedactsTheErrorHandlersPathArgument(): void
	{
		$previous = (string) ini_get('zend.exception_ignore_args');
		ini_set('zend.exception_ignore_args', '0');

		try {
			try {
				Soml::decodeFile(sys_get_temp_dir() . '/soml-missing-path-secret-' . bin2hex(random_bytes(8)));
				$this->fail('A nonexistent file was read');
			} catch (\RuntimeException $exception) {
				$this->assertStringContainsString('Cannot read', $exception->getMessage());
				$checkedFrames = 0;

				foreach ($exception->getTrace() as $frame) {
					if (($frame['class'] ?? '') === Soml::class) {
						$this->assertStringNotContainsString('path-secret', print_r($frame['args'] ?? [], true));
						$checkedFrames++;
					}
				}

				$this->assertSame(2, $checkedFrames);
			}
		} finally {
			ini_set('zend.exception_ignore_args', $previous);
		}
	}

	/** @return iterable<string, array{string}> */
	public static function invisibleCharacters(): iterable
	{
		yield 'soft hyphen' => ["\u{AD}"];
		yield 'zero width space' => ["\u{200B}"];
		yield 'word joiner' => ["\u{2060}"];
		yield 'interior byte order mark' => ["\u{FEFF}"];
		yield 'interlinear annotation anchor' => ["\u{FFF9}"];
		yield 'combining grapheme joiner' => ["\u{34F}"];
		yield 'variation selector' => ["\u{FE0F}"];
		yield 'supplementary variation selector' => ["\u{E0100}"];
		yield 'hangul filler' => ["\u{3164}"];
		yield 'mongolian variation selector' => ["\u{180B}"];
	}

	#[Test]
	#[DataProvider('invisibleCharacters')]
	public function invisibleFormatCharactersCannotHideDiagnosticText(string $character): void
	{
		try {
			Soml::decode("'prefix{$character}suffix': 1\n'prefix{$character}suffix': 2");
			$this->fail('A duplicate key was accepted');
		} catch (DecodeException $exception) {
			$this->assertStringNotContainsString($character, $exception->getMessage());
			$this->assertStringNotContainsString($character, $exception->codeFrame);
			$this->assertStringContainsString("prefix\u{FFFD}suffix", $exception->reason);
			$this->assertSame([2, 1], [$exception->lineNumber, $exception->columnNumber]);
		}
	}

	#[Test]
	#[DataProvider('invisibleCharacters')]
	public function diagnosticSanitizationDoesNotChangeStoredValues(string $character): void
	{
		$value = ['key' . $character => 'value' . $character];
		$this->assertSame($value, Soml::decode(Soml::encode($value, canonical: true), associative: true));
		$this->assertSame($value, Soml::decode(Soml::format(Soml::encode($value)), associative: true));
	}

	#[Test]
	public function decodeFilePathsAreRedactedInEveryLibraryStackFrame(): void
	{
		$previous = (string) ini_get('zend.exception_ignore_args');
		ini_set('zend.exception_ignore_args', '0');
		$filePath = '/private/config-path-secret.soml';

		try {
			foreach (["a: '\xFF'", 'a: 01'] as $source) {
				try {
					$result = \Soml\Decoder::decode($source, false, $filePath);
					$this->fail('An invalid document was accepted');
				} catch (DecodeException $exception) {
					$this->assertSame($filePath, $exception->filePath);
					$checkedFrames = 0;

					foreach ($exception->getTrace() as $frame) {
						$class = $frame['class'] ?? '';

						if (str_starts_with($class, 'Soml\\') && !str_starts_with($class, __NAMESPACE__)) {
							$this->assertStringNotContainsString('config-path-secret', print_r($frame['args'] ?? [], true));
							$checkedFrames++;
						}
					}

					$this->assertGreaterThan(2, $checkedFrames);
				}
			}
		} finally {
			ini_set('zend.exception_ignore_args', $previous);
		}
	}

	#[Test]
	public function unsupportedValuesAreHiddenInEveryEncoderStackFrame(): void
	{
		$previous = (string) ini_get('zend.exception_ignore_args');
		ini_set('zend.exception_ignore_args', '0');

		try {
			try {
				$result = Soml::encode(['a' => new \ArrayObject(['secret' => 'encoder-secret'])]);
				$this->fail('An unsupported object was accepted');
			} catch (\Soml\EncodeException $exception) {
				foreach ($exception->getTrace() as $frame) {
					$class = $frame['class'] ?? '';

					if (str_starts_with($class, 'Soml\\') && !str_starts_with($class, __NAMESPACE__)) {
						$this->assertStringNotContainsString('encoder-secret', print_r($frame['args'] ?? [], true));
					}
				}
			}
		} finally {
			ini_set('zend.exception_ignore_args', $previous);
		}
	}

	#[Test]
	public function invalidEditPathsAreHiddenInLibraryStackFrames(): void
	{
		$previous = (string) ini_get('zend.exception_ignore_args');
		ini_set('zend.exception_ignore_args', '0');

		try {
			try {
				$result = Soml::edit('{}', ["path-secret\r"], 'replacement-secret');
				$this->fail('An invalid key was accepted');
			} catch (\InvalidArgumentException $exception) {
				foreach ($exception->getTrace() as $frame) {
					if (($frame['class'] ?? '') === Soml::class || ($frame['class'] ?? '') === \Soml\Editor::class) {
						$arguments = $frame['args'] ?? [];
						$this->assertNotEmpty($arguments);
						$this->assertStringNotContainsString('-secret', print_r($arguments, true));
					}
				}
			}
		} finally {
			ini_set('zend.exception_ignore_args', $previous);
		}
	}
}
