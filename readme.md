# soml

> Read and write [SOML](https://soml.sh), a config format for humans, in PHP

> [!NOTE]
> The format is a draft.

## Highlights

- Strict specification conformance and precise error positions
- Distinct `int` and `float` types; instants and durations exact to the nanosecond
- Canonical encoding or preserved member order
- A familiar `json_decode()` / `json_encode()` API with `stdClass` or associative arrays
- Bounded nesting, linear decoding except for [PHP hash collisions](#limits), and output independent of precision settings and locale
- No dependencies or extensions beyond PHP itself

## Install

```sh
composer require soml/soml
```

Requires PHP 8.4 or later on a 64-bit platform and Composer 2.2 or later. The CLI is available as `vendor/bin/soml` in a project that installs this package.

## Usage

```php
use Soml\Soml;

$config = Soml::decode(<<<'SOML'
	# The edge service.
	name: 'api-gateway'
	replicas: 3
	timeout: 30.0
	grace: 1m30s
	deployed-at: 2026-09-19T14:00:00Z
	postgres: {host: 'db.internal'}
	SOML);

$config->replicas;
//=> 3

$config->timeout;
//=> 30.0

(string) $config->grace;
//=> '1m30s'

$config->postgres->host;
//=> 'db.internal'

echo Soml::encode($config);
/*
name: 'api-gateway'
replicas: 3
timeout: 30.0
grace: 1m30s
deployed-at: 2026-09-19T14:00:00Z
postgres: {
	host: 'db.internal'
}
*/
```

Read a file:

```php
$config = Soml::decodeFile('config.soml', associative: true);

$config['postgres']['host'];
//=> 'db.internal'
```

Write a file:

```php
file_put_contents('config.soml', Soml::encode($config));
```

## Types

| SOML | PHP | `encode()` also accepts |
|---|---|---|
| string | `string`, in UTF-8 | a backed enum with a string value |
| int | `int` | a backed enum with an int value |
| float | `float` | |
| bool | `bool` | |
| null | `null` | |
| instant | [`Soml\Instant`](#instant) | a `DateTimeInterface` in any time zone; an omitted time zone uses PHP's default |
| duration | [`Soml\Duration`](#duration) | a `DateInterval` without calendar units, negative components, or a relative date string |
| array | a list `array` | |
| object | `stdClass`, or an associative `array` with `associative: true` | any `array` that is not a list |

Integers are signed 64-bit; floats are IEEE 754 binary64. Decoding rejects overflow and nonzero float literals that round to zero. Write `infinity` or `-infinity` explicitly when intended.

Integers and floats keep their types:

```php
Soml::decode("a: 3\nb: 3.0", associative: true);
//=> ['a' => 3, 'b' => 3.0]

Soml::encode(['a' => 3, 'b' => 3.0]);
//=> "a: 3\nb: 3.0\n"
```

> [!IMPORTANT]
> PHP compares `3 == 3.0` as true, so use `===` when the type matters.

With `stdClass` objects:

- `Soml::decode(Soml::encode($value)) == $value` for decoded types. (`-0.0` becomes `0.0`.)
- `Soml::encode(Soml::decode($text), canonical: true)` is the canonical form of `$text`.

### Objects and arrays

Objects decode to `stdClass` by default, as with `json_decode()`. Associative arrays lose two distinctions:

- `{}` and `[]` are both `[]`.
- PHP converts keys such as `'0'` and `'404'` to ints, so `{0: 'a', 1: 'b'}` becomes `['a', 'b']` and encodes as an array.

`encode()` writes lists (`array_is_list()`) as arrays, and other arrays and `stdClass` as objects. Use `new stdClass()` for an empty object. Only plain `stdClass` objects are supported; subclasses are rejected before any property hooks run. Convert other classes, including `ArrayObject` and `JsonSerializable`, to arrays first.

SOML allows up to 100 nesting levels, counting each array or object, including the document.

> [!NOTE]
> Keys starting with U+0000 require `associative: true`, as with `json_decode()`.

A document can be an object or array. Check its type before reading a member:

```php
$config = Soml::decodeFile('config.soml');

if (!$config instanceof stdClass) {
	throw new UnexpectedValueException('The config must be an object');
}

$config->port;
```

## API

### Soml::decode(string $soml, bool $associative = false): array|stdClass

Decode a UTF-8 document into an object or array. Invalid input throws `DecodeException` with its position.

#### associative

Decode objects as associative arrays instead of `stdClass`.

```php
Soml::decode("port: 8080\nhosts: ['a', 'b']");
//=> object(stdClass) {port: 8080, hosts: ['a', 'b']}

Soml::decode("port: 8080\nhosts: ['a', 'b']", associative: true);
//=> ['port' => 8080, 'hosts' => ['a', 'b']]
```

### Soml::decodeFile(string $path, bool $associative = false): array|stdClass

Read a file without converting line endings, using `file_get_contents()` and its stream wrappers, such as `phar://`. Do not pass untrusted paths.

Throws `DecodeException` with the file and position for invalid input, or `RuntimeException` for unreadable files. Messages escape control and format characters in paths, replacing non-ASCII bytes with U+FFFD for invalid UTF-8 paths.

```php
Soml::decodeFile('config.soml');
// DecodeException: Duplicate key port at config.soml:3:1
```

### Soml::validate(string $soml): void

Check a complete document without retaining decoded values. This is useful when you only need to accept or reject a config: it still checks duplicate keys, numeric ranges, Unicode, and nesting, while avoiding allocation of large decoded values. Keys beginning with U+0000 are supported.

Throws `DecodeException` with the same positions as `decode()`.

```php
Soml::validate("port: 8080\ntimeout: 30s");
```

### Soml::decodeValue(string $soml): mixed

Decode a single value fragment, including a scalar, array, or braced object. Unlike a document, a fragment can be `30s`, `null`, or `'hello'`. Surrounding whitespace and comments are rejected; comments inside a container are allowed. Objects use `stdClass`, including the same limitation on keys starting with U+0000.

```php
Soml::decodeValue('30s');
//=> new Duration(30000000000)

Soml::decodeValue('{enabled: true}');
//=> object(stdClass) {enabled: true}
```

Throws `DecodeException` for invalid or trailing input.

### Soml::encodeValue(mixed $value, bool $canonical = false, bool $oneLine = false): string

Encode a single value fragment without a final line feed. Accepts all nested types supported by `encode()`, including scalars at the top level. `canonical` sorts object keys; `oneLine` writes container entries separated by commas and spaces. Strings always escape line feeds, so one-line output contains no literal line feed.

```php
Soml::encodeValue(new Duration(30 * Duration::SECOND));
//=> '30s'

Soml::encodeValue((object) ['b' => [1, 2], 'a' => null], canonical: true, oneLine: true);
//=> '{a: null, b: [1, 2]}'
```

Throws `EncodeException` with a path relative to the fragment. A fragment gets the same 100-level nesting limit as a document.

### Soml::canonicalize(string $soml): string

Decode and encode canonical text in one call, preserving distinctions between objects and arrays, numeric object keys, integer and float types, and time values. Comments and original spellings are discarded. Keys beginning with U+0000 have the same limitation as `decode()`.

```php
Soml::canonicalize("b: 90m\na: 0x10");
//=> "a: 16\nb: 1h30m\n"
```

Throws `DecodeException` or `EncodeException`, as the corresponding operations do.

### Soml::get(array|stdClass $value, array $path): mixed

Read a decoded value at a non-empty list of string keys and non-negative integer indexes. `null` is an existing value. PHP arrays use their native key lookup, including numeric key conversion; plain `stdClass` requires string keys. Subclasses are unsupported, so lookup never invokes user-defined accessors or property hooks.

```php
$config = Soml::decode('services: [{name: \'api\', timeout: 30s, note: null}]');

Soml::get($config, ['services', 0, 'timeout']);
//=> new Duration(30000000000)

Soml::get($config, ['services', 0, 'note']);
//=> null
```

Throws `OutOfBoundsException` for a missing member or index, and `InvalidArgumentException` for an invalid path or traversal through a scalar. Dot characters in keys are literal, as in `['a.b']`.

### Soml::encode(array|stdClass $value, bool $canonical = false): string

Encode an array or object in member order. Nested containers use braces or brackets, tabs, and one entry per line. Output ends with one line feed, without comments or block strings.

All other rules follow canonical form. Use `canonical: true` for hashing, signing, or comparison.

```php
Soml::encode(['name' => 'api', 'ports' => [80, 443], 'description' => 'The edge service']);
/*
name: 'api'
ports: [
	80
	443
]
description: 'The edge service'
*/
```

A top-level value other than an array or `stdClass` throws `TypeError`. Throws `EncodeException` for:

- `NAN`, which SOML does not have (use `null` for a missing value)
- invalid UTF-8 or carriage returns in strings or keys
- unsupported classes (see [Types](#types)), including `stdClass` subclasses, resources, closures, and enums without values
- a `DateTimeInterface` outside years 0001 to 9999 in UTC, or a `DateInterval` outside the duration range, with calendar units or negative components, or made from a relative date string
- circular structures or nesting deeper than 100 levels

The message includes the value's path:

```php
$text = Soml::encode(['servers' => [['ratio' => NAN]]]);
// EncodeException: Cannot serialize NaN, which is not representable. Use null for a missing value (at servers[0].ratio)
```

#### canonical

Sort each object's members by key. Equal values produce identical canonical text.

```php
Soml::encode(['name' => 'api', 'description' => 'The edge service'], canonical: true);
//=> "description: 'The edge service'\nname: 'api'\n"
```

### Soml::format(string $soml): string

Format a document, preserving comments, member order, value spellings, and block strings. Containers on one line stay on one line. Output uses tabs and ends with one line feed.

Throws `DecodeException` for invalid input.

```php
$formatted = Soml::format('ports: [80,   443,] # Public');
//=> "ports: [80, 443] # Public\n"
```

### Soml::edit(string $soml, array $path, mixed $value = null): string

Change one value, preserving surrounding spelling, comments, and layout. Omit `$value` to remove the member or item; pass `null` explicitly to set SOML null.

`$path` is a non-empty list of string object keys and non-negative integer array indexes. Missing objects are created; arrays can be extended at their length. Removing a missing value returns the original document. New values accept the same types as `encode()` and stay on one line inside a container on one line.

Throws `DecodeException` for invalid input, `EncodeException` for unrepresentable values or nesting beyond the destination's limit, and `InvalidArgumentException` for invalid paths, keys, or indexes.

```php
$updated = Soml::edit("port: 8080 # Public\n", ['port'], 9090);
//=> "port: 9090 # Public\n"

$updated = Soml::edit("port: 8080\ndebug: true\n", ['debug']);
//=> "port: 8080\n"

$updated = Soml::edit("port: 8080\n", ['port'], null);
//=> "port: null\n"
```

### Soml::isBareKey(string $key): bool

Whether a key can be unquoted: one or more ASCII letters, digits, `_`, or `-`. `404` and `-x` are valid. `encode()` quotes other keys.

```php
Soml::isBareKey('content-type');
//=> true

Soml::isBareKey('a.b');
//=> false
```

### Instant

A point in time exact to the nanosecond, such as `2026-09-19T14:00:00Z`. `DateTimeImmutable` holds microseconds. Different offsets representing the same instant are equal.

Immutable, with `==`, `<`, and `<=>` comparing by time. `json_encode()` writes its canonical spelling. Supports `serialize()` and `var_export()`, including Laravel's `config:cache`.

```php
use Soml\Instant;

$instant = Soml::decode('deployed: 2026-09-19T16:00:00.5+02:00')->deployed;

echo $instant;
//=> 2026-09-19T14:00:00.5Z

$instant->toDateTimeImmutable()->format('Y-m-d');
//=> '2026-09-19'
```

#### new Instant(int $epochSeconds, int $nanoseconds = 0)

Make an instant from Unix epoch seconds, rounded down, and fractional nanoseconds. Throws `InvalidArgumentException` outside years 0001 to 9999 in UTC or nanoseconds 0 to 999999999.

#### Instant::parse(string $text): Instant

Parse an instant such as `2026-09-19T14:00:00Z`, without surrounding whitespace. Throws `DecodeException` for invalid input.

#### Instant::fromDateTime(DateTimeInterface $dateTime): Instant

Make an instant from `DateTime` or `DateTimeImmutable` in any time zone.

#### $instant->toDateTimeImmutable(): DateTimeImmutable

The instant in UTC, truncated to microseconds.

#### $instant->epochSeconds and $instant->nanoseconds

Unix epoch seconds, rounded down, and fractional nanoseconds from 0 to 999999999. For example, `1969-12-31T23:59:59.5Z` has `-1` seconds and `500000000` nanoseconds.

#### Epoch timestamp conversions

| Factory | Conversion |
|---|---|
| `Instant::fromEpochMilliseconds(int $milliseconds)` | `$instant->toEpochMilliseconds(): int` |
| `Instant::fromEpochMicroseconds(int $microseconds)` | `$instant->toEpochMicroseconds(): int` |
| `Instant::fromEpochNanoseconds(int $nanoseconds)` | `$instant->toEpochNanoseconds(): int` |

Conversions use integer arithmetic. Millisecond and microsecond output rounds down, including before the epoch, and discards finer precision. Those outputs fit throughout the supported years 0001 to 9999. A nanosecond timestamp has the narrower signed 64-bit range, about 292 years either side of the epoch; `toEpochNanoseconds()` throws `OverflowException` outside it. Millisecond and microsecond factories throw `InvalidArgumentException` outside the supported civil years.

```php
$instant = Instant::fromEpochNanoseconds(-1);

(string) $instant;
//=> '1969-12-31T23:59:59.999999999Z'

$instant->toEpochMilliseconds();
//=> -1

$instant->toEpochNanoseconds();
//=> -1
```

#### $instant->plus(Duration $duration) and $instant->minus(Duration $duration): Instant

Shift an instant by a fixed elapsed duration with exact nanosecond precision. These operations leave the original unchanged and do not apply calendar units, local clock changes, or daylight saving adjustments. Both accept the full duration range, including `PHP_INT_MIN` nanoseconds. Throws `InvalidArgumentException` if the result is outside years 0001 to 9999 in UTC.

```php
(string) new Instant(-1, 999_999_999)->plus(new Duration(1));
//=> '1970-01-01T00:00:00Z'
```

#### $instant->difference(Instant $other): Duration

The signed elapsed duration from `$other` to `$instant`, exact to the nanosecond. Throws `OverflowException` when the difference cannot fit in signed 64-bit nanoseconds.

```php
new Instant(1)->difference(new Instant(0, 999_999_999))->nanoseconds;
//=> 1
```

#### (string) $instant

The canonical spelling, in UTC, such as `2026-09-19T14:00:00Z` or `2026-09-19T14:00:00.123456789Z`.

### Duration

A length of time exact to the nanosecond, such as `1h30m`: a signed 64-bit count of nanoseconds, about 292 years either way. Unlike `DateInterval`, it has no variable-length units such as days or months.

Immutable, with `==`, `<`, and `<=>` comparing by length. `json_encode()` writes its canonical spelling. Supports `serialize()` and `var_export()`, including Laravel's `config:cache`.

```php
use Soml\Duration;

$timeout = Soml::decode('timeout: 1m30s')->timeout;

$timeout->nanoseconds;
//=> 90000000000

$timeout->toSeconds();
//=> 90.0

echo new Duration(1500 * Duration::MILLISECOND);
//=> 1.5s
```

#### new Duration(int $nanoseconds)

Make a duration from nanoseconds. Unit constants are `Duration::NANOSECOND`, `MICROSECOND`, `MILLISECOND`, `SECOND`, `MINUTE`, and `HOUR`: `new Duration(5 * Duration::MINUTE)` is five minutes.

#### Duration::parse(string $text): Duration

Parse a duration such as `1h30m`, without surrounding whitespace. Throws `DecodeException` for invalid input.

```php
Duration::parse('1.5h');
//=> new Duration(5_400_000_000_000), which is written 1h30m
```

#### Duration::fromSeconds(int|float $seconds): Duration

Make a duration from seconds, rounded to the nearest nanosecond. Floats can lose precision; use `new Duration()` or `parse()` for exact nanoseconds. Throws `InvalidArgumentException` for non-finite or out-of-range values.

#### Exact unit factories

`Duration::fromMicroseconds(int)`, `fromMilliseconds(int)`, `fromMinutes(int)`, and `fromHours(int)` scale whole units without floating-point arithmetic. Each throws `OverflowException` if the nanosecond result does not fit in signed 64 bits. For fractional seconds, use `fromSeconds()` or an exact string with `parse()`.

```php
Duration::fromMinutes(90)->nanoseconds;
//=> 5400000000000

(string) Duration::fromMilliseconds(-250);
//=> '-0.25s'
```

#### Exact arithmetic

| Method | Result |
|---|---|
| `$duration->plus(Duration $other)` | Add another duration |
| `$duration->minus(Duration $other)` | Subtract another duration |
| `$duration->multipliedBy(int $factor)` | Multiply by a whole-number factor |
| `$duration->negated()` | Reverse the sign |
| `$duration->absolute()` | Non-negative magnitude |

All return immutable `Duration` values and retain exact nanoseconds. An out-of-range result throws `OverflowException` instead of becoming an imprecise float. The magnitude and negation of `PHP_INT_MIN` nanoseconds are out of range.

```php
$timeout = Duration::fromMinutes(1)->plus(Duration::fromMilliseconds(250));

(string) $timeout;
//=> '1m0.25s'

(string) $timeout->multipliedBy(2);
//=> '2m0.5s'
```

#### Duration::fromDateInterval(DateInterval $interval): Duration

Make a duration from a `DateInterval` of hours, minutes, seconds, and microseconds. Throws `InvalidArgumentException` for years, months, days, negative components, relative date strings, or out-of-range values. For elapsed time across daylight saving changes, convert both dates to UTC before calling `diff()`.

#### $duration->nanoseconds

The length in nanoseconds, an `int`.

#### $duration->toSeconds(): float

The length in seconds, for timeout options.

#### $duration->toDateInterval(): DateInterval

The duration as a `DateInterval` for `DateTimeImmutable::add()`, truncated to microseconds.

#### (string) $duration

The canonical spelling, such as `1h30m`, `1.5s`, `-0.25s`, or `0s`. For example, `90m` becomes `1h30m` and `-250ms` becomes `-0.25s`.

### DecodeException

Thrown for invalid input. Extends `RuntimeException` and implements [`SomlException`](#somlexception).

The message contains the reason and position on one line:

```php
try {
	Soml::decode("name: 'api'\nreplicas: three");
} catch (DecodeException $exception) {
	echo $exception->getMessage();
	//=> Unexpected “three”. A string value must be quoted, as in 'three' at line 2, column 11

	echo $exception->codeFrame;
	//=>   1 | name: 'api'
	//=> > 2 | replicas: three
	//=>     |           ^
}
```

| Property | |
|---|---|
| `reason` | What is wrong, without the position |
| `lineNumber` | The 1-based line |
| `columnNumber` | The 1-based column, counted in Unicode code points |
| `offset` | The 0-based byte offset, so `substr($soml, $exception->offset)` starts at the error |
| `codeFrame` | Up to three lines ending at the error, with a caret. Long lines are clipped around the error; characters affecting terminal display are replaced. |
| `filePath` | The original path for `decodeFile()`, otherwise `null` |

Reasons and code frames use bounded, sanitized source excerpts and may differ from the JavaScript reference. Line and column numbers match.

Library stack frames hide documents, values, file paths, and edit paths (`#[\SensitiveParameter]`). Native PHP stream functions can still expose file paths. Reasons and code frames contain source text; avoid logging them for configs with secrets.

### EncodeException

Thrown by `encode()` or `edit()` for unrepresentable values. Extends `RuntimeException` and implements [`SomlException`](#somlexception).

| Property | |
|---|---|
| `reason` | What is wrong, without the location |
| `path` | The keys and indexes that lead to the value, such as `['servers', 0, 'ratio']`, or an empty array for the top-level value |

### SomlException

Catch either `DecodeException` or `EncodeException`:

```php
use Soml\SomlException;

try {
	$config = Soml::decodeFile('config.soml');
	file_put_contents('config.canonical.soml', Soml::encode($config, canonical: true));
} catch (SomlException $exception) {
	echo $exception->getMessage();
}
```

## Recipes

### Validate untrusted input before keeping a decoded value

Limit the byte count before decoding to bound allocation and reduce exposure to PHP hash collisions. `validate()` still reads the whole document and checks all of its syntax; it saves decoded-value allocations when no value is needed.

```php
if (strlen($text) > 262_144) {
	throw new LengthException('Config exceeds 256 KiB');
}

Soml::validate($text);
```

### Pass a scalar fragment between tools

Use the fragment API when a value is intentionally outside a document. Use `decode()` for complete configs, so a bare scalar is rejected where a config object or array is required.

```php
$fragment = Soml::encodeValue(Duration::fromMilliseconds(250));
$value = Soml::decodeValue($fragment);

$value->nanoseconds;
//=> 250000000
```

### Compute a precise elapsed time

Use instant differences to retain nanoseconds. Converting both instants to floating-point seconds first can lose a short difference at large epoch timestamps.

```php
$started = Instant::parse('2026-10-13T12:00:00.999999999Z');
$finished = Instant::parse('2026-10-13T12:00:01Z');

$finished->difference($started)->nanoseconds;
//=> 1
```

### Convert a JavaScript millisecond timestamp

```php
$instant = Instant::fromEpochMilliseconds(1_800_000_000_123);

$instant->toEpochMilliseconds();
//=> 1800000000123
```

### Hash a config

Hash canonical text to compare, cache, or sign a config. Comments, member order, and value spelling do not affect the hash:

```php
hash('sha256', Soml::encode(Soml::decode("b: 1\na: 0x10 # Note."), canonical: true));
//=> the same as for Soml::decode("a: 16\nb: 1")
```

### Compare two configs

Compare canonical strings to detect changes in values, structure, or types. PHP's `==` misses type changes such as `3` to `3.0`:

```php
$before = Soml::encode(Soml::decode("a: 3\nb: 1"), canonical: true);
$after = Soml::encode(Soml::decode("a: 3.0\nb: 1"), canonical: true);

$before === $after;
//=> false
```

### Use a duration as a timeout

```php
$config = Soml::decodeFile('config.soml');

usleep(intdiv($config->retryDelay->nanoseconds, 1000));

$client = new GuzzleHttp\Client(['timeout' => $config->timeout->toSeconds()]);
```

## Command line

After installing the package, use `vendor/bin/soml`. In this repository, use `php bin/soml` after `composer install`. Options precede the command. Input comes from an optional local file or standard input; `-` explicitly selects standard input. Output goes to standard output, and input files are never modified.

```sh
vendor/bin/soml validate config.soml
vendor/bin/soml format config.soml > formatted.soml
vendor/bin/soml canonicalize config.soml > canonical.soml
cat config.soml | vendor/bin/soml validate
```

### Read and edit paths

Paths are JSON arrays, so keys containing dots, spaces, or an empty string have unambiguous spellings. String segments name object keys, and integer segments index arrays. The value for `set` is a SOML fragment, which allows durations and instants without disguising them as strings.

```sh
vendor/bin/soml get '["services",0,"timeout"]' config.soml
vendor/bin/soml set '["timeout"]' '1m30s' config.soml > updated.soml
vendor/bin/soml set '["note"]' 'null' config.soml > updated.soml
vendor/bin/soml remove '["debug"]' config.soml > updated.soml
```

`get` prints a one-line value fragment and a line feed. Edits preserve the source's spelling, comments, and final-newline choice, as `edit()` does. Avoid redirecting output to the input path, because the shell truncates that file before the command reads it.

### Convert JSON

```sh
vendor/bin/soml to-json config.soml > config.json
vendor/bin/soml from-json config.json > config.soml
```

Integer and float types and numeric object keys are preserved. Time values become canonical JSON strings and stay strings on conversion back; JSON has no corresponding native types. Infinity causes an error instead of becoming null. JSON integers beyond PHP's signed 64-bit range become exact strings, rather than imprecise floats. JSON scalars are rejected as documents.

### Inspect structure

`vendor/bin/soml stats config.soml` prints JSON with source bytes, maximum container depth, object and array counts, key count, and a count for each scalar type. The root container is depth 1. Comments do not count as values.

### Limits and diagnostics

The CLI reads documents in 8 KiB chunks and rejects inputs above 16 MiB by default. Command-line PATH and VALUE arguments each have a fixed 16 MiB limit. Use `--max-bytes N` to choose a positive document byte limit, including a smaller limit for untrusted configs. Stream wrappers such as `https://`, `php://`, and `data:` are rejected by the CLI; the PHP `decodeFile()` API retains its documented wrapper support.

```sh
vendor/bin/soml --max-bytes 262144 validate config.soml
vendor/bin/soml --frame validate config.soml
vendor/bin/soml --help
```

Diagnostics go to standard error. Source frames require `--frame`, because they may reveal secrets. Reasons can still contain bounded source excerpts. Exit statuses are 0 for success, 1 for invalid input or I/O failure, and 2 for invalid command-line usage or edit paths.

## Benchmark

### Measure this library

The standalone runner needs only this repository's Composer dependencies. It measures decoding in both object modes, validation, canonicalization, formatting, ordered and canonical encoding, fragment encoding, lookup, no-op edits, replacement, insertion, removal, and exact scalar operations. It reports CPU time per operation and extra peak memory, with JSON output for comparing runs.

```sh
composer benchmark
composer benchmark -- --filter earthquakes/validate --samples 5 --batch-ms 20
composer benchmark -- --filter values/ --json
php -d opcache.enable_cli=1 bench/library.php --json
```

Calibration doubles the batch size until the requested CPU budget is reached and caps the run count. Calibration also warms up each case. Independent samples report minimum, median, and maximum; the median averages the middle two samples when the count is even. Operations that already take a full batch run once and report `samples: 1` explicitly. Peak memory is measured separately, while retaining the operation's result. Match filters use literal text, and an unmatched filter is an error.

Use the same PHP version, opcache/JIT settings, fixtures, and batch settings when comparing results. CPU time reduces interference from other processes; measurements remain approximate. JSON includes PHP and PCRE versions, integer width, opcache/JIT settings, batch size, and the actual runs and samples for every case. An environment with `getrusage()` is required for CPU measurements.

### Compare parsers

CPU time for [shared fixtures](https://github.com/soml-lang/soml/tree/main/benchmark#readme) containing the same data in SOML, JSON, and TOML, measured on an Apple M4 Pro with PHP 8.5.10, opcache enabled, and JIT disabled. Lower is better; measurements are approximate.

| | soml-php | ext/json | internal/toml | devium/toml |
|---|--:|--:|--:|--:|
| Read service (3 KB) | 86.5 µs | 9.95 µs | 988 µs | 2.8 ms |
| Read platform (35 KB) | 574 µs | 81.9 µs | 9.77 ms | 45.5 ms |
| Read earthquakes (733 KB) | 18.1 ms | 2.81 ms | 355 ms | 40.8 s |
| Write service | 31.9 µs | 3.88 µs | 157 µs | 35.6 µs |
| Write platform | 214 µs | 22.8 µs | fails | 238 µs |
| Write earthquakes | 9.75 ms | 2.06 ms | 30.5 ms | 13.6 ms |

`ext/json` uses native code and a smaller grammar. internal/toml cannot write `platform` because PHP converts keys such as `404` to integers, which it refuses. The tracing JIT makes soml-php about 1.3 times as fast on these fixtures.

```sh
cd bench
composer install
php -d opcache.enable_cli=1 benchmark.php
# Or compare only the small fixture:
php -d opcache.enable_cli=1 benchmark.php service
```

## Limits

- **Keys starting with U+0000** need `associative: true` when decoding, because `stdClass` properties cannot start with U+0000. Formatting and editing support these keys directly.
- **Limit untrusted input size.** PHP hashes array keys without a random seed, so crafted collisions take quadratic time to decode: about 3 seconds for 1 MB. Other PHP parsers that build arrays, including `json_decode()`, have the same limit.

## Development

```sh
composer install
composer test
composer audit
```

The [conformance suite](tests/conformance) mirrors the [spec repository](https://github.com/soml-lang/soml/tree/main/conformance). Update it from an adjacent `soml` checkout with `../soml/sync-conformance.sh tests/conformance`.

`composer test` lints PHP source and the CLI, runs PHPUnit, and runs PHPStan at its maximum level. Tests cover decoding, encoding, formatting, editing, value operations, and subprocess CLI behavior, including insertion and removal in every valid conformance fixture.

`composer lint` runs syntax checks with the current PHP executable. `composer audit` checks dependency advisories. CI runs both supported PHP versions, audits dependencies, and repeats PHPUnit with the tracing JIT.

The [differential fuzzers](tests/differential) compare against the JavaScript reference and check formatting's value preservation and idempotence. Use an adjacent `soml-javascript` checkout with dependencies installed and a Node.js version that runs TypeScript:

```sh
composer test:differential
# Optionally pass a seed and a positive integer case count to each fuzzer:
composer test:differential -- 42 2000
```

Runs separately from `composer test`, skips if the reference checkout is missing, and retains generated files in private, randomly named directories in the system temporary directory. The bridge passes arguments directly to the child process and rejects failed processes, incomplete writes, malformed JSON, and mismatched result counts. Reference checkout changes can affect these comparisons; run against a known revision for reproducible results.
