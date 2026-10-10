<?php

declare(strict_types=1);

namespace Soml;

use function array_slice;
use function array_unshift;
use function count;
use function implode;
use function intdiv;
use function max;
use function min;
use function ord;
use function preg_match_all;
use function preg_replace;
use function preg_split;
use function str_pad;
use function str_repeat;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function substr_count;

/**
Thrown when the input is not a valid document.

The message contains the reason and position on one line. Use `$reason` for the reason alone, and `$codeFrame` to show the error's context.

```
try {
	Soml::decode('name: api-gateway');
} catch (DecodeException $exception) {
	echo $exception->getMessage();
	//=> Unexpected “api-gateway”. A string value must be quoted, as in 'api-gateway' at line 1, column 7

	echo $exception->codeFrame;
	//=> > 1 | name: api-gateway
	//=>     |       ^
}
```
*/
final class DecodeException extends \RuntimeException implements SomlException
{
	private const int CONTEXT_LINES = 2;

	private const int MAX_LINE_WIDTH = 100;

	private const int CODE_POINT_CHUNK_SIZE = 65_536;

	/*
	Characters that a terminal acts on rather than shows: control characters other than tab, format characters, and line or paragraph separators.
	*/
	private const string UNPRINTABLE = '/(?!\t)[' . Decoder::DEFAULT_IGNORABLE_CODE_POINT . '\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u';

	/**
	What is wrong, without the position.
	*/
	public readonly string $reason;

	/**
	The 1-based line of the error.
	*/
	public readonly int $lineNumber;

	/**
	The 1-based column of the error, counted in Unicode code points.
	*/
	public readonly int $columnNumber;

	/**
	The 0-based byte offset of the error in the document, so `substr($document, $exception->offset)` starts at the error.
	*/
	public readonly int $offset;

	/**
	Up to three lines ending at the error, with a caret under the position. A long line is clipped around the position.
	*/
	public readonly string $codeFrame;

	/**
	The file, when the document was read by `Soml::decodeFile()`, and otherwise `null`.
	*/
	public readonly ?string $filePath;

	private function __construct(#[\SensitiveParameter] string $reason, #[\SensitiveParameter] string $source, int $offset, #[\SensitiveParameter] ?string $filePath, ?int $sourceEnd)
	{
		$reason = self::sanitize($reason);
		$lineStart = self::findLineStart($source, $offset);
		$line = substr_count($source, "\n", 0, $offset) + 1;
		$column = self::countCodePoints($source, $lineStart, $offset - $lineStart) + 1;
		$location = $filePath === null ? "line {$line}, column {$column}" : Decoder::describeText($filePath) . ":{$line}:{$column}";

		parent::__construct("{$reason} at {$location}");
		$this->reason = $reason;
		$this->lineNumber = $line;
		$this->columnNumber = $column;
		$this->offset = $offset;
		$this->codeFrame = self::createCodeFrame($source, $offset, $line, $lineStart, $sourceEnd ?? strlen($source));
		$this->filePath = $filePath;
	}

	/**
	`$sourceEnd` limits the code frame to the valid prefix when reporting invalid UTF-8, without copying that prefix.

	@param non-empty-string $reason
	@internal
	*/
	#[\NoDiscard]
	public static function create(#[\SensitiveParameter] string $reason, #[\SensitiveParameter] string $source, int $offset, #[\SensitiveParameter] ?string $filePath = null, ?int $sourceEnd = null): self
	{
		return new self($reason, $source, $offset, $filePath, $sourceEnd);
	}

	private static function findLineStart(#[\SensitiveParameter] string $source, int $offset): int
	{
		if ($offset <= 0) {
			return 0;
		}

		$lineFeed = strrpos($source, "\n", $offset - 1 - strlen($source));
		return $lineFeed === false ? 0 : $lineFeed + 1;
	}

	private static function countCodePoints(#[\SensitiveParameter] string $source, int $start, int $length): int
	{
		// Note: Not using `mb_strlen()` because it would add an optional mbstring extension dependency and require copying the entire span.
		$end = $start + $length;
		$count = $length;

		// Count continuation bytes without a Unicode regex, so chunks may split a character without changing the total.
		for ($offset = $start; $offset < $end; $offset += self::CODE_POINT_CHUNK_SIZE) {
			$count -= preg_match_all('/[\x80-\xBF]/', substr($source, $offset, min(self::CODE_POINT_CHUNK_SIZE, $end - $offset)));
		}

		return $count;
	}

	private static function createCodeFrame(#[\SensitiveParameter] string $source, int $offset, int $line, int $lineStart, int $sourceEnd): string
	{
		// The error line and up to two lines before it, found by scanning back from the error, so that an error late in a long document does not split the whole document.
		$lineStarts = [$lineStart];

		while (count($lineStarts) <= self::CONTEXT_LINES && $lineStarts[0] > 0) {
			array_unshift($lineStarts, self::findLineStart($source, $lineStarts[0] - 1));
		}

		$firstLine = $line - count($lineStarts) + 1;
		$gutterWidth = strlen((string) $line);
		$output = [];

		foreach ($lineStarts as $index => $lineStart) {
			$number = $firstLine + $index;
			$isErrorLine = $number === $line;
			$lineEnd = strpos($source, "\n", $lineStart);
			[$text, $pointer] = self::clip($source, $lineStart, $lineEnd === false ? $sourceEnd : min($lineEnd, $sourceEnd), $isErrorLine ? $offset : $lineStart);
			$text = self::sanitize($text);
			$gutter = ($isErrorLine ? '>' : ' ') . ' ' . str_pad((string) $number, $gutterWidth, ' ', \STR_PAD_LEFT) . ' |';
			$output[] = $text === '' ? $gutter : "{$gutter} {$text}";

			if (!$isErrorLine) {
				continue;
			}

			// Keep the tabs from the line, so the caret lines up whatever the tab width is.
			$padding = preg_replace('/[^\t]/u', ' ', $pointer);
			$output[] = '  ' . str_repeat(' ', $gutterWidth) . " | {$padding}^";
		}

		return implode("\n", $output);
	}

	/**
	Read a bounded window directly from the source, then clip it to code points around the absolute pointer. Returns the text and the text before the pointer.

	@return array{string, string}
	*/
	private static function clip(#[\SensitiveParameter] string $source, int $lineStart, int $lineEnd, int $pointerOffset): array
	{
		// Only the part around the pointer is copied and split into characters, because the line can be the whole document. Four bytes per code point on each side hold the clipped text.
		$windowStart = max($lineStart, $pointerOffset - 4 * self::MAX_LINE_WIDTH);

		while ($windowStart < $pointerOffset && (ord($source[$windowStart]) & 0xC0) === 0x80) {
			$windowStart++;
		}

		$windowEnd = min($lineEnd, $pointerOffset + 4 * self::MAX_LINE_WIDTH);

		while ($windowEnd > $pointerOffset && $windowEnd < $lineEnd && (ord($source[$windowEnd]) & 0xC0) === 0x80) {
			$windowEnd--;
		}

		$window = substr($source, $windowStart, $windowEnd - $windowStart);
		$pointerColumn = self::countCodePoints($window, 0, $pointerOffset - $windowStart);
		$characters = preg_split('//u', $window, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
		$length = count($characters);
		$start = max(0, min($pointerColumn - intdiv(self::MAX_LINE_WIDTH, 2), $length - self::MAX_LINE_WIDTH));
		$end = min($length, $start + self::MAX_LINE_WIDTH);
		$characters = array_slice($characters, $start, $end - $start);
		$prefix = $windowStart > $lineStart || $start > 0 ? '…' : '';
		$suffix = $windowEnd < $lineEnd || $end < $length ? '…' : '';
		$before = implode('', array_slice($characters, 0, $pointerColumn - $start));

		return [$prefix . implode('', $characters) . $suffix, $prefix . $before];
	}

	/*
	The rejected text can hold characters that a terminal acts on rather than shows. Each becomes U+FFFD, which is also one code point, so the caret still lines up. Tabs are kept.
	*/
	private static function sanitize(#[\SensitiveParameter] string $text): string
	{
		return preg_replace(self::UNPRINTABLE, "\u{FFFD}", $text) ?? $text;
	}
}
