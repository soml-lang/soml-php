<?php

declare(strict_types=1);

namespace Soml;

use function file_get_contents;
use function array_key_exists;
use function is_array;
use function is_string;
use function preg_match;
use function property_exists;
use function restore_error_handler;
use function set_error_handler;
use function str_starts_with;

/**
Read and write SOML, a config format for humans.

```
use Soml\Soml;

$config = Soml::decode(<<<'SOML'
	name: 'api-gateway'
	port: 8080
	timeout: 30s
	SOML);

$config->port;
//=> 8080

echo Soml::encode($config);
//=> name: 'api-gateway'
//=> port: 8080
//=> timeout: 30s
```
*/
final class Soml
{
	private function __construct() {}

	/**
	Decode a UTF-8 document into an object or array.

	| SOML | PHP |
	|---|---|
	| object | `stdClass`, or an associative `array` with `$associative` |
	| array | `array` (a list) |
	| string | `string` |
	| int | `int` |
	| float | `float` |
	| bool | `bool` |
	| null | `null` |
	| instant | `Instant` |
	| duration | `Duration` |

	@param string $soml The document, in UTF-8.
	@param bool $associative Decode objects as associative arrays instead of `stdClass` instances. Arrays lose the distinction between `{}` and `[]`, and PHP converts keys such as `'404'` to integers, so `{0: 'a', 1: 'b'}` becomes a list that `encode()` writes as an array.
	@return ($associative is true ? array<mixed> : array<mixed>|\stdClass)
	@throws DecodeException For an invalid document, including invalid UTF-8, with its position.

	```
	Soml::decode("port: 8080\nhosts: ['a', 'b']");
	//=> object(stdClass) {port: 8080, hosts: ['a', 'b']}

	Soml::decode("port: 8080\nhosts: ['a', 'b']", associative: true);
	//=> ['port' => 8080, 'hosts' => ['a', 'b']]
	```
	*/
	public static function decode(#[\SensitiveParameter] string $soml, bool $associative = false): array|\stdClass
	{
		return Decoder::decode($soml, $associative);
	}

	/**
	Check a complete document without retaining decoded values. This still checks duplicate keys, scalar ranges, UTF-8, and the nesting limit, and supports keys beginning with U+0000.

	@throws DecodeException When the document is invalid.
	*/
	public static function validate(#[\SensitiveParameter] string $soml): void
	{
		Decoder::validate($soml);
	}

	/**
	Decode one value fragment, including a scalar or a braced container. No surrounding whitespace or comments are accepted. Objects use stdClass, as with decode().

	@return array<mixed>|\stdClass|string|int|float|bool|null|Instant|Duration
	@throws DecodeException When the fragment is invalid or has trailing text.
	*/
	#[\NoDiscard]
	public static function decodeValue(#[\SensitiveParameter] string $soml): mixed
	{
		return Decoder::decodeScalar($soml, associative: false);
	}

	/**
	Encode one value fragment without a final line feed. Accepts the same nested value types as encode(); scalars are also accepted at the top level. One-line mode writes containers separated by commas and spaces.

	@throws EncodeException When the value cannot be represented.
	*/
	#[\NoDiscard]
	public static function encodeValue(#[\SensitiveParameter] mixed $value, bool $canonical = false, bool $oneLine = false): string
	{
		return Encoder::encodeValue($value, 1, '', $oneLine, $canonical);
	}

	/**
	Produce canonical text directly from a document, discarding comments and spelling while preserving object/array distinctions.

	@throws DecodeException When the source is invalid, including an object key beginning with U+0000.
	@throws EncodeException When the decoded value cannot be encoded.
	*/
	#[\NoDiscard]
	public static function canonicalize(#[\SensitiveParameter] string $soml): string
	{
		return self::encode(self::decode($soml), canonical: true);
	}

	/**
	Read a decoded value at a non-empty path of string keys and non-negative integer indexes. Null is an existing value. Arrays use PHP key lookup; plain stdClass requires string keys. Subclasses are unsupported, so no user-defined accessor or property hook runs.

	@param array<mixed>|\stdClass $value
	@param list<string|int> $path
	@throws \InvalidArgumentException When the path is invalid or traverses a scalar.
	@throws \OutOfBoundsException When a key or index is absent.
	*/
	#[\NoDiscard]
	public static function get(#[\SensitiveParameter] array|\stdClass $value, #[\SensitiveParameter] array $path): mixed
	{
		$path = Editor::validatePath($path);

		foreach ($path as $segment) {
			if ($value instanceof \stdClass) {
				if ($value::class !== \stdClass::class) {
					throw new \InvalidArgumentException('Object lookup supports plain stdClass instances; subclasses are unsupported');
				}

				if (!is_string($segment)) {
					throw new \InvalidArgumentException('An object requires a string key, not an index');
				}

				// PHP cannot access a NUL-leading property directly, even when an array-to-object cast created it.
				if (str_starts_with($segment, "\0")) {
					$value = (array) $value;
				}
			}

			$isArray = is_array($value);

			if (!$isArray && !$value instanceof \stdClass) {
				throw new \InvalidArgumentException('The path leads through a value that is not an object or an array');
			}

			if (!($isArray ? array_key_exists($segment, $value) : property_exists($value, (string) $segment))) {
				throw new \OutOfBoundsException('No value at path segment ' . Decoder::describeText(Decoder::describeKey((string) $segment)));
			}

			$value = $isArray ? $value[$segment] : $value->{$segment};
		}

		return $value;
	}

	/**
	Read and decode a file without converting line endings. Paths use `file_get_contents()` and support stream wrappers; do not pass untrusted paths. An invalid document's exception names the file, as in `Duplicate key port at config.soml:3:1`.

	@param bool $associative Decode objects as associative arrays instead of `stdClass` instances.
	@return ($associative is true ? array<mixed> : array<mixed>|\stdClass)
	@throws DecodeException When the document is not valid.
	@throws \RuntimeException When the file cannot be read.
	*/
	public static function decodeFile(#[\SensitiveParameter] string $path, bool $associative = false): array|\stdClass
	{
		// The warning or notice of the read, such as for a missing file or a directory, which gives an empty string, is the reason. An error handler sees it even when another handler or `@` would hide it.
		set_error_handler(static function (int $type, #[\SensitiveParameter] string $message) use ($path): never {
			throw new \RuntimeException(Decoder::describeText("Cannot read {$path}: {$message}"));
		});

		try {
			$contents = file_get_contents($path);
		} catch (\ValueError $error) {
			// An empty path, or one with a NUL byte.
			throw new \RuntimeException(Decoder::describeText("Cannot read {$path}: {$error->getMessage()}"), previous: $error);
		} finally {
			restore_error_handler();
		}

		if ($contents === false) {
			throw new \RuntimeException(Decoder::describeText("Cannot read {$path}"));
		}

		return Decoder::decode($contents, $associative, $path);
	}

	/**
	Encode an array or object, preserving member order. Nested containers use braces or brackets and tabs, with one member or item per line and one final line feed. Comments and block strings are never written.

	Lists (`array_is_list()`) are arrays; other arrays and plain `stdClass` instances are objects. Subclasses of `stdClass` are rejected before their property hooks run. Use `new stdClass()` for an empty object. Also accepts `DateTimeInterface` as an instant, `DateInterval` without calendar units, negative components, or a relative date string as a duration, and backed enums as their values.

	@param array<mixed>|\stdClass $value
	@param bool $canonical Sort each object's members by key for canonical form. Equal values produce identical text for hashing, signing, or comparison. By default, member order is preserved and all other canonical rules are followed.
	@throws EncodeException For a value that cannot be written, such as NaN, a string that is not valid UTF-8 or has a carriage return, an object of another class, a circular structure, or nesting deeper than 100 levels.

	```
	Soml::encode(['name' => 'api', 'ports' => [80, 443]]);
	//=> "name: 'api'\nports: [\n\t80\n\t443\n]\n"

	Soml::encode(['name' => 'api', 'description' => 'The edge service'], canonical: true);
	//=> "description: 'The edge service'\nname: 'api'\n"
	```
	*/
	#[\NoDiscard]
	public static function encode(#[\SensitiveParameter] array|\stdClass $value, bool $canonical = false): string
	{
		return Encoder::encode($value, $canonical);
	}

	/**
	Format a document's layout, preserving comments, member order, value spellings, and block strings. Containers written on one line stay on one line. The result uses tabs and ends with one line feed.

	@throws DecodeException When the document is not valid.

	```
	Soml::format('ports: [80,   443,] # Public');
	//=> "ports: [80, 443] # Public\n"
	```
	*/
	#[\NoDiscard]
	public static function format(#[\SensitiveParameter] string $soml): string
	{
		return Formatter::format($soml);
	}

	/**
	Change one value while preserving the rest of the document's spelling, comments, and layout. Omit `$value` to remove the member or item; pass `null` explicitly to set a SOML null.

	Missing objects are created; arrays can be extended at the index equal to their length. Removing a missing value returns the original document, so compare strings to detect a removal. New values accept the same PHP types as `encode()` and stay on one line inside a container that stays on one line.

	@param list<string|int> $path A non-empty path of string object keys and non-negative integer array indexes.
	@throws DecodeException When the document is not valid.
	@throws EncodeException When the new value cannot be represented or exceeds the nesting limit at its destination.
	@throws \InvalidArgumentException When the path does not fit the document or contains an invalid key or index.

	```
	Soml::edit("port: 8080 # Public\n", ['port'], 9090);
	//=> "port: 9090 # Public\n"

	Soml::edit("port: 8080\ndebug: true\n", ['debug']);
	//=> "port: 8080\n"
	```
	*/
	#[\NoDiscard]
	public static function edit(#[\SensitiveParameter] string $soml, #[\SensitiveParameter] array $path, #[\SensitiveParameter] mixed $value = null): string
	{
		return Editor::edit($soml, $path, $value, \func_num_args() < 3);
	}

	/**
	Whether a key can be written without quotes: one or more ASCII letters, digits, `_`, or `-`, in any order. `404` and `-x` are valid bare keys. `encode()` quotes every other key.

	@phpstan-assert-if-true =non-empty-string $key

	```
	Soml::isBareKey('content-type');
	//=> true

	Soml::isBareKey('a.b');
	//=> false
	```
	*/
	#[\NoDiscard]
	public static function isBareKey(#[\SensitiveParameter] string $key): bool
	{
		return preg_match('/\A[A-Za-z0-9_-]++\z/', $key) === 1;
	}
}
