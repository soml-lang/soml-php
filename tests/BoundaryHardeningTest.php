<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoundaryHardeningTest extends TestCase
{
	/** @return iterable<string, array{string}> */
	public static function oversizedArguments(): iterable
	{
		yield 'path argument' => ['["get", \'["\' . str_repeat("a", 16_777_216) . \'"]\']'];
		yield 'replacement argument' => ['["set", \'["a"]\', chr(39) . str_repeat("a", 16_777_216) . chr(39)]'];
		yield 'invalid large replacement is bounded before parsing' => ['["set", \'["a"]\', str_repeat("[", 16_777_217)]'];
	}

	#[Test]
	#[DataProvider('oversizedArguments')]
	public function commandArgumentsHaveAFixedBudgetBeforeTheyAreParsed(string $arguments): void
	{
		$code = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . '; exit(\\Soml\\Command::run(' . $arguments . '));';
		[$status, $output, $error] = $this->runProcess([\PHP_BINARY, '-r', $code], 'a: 1');
		$this->assertSame(1, $status);
		$this->assertSame('', $output);
		$this->assertStringContainsString('16777216-byte limit', $error);
	}

	#[Test]
	public function theDocumentBudgetDoesNotRestrictSmallCommandArguments(): void
	{
		[$status, $output, $error] = $this->runCommand(['--max-bytes', '4', 'set', '["a"]', "'😀'"], 'a: 1');
		$this->assertSame(0, $status, $error);
		$this->assertSame("a: '😀'", $output);
	}

	#[Test]
	public function flatPathsRejectNestedJsonBeforeReadingTheDocument(): void
	{
		[$status, $output, $error] = $this->runCommand(['get', '[' . str_repeat('[', 500) . '"a"' . str_repeat(']', 500) . ']'], 'not a valid document');
		$this->assertSame(2, $status);
		$this->assertSame('', $output);
		$this->assertStringContainsString('PATH must be valid JSON', $error);
	}

	/**
	@param list<string> $arguments
	@return array{int, string, string}
	*/
	private function runCommand(array $arguments, string $input): array
	{
		return $this->runProcess([\PHP_BINARY, __DIR__ . '/../bin/soml', ...$arguments], $input);
	}

	/**
	@param non-empty-list<string> $command
	@return array{int, string, string}
	*/
	private function runProcess(array $command, string $input): array
	{
		$process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

		if (!is_resource($process)) {
			throw new \RuntimeException('Cannot start the CLI');
		}

		try {
			fwrite($pipes[0], $input);
			fclose($pipes[0]);
			$output = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
		} finally {
			foreach ($pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}

			$status = proc_close($process);
		}

		if ($output === false || $error === false) {
			throw new \RuntimeException('Cannot read CLI output');
		}

		return [$status, $output, $error];
	}
}
