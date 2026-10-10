<?php

declare(strict_types=1);

namespace Soml;

/**
The command-line interface. Input is bounded, local files are opened read-only, and transformed documents are written to standard output.

@internal
*/
final class Command
{
	private const int DEFAULT_MAXIMUM_BYTES = 16_777_216;

	/** @var array<string, 0|1|2> */
	private const array ARGUMENTS = [
		'validate' => 0,
		'format' => 0,
		'canonicalize' => 0,
		'get' => 1,
		'set' => 2,
		'remove' => 1,
		'to-json' => 0,
		'from-json' => 0,
		'stats' => 0,
	];

	private const string HELP = <<<'TEXT'
	Usage: soml [--max-bytes N] [--frame] COMMAND [ARGUMENTS] [FILE|-]

	Commands:
	  validate       Check a document; success produces no output
	  format         Preserve comments and spelling while normalizing layout
	  canonicalize   Sort keys and normalize values, without comments
	  get PATH       Print the value at a JSON array path as a SOML fragment
	  set PATH VALUE Replace or insert a SOML value fragment at a JSON array path
	  remove PATH    Remove the member or item at a JSON array path
	  to-json        Convert a document to JSON; time values become strings
	  from-json      Convert a JSON object or array to SOML
	  stats          Print document statistics as JSON
	  help           Show this help

	Input defaults to standard input. Files are never modified.
	Options precede the command. Input is limited to 16 MiB by default.
	PATH and VALUE each have a fixed 16 MiB limit.
	--frame explicitly includes source context in decode errors.
	TEXT;

	private function __construct() {}

	/**
	Run a command and return its process status. Usage errors return 2; data and I/O errors return 1.

	@param list<string> $arguments Arguments without the executable name.
	@return 0|1|2
	*/
	public static function run(#[\SensitiveParameter] array $arguments): int
	{
		$frame = false;
		$maximumBytes = self::DEFAULT_MAXIMUM_BYTES;

		try {
			$index = 0;

			while (isset($arguments[$index]) && str_starts_with($arguments[$index], '--')) {
				$option = $arguments[$index];
				$index++;

				if ($option === '--help') {
					self::write(self::HELP . "\n");
					return 0;
				}

				if ($option === '--frame') {
					$frame = true;
				} elseif ($option === '--max-bytes') {
					$limit = $arguments[$index++] ?? null;
					$parsed = $limit === null ? false : filter_var($limit, \FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => \PHP_INT_MAX - 1]]);

					if ($parsed === false) {
						throw new \InvalidArgumentException('--max-bytes requires a positive integer smaller than PHP_INT_MAX');
					}

					$maximumBytes = $parsed;
				} else {
					throw new \InvalidArgumentException('Unknown option ' . Decoder::describeText($option));
				}
			}

			$command = $arguments[$index++] ?? 'help';
			$argumentCount = max(0, count($arguments) - $index);

			if ($command === 'help') {
				if ($argumentCount !== 0) {
					throw new \InvalidArgumentException('help accepts no arguments');
				}

				self::write(self::HELP . "\n");
				return 0;
			}

			$required = self::ARGUMENTS[$command] ?? null;

			if ($required === null) {
				throw new \InvalidArgumentException('Unknown command ' . Decoder::describeText($command));
			}

			if ($argumentCount < $required || $argumentCount > $required + 1) {
				throw new \InvalidArgumentException("{$command} requires {$required} argument(s) and an optional input file");
			}

			for ($position = 0; $position < $required; $position++) {
				self::checkLength($arguments[$index + $position], self::DEFAULT_MAXIMUM_BYTES);
			}

			$path = $required > 0 ? self::path($arguments[$index]) : [];
			$value = $command === 'set' ? Soml::decodeValue($arguments[$index + 1]) : null;
			$file = $arguments[$index + $required] ?? '-';
			$text = self::read($file, $maximumBytes);
			$output = match ($command) {
				'validate' => self::validate($text),
				'format' => Soml::format($text),
				'canonicalize' => Soml::canonicalize($text),
				'get' => Soml::encodeValue(Soml::get(Soml::decode($text), $path), oneLine: true) . "\n",
				'set' => Soml::edit($text, $path, $value),
				'remove' => Soml::edit($text, $path),
				'to-json' => self::json(Soml::decode($text)),
				'from-json' => self::fromJson($text),
				'stats' => self::statistics($text),
			};

			self::write($output);
			return 0;
		} catch (\InvalidArgumentException $exception) {
			fwrite(\STDERR, 'soml: ' . Decoder::describeText($exception->getMessage()) . "\n");
			return 2;
		} catch (\Throwable $exception) {
			fwrite(\STDERR, 'soml: ' . Decoder::describeText($exception->getMessage()) . "\n");

			if ($frame && $exception instanceof DecodeException) {
				fwrite(\STDERR, $exception->codeFrame . "\n");
			}

			return 1;
		}
	}

	/**
	Decode a JSON array path, then use the editor's validation before touching the input.

	@return non-empty-list<string|int>
	*/
	private static function path(#[\SensitiveParameter] string $text): array
	{
		try {
			$path = json_decode($text, depth: 2, flags: \JSON_THROW_ON_ERROR);
		} catch (\JsonException $exception) {
			throw new \InvalidArgumentException('PATH must be valid JSON', previous: $exception);
		}

		if (!is_array($path)) {
			throw new \InvalidArgumentException('PATH must be a non-empty JSON array of string keys and non-negative integer indexes');
		}

		return Editor::validatePath($path);
	}

	/**
	Bound command-line fragments before parsing or allocating decoded values.

	@param positive-int $maximumBytes
	*/
	private static function checkLength(#[\SensitiveParameter] string $text, int $maximumBytes): void
	{
		if (strlen($text) > $maximumBytes) {
			throw new \LengthException("Input exceeds the {$maximumBytes}-byte limit");
		}
	}

	/**
	Read in bounded chunks and reject as soon as the input limit is exceeded, without loading the remainder. Stream wrappers are excluded from this local-file CLI.

	@param positive-int $maximumBytes
	*/
	private static function read(#[\SensitiveParameter] string $file, int $maximumBytes): string
	{
		if ($file !== '-' && (str_contains($file, '://') || strncasecmp($file, 'data:', 5) === 0 || str_contains($file, "\0"))) {
			throw new \InvalidArgumentException('The input must be a local file path or - for standard input');
		}

		set_error_handler(self::throwStreamError(...));

		try {
			$stream = $file === '-' ? \STDIN : fopen($file, 'rb');

			if ($stream === false) {
				throw new \RuntimeException('Cannot open the input file');
			}

			try {
				$text = '';

				while (true) {
					$remainingBytes = $maximumBytes - strlen($text);

					if ($remainingBytes < 0) {
						throw new \LengthException("Input exceeds the {$maximumBytes}-byte limit");
					}

					if (feof($stream)) {
						return $text;
					}

					$chunk = fread($stream, min(8192, $remainingBytes + 1));

					if ($chunk === false) {
						throw new \RuntimeException('Cannot read the input');
					}

					if ($chunk === '' && !feof($stream)) {
						throw new \RuntimeException('The input stream stopped producing data before its end');
					}

					$text .= $chunk;
				}
			} finally {
				if ($file !== '-') {
					fclose($stream);
				}
			}
		} finally {
			restore_error_handler();
		}
	}

	/**
	The validate command intentionally writes nothing on success.
	*/
	private static function validate(#[\SensitiveParameter] string $text): string
	{
		Soml::validate($text);
		return '';
	}

	/**
	Write JSON without silently discarding invalid floats, UTF-8, or distinctions between integer and float values.
	*/
	private static function json(#[\SensitiveParameter] mixed $value): string
	{
		return json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR, Decoder::MAX_DEPTH + 1) . "\n";
	}

	/**
	Read JSON without converting out-of-range integer tokens into imprecise floats. Oversized integers remain strings; the document must be an array or object.
	*/
	private static function fromJson(#[\SensitiveParameter] string $text): string
	{
		$value = json_decode($text, flags: \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING, depth: Decoder::MAX_DEPTH + 1);

		if (!is_array($value) && !$value instanceof \stdClass) {
			throw new \UnexpectedValueException('A JSON document must be an object or an array');
		}

		return Soml::encode($value);
	}

	/**
	Report structure and scalar types using the same distinctions as the decoder.
	*/
	private static function statistics(#[\SensitiveParameter] string $text): string
	{
		$counts = ['bytes' => strlen($text), 'maximumDepth' => 0, 'objects' => 0, 'arrays' => 0, 'keys' => 0, 'strings' => 0, 'integers' => 0, 'floats' => 0, 'booleans' => 0, 'nulls' => 0, 'instants' => 0, 'durations' => 0];
		self::countValue(Soml::decode($text), 1, $counts);
		return self::json($counts);
	}

	/**
	Accumulate document statistics without converting time values or numeric object keys.

	@param array<string, int> $counts
	*/
	private static function countValue(#[\SensitiveParameter] mixed $value, int $depth, array &$counts): void
	{
		$isObject = $value instanceof \stdClass;

		if ($isObject || is_array($value)) {
			$items = $isObject ? get_object_vars($value) : $value;
			$counts[$isObject ? 'objects' : 'arrays']++;
			$counts['maximumDepth'] = max($counts['maximumDepth'], $depth);
			$counts['keys'] += $isObject ? count($items) : 0;

			foreach ($items as $item) {
				self::countValue($item, $depth + 1, $counts);
			}

			return;
		}

		$type = match (true) {
			is_string($value) => 'strings',
			is_int($value) => 'integers',
			is_float($value) => 'floats',
			is_bool($value) => 'booleans',
			$value === null => 'nulls',
			$value instanceof Instant => 'instants',
			$value instanceof Duration => 'durations',
			default => throw new \LogicException('The decoded value has an unsupported type'),
		};
		$counts[$type]++;
	}

	/**
	Check the output write so a broken pipe or full destination does not report success.
	*/
	private static function write(#[\SensitiveParameter] string $output): void
	{
		if ($output === '') {
			return;
		}

		set_error_handler(self::throwStreamError(...));

		try {
			if (fwrite(\STDOUT, $output) !== strlen($output)) {
				throw new \RuntimeException('Cannot write the complete output');
			}
		} finally {
			restore_error_handler();
		}
	}

	/**
	Convert stream warnings into sanitized exceptions before PHP can print native diagnostics.
	*/
	private static function throwStreamError(int $type, #[\SensitiveParameter] string $message): never
	{
		throw new \RuntimeException(Decoder::describeText($message));
	}
}
