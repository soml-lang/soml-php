<?php

declare(strict_types=1);

namespace Soml;

use function array_unshift;
use function is_int;
use function preg_match;
use function preg_replace;
use function strlen;
use function substr;

/**
Thrown by `Soml::encode()` or `Soml::edit()` for a value that cannot be written.

The message says where the value is:

```
try {
	$text = Soml::encode(['servers' => [['ratio' => NAN]]]);
} catch (EncodeException $exception) {
	echo $exception->getMessage();
	//=> Cannot serialize NaN, which is not representable. Use null for a missing value (at servers[0].ratio)

	$exception->path;
	//=> ['servers', 0, 'ratio']
}
```
*/
final class EncodeException extends \RuntimeException implements SomlException
{
	private const int MAX_PATH_LENGTH = 200;

	/**
	What is wrong, without the location.
	*/
	public readonly string $reason;

	/**
	The keys and indexes that lead to the value, such as `['servers', 0, 'ratio']`, or an empty array for the top-level value.

	@var list<string|int>
	*/
	public private(set) array $path = [];

	private function __construct(#[\SensitiveParameter] string $reason)
	{
		$reason = Decoder::describeText($reason);
		parent::__construct($reason);
		$this->reason = $reason;
	}

	/**
	@internal
	*/
	#[\NoDiscard]
	public static function create(#[\SensitiveParameter] string $reason): self
	{
		return new self($reason);
	}

	/**
	Adds the key or index that leads to the value from the container that the error is leaving.

	@internal
	*/
	public function prependPathSegment(#[\SensitiveParameter] string|int $segment): void
	{
		array_unshift($this->path, $segment);
		$this->message = "{$this->reason} (at {$this->describePath()})";
	}

	/*
	The path as `servers[0].ratio`, with each key as a parse error shows it. The keys may come from untrusted data, so the path is cut short, and the characters that a terminal acts on become escapes.
	*/
	private function describePath(): string
	{
		$description = '';

		foreach ($this->path as $index => $segment) {
			// Once this prefix must be abbreviated, later segments cannot affect the displayed path.
			if (strlen($description) > 4 * self::MAX_PATH_LENGTH) {
				break;
			}

			if (is_int($segment)) {
				$description .= "[{$segment}]";
			} elseif (Soml::isBareKey($segment)) {
				$description .= ($index === 0 ? '' : '.') . Decoder::abbreviate($segment);
			} else {
				// Invalid bytes each become one character; only a prefix can appear in the abbreviated path.
				$key = $segment;

				if (preg_match('//u', $key) !== 1) {
					// The truncation marker keeps a bare prefix quoted, as the complete invalid key would be.
					$key = substr($key, 0, self::MAX_PATH_LENGTH) . (strlen($key) > self::MAX_PATH_LENGTH ? '…' : '');
					$key = preg_replace('/[\x80-\xFF]/', "\u{FFFD}", $key) ?? '';
				}

				$description .= ($index === 0 ? '' : '.') . Decoder::describeKey($key);
			}
		}

		return Decoder::describeText(Decoder::abbreviate($description, self::MAX_PATH_LENGTH));
	}
}
