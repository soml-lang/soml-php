<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\Soml;

final class CommandTest extends TestCase
{
	/** @return iterable<string, array{list<string>, string, int, string}> */
	public static function commands(): iterable
	{
		yield 'quiet validation' => [['validate'], "# comment\na: [1, null]", 0, ''];
		yield 'validation accepts NUL keys' => [['validate'], '"\u{0}key": 1', 0, ''];
		yield 'format preserves spelling' => [['format'], 'a:[0x10,  2,]', 0, "a: [0x10, 2]\n"];
		yield 'canonical order and spelling' => [['canonicalize'], "b: 90m\na: 0x10", 0, "a: 16\nb: 1h30m\n"];
		yield 'lookup a scalar' => [['get', '["a",0]'], 'a: [30s]', 0, "30s\n"];
		yield 'lookup existing null' => [['get', '["a"]'], 'a: null', 0, "null\n"];
		yield 'lookup numeric object key' => [['get', '["0"]'], '0: true', 0, "true\n"];
		yield 'lookup container fragment' => [['get', '["a"]'], 'a: {b: [1, 1.0]}', 0, "{b: [1, 1.0]}\n"];
		yield 'set duration' => [['set', '["timeout"]', '1m'], "timeout: 30s # keep\n", 0, "timeout: 1m # keep\n"];
		yield 'set explicit null' => [['set', '["a"]', 'null'], 'a: 1', 0, 'a: null'];
		yield 'create missing objects' => [['set', '["a","b"]', '[1, true]'], '{}', 0, "{\n\ta: {\n\t\tb: [\n\t\t\t1\n\t\t\ttrue\n\t\t]\n\t}\n}"];
		yield 'append array' => [['set', '[1]', '2'], '[1]', 0, '[1, 2]'];
		yield 'remove member' => [['remove', '["a"]'], "a: 1\nb: 0x10\n", 0, "b: 0x10\n"];
		yield 'missing removal preserves source' => [['remove', '["b"]'], 'a: 0x10', 0, 'a: 0x10'];
		yield 'JSON preserves float type' => [['to-json'], 'a: 1.0', 0, "{\n    \"a\": 1.0\n}\n"];
		yield 'JSON time values are strings' => [['to-json'], 'a: 90m', 0, "{\n    \"a\": \"1h30m\"\n}\n"];
		yield 'read JSON numeric object' => [['from-json'], '{"0":null,"1":[]}', 0, "0: null\n1: []\n"];
		yield 'read JSON float' => [['from-json'], '{"a":1.0}', 0, "a: 1.0\n"];
		yield 'JSON large integer stays exact as a string' => [['from-json'], '{"a":9223372036854775808}', 0, "a: '9223372036854775808'\n"];
		yield 'JSON scalar is not a document' => [['from-json'], 'true', 1, ''];
		yield 'infinity cannot become JSON' => [['to-json'], 'a: infinity', 1, ''];
		yield 'invalid input' => [['validate'], 'a: 01', 1, ''];
		yield 'input beyond limit' => [['--max-bytes', '4', 'validate'], 'a: 12', 1, ''];
		yield 'input exactly at limit' => [['--max-bytes', '4', 'validate'], 'a: 1', 0, ''];
		yield 'invalid numeric limit' => [['--max-bytes', '1.5', 'validate'], '', 2, ''];
		yield 'zero limit' => [['--max-bytes', '0', 'validate'], '', 2, ''];
		yield 'missing limit' => [['--max-bytes'], '', 2, ''];
		yield 'unknown command' => [['unknown'], '', 2, ''];
		yield 'unknown option' => [['--unknown'], '', 2, ''];
		yield 'missing path' => [['get'], '', 2, ''];
		yield 'missing value' => [['set', '["a"]'], '', 2, ''];
		yield 'too many files' => [['format', 'one', 'two'], '', 2, ''];
		yield 'path must be an array' => [['get', '{}'], '', 2, ''];
		yield 'malformed JSON path' => [['get', '['], '', 2, ''];
		yield 'path must be nonempty' => [['get', '[]'], '', 2, ''];
		yield 'negative path index' => [['get', '[-1]'], '', 2, ''];
		yield 'missing lookup' => [['get', '["missing"]'], 'a: 1', 1, ''];
		yield 'remote stream wrapper' => [['validate', 'https://example.invalid/config'], '', 2, ''];
		yield 'PHP stream wrapper' => [['validate', 'php://memory'], '', 2, ''];
		yield 'data wrapper without slashes' => [['validate', 'data:text/plain,a: 1'], '', 2, ''];
		yield 'case insensitive data wrapper' => [['validate', 'DaTa:text/plain,a: 1'], '', 2, ''];
	}

	/** @param list<string> $arguments */
	#[Test]
	#[DataProvider('commands')]
	public function commandsUseTheLibraryContractsAndUsefulExitStatuses(array $arguments, string $input, int $status, string $expected): void
	{
		[$actualStatus, $output, $error] = $this->runCommand($arguments, $input);
		$this->assertSame($status, $actualStatus, $error);
		$this->assertSame($expected, $output);
		$this->assertSame($status === 0, $error === '');
		$this->assertStringNotContainsString('PHP Warning', $error);
		$this->assertStringNotContainsString('Stack trace', $error);
	}

	#[Test]
	public function framesRequireOptInAndCannotEmitTerminalControls(): void
	{
		$source = "a: 1\na: '\u{202e}secret'";
		[$status, $output, $error] = $this->runCommand(['validate'], $source);
		$this->assertSame(1, $status);
		$this->assertSame('', $output);
		$this->assertStringNotContainsString('secret', $error);
		[$status, $output, $error] = $this->runCommand(['--frame', 'validate'], $source);
		$this->assertSame(1, $status);
		$this->assertStringContainsString('secret', $error);
		$this->assertStringNotContainsString("\u{202e}", $error);
		$this->assertStringContainsString('^', $error);
	}

	#[Test]
	public function localFilesWithShellSyntaxAreReadWithoutBeingModified(): void
	{
		$path = sys_get_temp_dir() . '/soml-cli-' . bin2hex(random_bytes(8)) . ' $(never-run) "quoted".soml';
		$source = "b: 0x10\na: true\n";
		file_put_contents($path, $source);

		try {
			[$status, $output, $error] = $this->runCommand(['canonicalize', $path]);
			$this->assertSame(0, $status, $error);
			$this->assertSame("a: true\nb: 16\n", $output);
			$this->assertSame($source, file_get_contents($path));
		} finally {
			unlink($path);
		}
	}

	#[Test]
	public function statisticsDistinguishEveryDecodedTypeAndContainerDepth(): void
	{
		$source = "a: [1, 1.0, true, null, 's', 1ns, 1970-01-01T00:00:00Z, {}]";
		[$status, $output, $error] = $this->runCommand(['stats'], $source);
		$this->assertSame(0, $status, $error);
		$this->assertSame(['bytes' => strlen($source), 'maximumDepth' => 3, 'objects' => 2, 'arrays' => 1, 'keys' => 1, 'strings' => 1, 'integers' => 1, 'floats' => 1, 'booleans' => 1, 'nulls' => 1, 'instants' => 1, 'durations' => 1], json_decode($output, associative: true, flags: \JSON_THROW_ON_ERROR));
	}

	#[Test]
	public function JSONConversionRoundTripsWithoutLosingNumericObjectsOrFloatTypes(): void
	{
		$source = "a: {0: 1.0, 1: [], '': null}\nb: -9223372036854775808";
		[$status, $json, $error] = $this->runCommand(['to-json'], $source);
		$this->assertSame(0, $status, $error);
		[$status, $roundTrip, $error] = $this->runCommand(['from-json'], $json);
		$this->assertSame(0, $status, $error);
		$this->assertSame(Soml::canonicalize($source), Soml::canonicalize($roundTrip));
	}

	#[Test]
	public function aLargeConfiguredLimitDoesNotPreallocateThatManyBytes(): void
	{
		[$status, $output, $error] = $this->runCommand(['--max-bytes', '1000000000', 'validate'], 'a: 1', ['-d', 'memory_limit=8M']);
		$this->assertSame(0, $status, $error);
		$this->assertSame('', $output);
	}

	#[Test]
	public function repeatedOptionsKeepTheirOrderAndLeaveTheCommandArgumentsIntact(): void
	{
		[$status, $output, $error] = $this->runCommand(['--max-bytes', '1', '--frame', '--max-bytes', '4', 'get', '["a"]'], 'a: 1');
		$this->assertSame(0, $status, $error);
		$this->assertSame("1\n", $output);
	}

	#[Test]
	public function inputLimitsReadOnlyTheSingleExtraByteNeededToDetectOverflow(): void
	{
		$file = sys_get_temp_dir() . '/soml-input-limit-' . bin2hex(random_bytes(8));
		file_put_contents($file, 'a: 1' . str_repeat(' ', 10_000));
		$code = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . '; $status = \\Soml\\Command::run(["--max-bytes", "4", "validate"]); echo ftell(STDIN); exit($status);';
		$process = proc_open([\PHP_BINARY, '-r', $code], [0 => ['file', $file, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

		if (!is_resource($process)) {
			unlink($file);
			throw new \RuntimeException('Cannot start the CLI');
		}

		try {
			$output = stream_get_contents($pipes[1]);
			$error = stream_get_contents($pipes[2]);
		} finally {
			fclose($pipes[1]);
			fclose($pipes[2]);
			$status = proc_close($process);
			unlink($file);
		}

		$this->assertSame(1, $status);
		$this->assertSame('5', $output);
		$this->assertIsString($error);
		$this->assertStringContainsString('Input exceeds the 4-byte limit', $error);
	}

	#[Test]
	public function nonBlockingInputThatMakesNoProgressFailsInsteadOfSpinning(): void
	{
		$code = 'require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . '; stream_set_blocking(STDIN, false); exit(\\Soml\\Command::run(["validate"]));';
		$process = proc_open([\PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

		if (!is_resource($process)) {
			throw new \RuntimeException('Cannot start the CLI');
		}

		try {
			$readable = [$pipes[2]];
			$writable = null;
			$exceptional = null;
			$this->assertSame(1, stream_select($readable, $writable, $exceptional, 5));
			fclose($pipes[0]);
			$error = stream_get_contents($pipes[2]);
		} finally {
			foreach ($pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}

			proc_terminate($process);
			$status = proc_close($process);
		}

		$this->assertSame(1, $status);
		$this->assertIsString($error);
		$this->assertStringContainsString('stopped producing data', $error);
	}

	#[Test]
	public function helpDoesNotReadInput(): void
	{
		foreach ([[], ['--help'], ['help']] as $arguments) {
			[$status, $output, $error] = $this->runCommand($arguments);
			$this->assertSame(0, $status, $error);
			$this->assertStringContainsString('Usage: soml', $output);
		}
	}

	#[Test]
	public function brokenOutputPipesFailCleanlyWithoutNativeNotices(): void
	{
		$process = proc_open([\PHP_BINARY, '-d', 'display_errors=stdout', __DIR__ . '/../bin/soml', 'format'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

		if (!is_resource($process)) {
			throw new \RuntimeException('Cannot start the CLI');
		}

		try {
			fclose($pipes[1]);
			fwrite($pipes[0], 'a: 1');
			fclose($pipes[0]);
			$error = stream_get_contents($pipes[2]);
		} finally {
			foreach ($pipes as $pipe) {
				if (is_resource($pipe)) {
					fclose($pipe);
				}
			}

			$status = proc_close($process);
		}

		$this->assertSame(1, $status);
		$this->assertIsString($error);
		$this->assertStringStartsWith('soml: ', $error);
		$this->assertStringNotContainsString('PHP Notice', $error);
		$this->assertStringNotContainsString('PHP Warning', $error);
		$this->assertStringNotContainsString('Stack trace', $error);
	}

	/**
	@param list<string> $arguments
	@param list<string> $phpOptions
	@return array{int, string, string}
	*/
	private function runCommand(array $arguments, string $input = '', array $phpOptions = []): array
	{
		$process = proc_open([\PHP_BINARY, ...$phpOptions, __DIR__ . '/../bin/soml', ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

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
