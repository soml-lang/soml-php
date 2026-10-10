<?php

declare(strict_types=1);

namespace Soml;

use function array_key_exists;
use function array_map;
use function bindec;
use function checkdate;
use function chr;
use function count;
use function dechex;
use function explode;
use function hexdec;
use function implode;
use function in_array;
use function intdiv;
use function intval;
use function is_array;
use function is_float;
use function is_infinite;
use function is_int;
use function ltrim;
use function max;
use function min;
use function octdec;
use function ord;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strcmp;
use function strcspn;
use function strlen;
use function strpbrk;
use function strpos;
use function strrpos;
use function strspn;
use function strtolower;
use function strtoupper;
use function substr;
use function substr_compare;
use function substr_count;

/**
A recursive-descent reader over one UTF-8 string. Each method reads one grammar rule at `$index` and moves it past what it read.

It closely follows `parse.ts` in the JavaScript reference implementation. It works on byte offsets and scans byte runs with `strspn()`, `strcspn()`, and `strpos()`, which run in C. Backtracking regular expressions see bounded windows, because PCRE returns `false` at its backtracking limit.

@internal
*/
final class Decoder
{
	/**
	The deepest nesting either direction accepts.
	*/
	public const int MAX_DEPTH = 100;

	public const string BARE_KEY_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-';

	/*
	A number, an instant, or a duration is lexed as one maximal run of these characters, and then validated as a whole.
	*/
	public const string NUMBER_CHARACTERS = self::BARE_KEY_CHARACTERS . '.:+';

	// A shared native scan mask for ASCII diagnostic prefixes and invalid UTF-8 searches.
	private const string ASCII = "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F\x20\x21\x22\x23\x24\x25\x26\x27\x28\x29\x2A\x2B\x2C\x2D\x2E\x2F\x30\x31\x32\x33\x34\x35\x36\x37\x38\x39\x3A\x3B\x3C\x3D\x3E\x3F\x40\x41\x42\x43\x44\x45\x46\x47\x48\x49\x4A\x4B\x4C\x4D\x4E\x4F\x50\x51\x52\x53\x54\x55\x56\x57\x58\x59\x5A\x5B\x5C\x5D\x5E\x5F\x60\x61\x62\x63\x64\x65\x66\x67\x68\x69\x6A\x6B\x6C\x6D\x6E\x6F\x70\x71\x72\x73\x74\x75\x76\x77\x78\x79\x7A\x7B\x7C\x7D\x7E\x7F";

	private const string DIGITS = '0123456789';

	private const string LETTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

	/*
	What may directly follow a scalar value: whitespace, a separator, a closing bracket, or a comment. The end of the document may too.
	*/
	private const array VALUE_END = [
		' ' => true,
		"\t" => true,
		"\n" => true,
		',' => true,
		']' => true,
		'}' => true,
		'#' => true,
		'/' => true,
	];

	/*
	Every C0 control character except tab and line feed, and DEL. They are errors anywhere in a document.
	*/
	private const string CONTROL_CHARACTERS = '/[\x00-\x08\x0B-\x1F\x7F]/u';

	/*
	The longest token that the instant regular expression and the diagnostic regular expressions look at, so that a huge invalid token is described from its first part.
	*/
	private const int MAX_DIAGNOSED_LENGTH = 1000;

	private const array RADICES = [
		'x' => ['name' => 'hexadecimal', 'article' => 'A', 'digits' => '0123456789ABCDEF', 'maximumDigits' => 16, 'base' => 16],
		'o' => ['name' => 'octal', 'article' => 'An', 'digits' => '01234567', 'maximumDigits' => 21, 'base' => 8],
		'b' => ['name' => 'binary', 'article' => 'A', 'digits' => '01', 'maximumDigits' => 63, 'base' => 2],
	];

	/*
	The year, month, day, hour, minute, second, fraction, offset, offset hour, and offset minute.
	*/
	private const string INSTANT = '/^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]+))?(Z|[+\-]([0-9]{2}):([0-9]{2}))\z/';

	private const string LOCAL_DATE_TIME = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]+)?\z/';

	private const string INSTANT_FORMAT = 'An instant is written as 2026-09-19T14:00:00Z, with an optional fraction of up to nine digits and an offset of Z or ±HH:MM';

	/*
	The Unicode properties that are not general categories, as character class contents. PCRE2 only knows them from version 10.40, and PHP can be linked with an older one. They are fixed sets, checked against PCRE2 10.49 over every code point.
	*/
	public const string DEFAULT_IGNORABLE_CODE_POINT = '\x{AD}\x{34F}\x{61C}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{3164}\x{FE00}-\x{FE0F}\x{FEFF}\x{FFA0}\x{FFF0}-\x{FFF8}\x{1BCA0}-\x{1BCA3}\x{1D173}-\x{1D17A}\x{E0000}-\x{E0FFF}';

	private const string WHITE_SPACE = '\x{9}-\x{D}\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}';

	/**
	@internal
	*/
	public const string BIDI_CONTROL = '\x{61C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}';

	private const string KEY_QUOTING_HINT = '. A key that contains characters other than letters, digits, “_”, and “-” must be quoted';

	/*
	The characters that may be meant as part of a key: visible ones that have no meaning in the grammar, which includes every bare key character. A `=` is left out, because after a key, as in `name=foo`, it was most likely meant as the `:` of INI and TOML, so a key that holds a `=` gets no quoting hint.
	*/
	private const string QUOTABLE_KEY_CHARACTER = '[^' . self::DEFAULT_IGNORABLE_CODE_POINT . '\p{C}' . self::WHITE_SPACE . '"#\'*,\/:=\[\]\{\}]';

	private const array SIMPLE_ESCAPES = [
		'\\' => '\\',
		'"' => '"',
		'n' => "\n",
		't' => "\t",
	];

	/*
	What a backslash followed by one of these characters was probably meant to be.
	*/
	private const array ESCAPE_MISTAKES = [
		'r' => 'There is no \r escape, because a carriage return cannot be represented',
		'\'' => 'A \' needs no escape inside "..."',
		"\n" => 'A backslash must be followed by an escape character. Use \\\\ for a literal backslash, or a \'...\' string',
	];

	/*
	Names for the characters that are invisible or easy to mistake, so an error can say what it found.
	*/
	private const array CHARACTER_NAMES = [
		0x061C => 'ARABIC LETTER MARK',
		0x85 => 'NEXT LINE',
		0xA0 => 'NO-BREAK SPACE',
		0xAD => 'SOFT HYPHEN',
		0x1680 => 'OGHAM SPACE MARK',
		0x2000 => 'EN QUAD',
		0x2001 => 'EM QUAD',
		0x2002 => 'EN SPACE',
		0x2003 => 'EM SPACE',
		0x2004 => 'THREE-PER-EM SPACE',
		0x2005 => 'FOUR-PER-EM SPACE',
		0x2006 => 'SIX-PER-EM SPACE',
		0x2007 => 'FIGURE SPACE',
		0x2008 => 'PUNCTUATION SPACE',
		0x2009 => 'THIN SPACE',
		0x200A => 'HAIR SPACE',
		0x200B => 'ZERO WIDTH SPACE',
		0x200C => 'ZERO WIDTH NON-JOINER',
		0x200D => 'ZERO WIDTH JOINER',
		0x200E => 'LEFT-TO-RIGHT MARK',
		0x200F => 'RIGHT-TO-LEFT MARK',
		0x2028 => 'LINE SEPARATOR',
		0x2029 => 'PARAGRAPH SEPARATOR',
		0x202A => 'LEFT-TO-RIGHT EMBEDDING',
		0x202B => 'RIGHT-TO-LEFT EMBEDDING',
		0x202C => 'POP DIRECTIONAL FORMATTING',
		0x202D => 'LEFT-TO-RIGHT OVERRIDE',
		0x202E => 'RIGHT-TO-LEFT OVERRIDE',
		0x202F => 'NARROW NO-BREAK SPACE',
		0x205F => 'MEDIUM MATHEMATICAL SPACE',
		0x2060 => 'WORD JOINER',
		0x2066 => 'LEFT-TO-RIGHT ISOLATE',
		0x2067 => 'RIGHT-TO-LEFT ISOLATE',
		0x2068 => 'FIRST STRONG ISOLATE',
		0x2069 => 'POP DIRECTIONAL ISOLATE',
		0x3000 => 'IDEOGRAPHIC SPACE',
		0xFEFF => 'ZERO WIDTH NO-BREAK SPACE',
	];

	private int $index = 0;
	private SyntaxNode $node;

	private readonly int $length;

	/*
	Where the document's collection starts, after the comments and whitespace before it.
	*/
	private int $documentStart = 0;

	private function __construct(
		#[\SensitiveParameter]
		private readonly string $source,
		private readonly bool $associative,
		#[\SensitiveParameter] private readonly ?string $filePath = null,
		private readonly bool $collectValues = true,
		#[\SensitiveParameter] private readonly ?SyntaxTree $syntax = null,
	) {
		$this->length = strlen($source);
	}

	/**
	@return array<mixed>|\stdClass
	*/
	public static function decode(#[\SensitiveParameter] string $source, bool $associative, #[\SensitiveParameter] ?string $filePath = null): array|\stdClass
	{
		self::checkCharacters($source, $filePath);
		return new self($source, $associative, $filePath)->parseDocument();
	}

	/**
	Validate a document for source operations, retaining only keys needed to reject duplicates. Scalar and nested values are discarded after validation.
	*/
	public static function validate(#[\SensitiveParameter] string $source): void
	{
		self::checkCharacters($source);
		new self($source, true, collectValues: false)->parseDocument();
	}

	/**
	One value that fills `$source`, such as `1h30m`, for `Duration::parse()` and `Instant::parse()`, which check its type.

	@return array<mixed>|\stdClass|string|int|float|bool|null|Instant|Duration
	*/
	public static function decodeScalar(#[\SensitiveParameter] string $source, bool $associative = true): mixed
	{
		self::checkCharacters($source);
		// Scalar callers need no object properties. Public fragments may also contain objects, using the same object mode as a document.
		$decoder = new self($source, $associative);

		if ($source === '') {
			$decoder->fail('Expected a value, but found nothing');
		}

		$value = $decoder->parseValue(1);

		if ($decoder->index < $decoder->length) {
			$decoder->fail("Unexpected {$decoder->describeHere()} after the value");
		}

		return $value;
	}

	/**
	Build source nodes during validation, decoding each key once with the same escape rules and retaining no decoded scalar values.
	*/
	public static function parseTree(#[\SensitiveParameter] SyntaxTree $tree): SyntaxNode
	{
		self::checkCharacters($tree->text);
		$decoder = new self($tree->text, true, collectValues: false, syntax: $tree);
		$decoder->parseDocument();
		return $decoder->node;
	}

	/*
	Characters that are errors wherever they appear, so they are checked once, up front. After this, every substring that a Unicode regular expression sees is valid UTF-8, because each one is cut at a character boundary.
	*/
	private static function checkCharacters(#[\SensitiveParameter] string $source, #[\SensitiveParameter] ?string $filePath = null): void
	{
		// PCRE validates the whole UTF-8 subject before matching, so one call preserves UTF-8 error precedence and locates raw controls.
		$hasControl = preg_match(self::CONTROL_CHARACTERS, $source, $match, \PREG_OFFSET_CAPTURE);

		if ($hasControl === false) {
			self::failInvalidUtf8($source, $filePath);
		}

		if (str_starts_with($source, "\u{FEFF}")) {
			throw DecodeException::create('A byte order mark (BOM) is not allowed', $source, 0, $filePath);
		}

		if ($hasControl !== 1) {
			return;
		}

		$offset = $match[0][1];
		$code = ord($source[$offset]);

		if ($code === 0x0D) {
			throw DecodeException::create('A carriage return (U+000D) is not allowed anywhere. Use LF line endings', $source, $offset, $filePath);
		}

		$escape = sprintf('\u{%x}', $code);
		throw DecodeException::create("A raw control character (" . self::formatCodePoint($code) . ") is not allowed anywhere, including in strings and comments. In a string, write it as the escape {$escape} inside \"...\"", $source, $offset, $filePath);
	}

	private static function failInvalidUtf8(#[\SensitiveParameter] string $source, #[\SensitiveParameter] ?string $filePath): never
	{
		$offset = self::findInvalidUtf8($source);

		// A sequence that the end of the input cuts short.
		if (self::isTruncatedUtf8(substr($source, $offset, 4))) {
			throw DecodeException::create('Incomplete UTF-8 sequence at the end of the input', $source, $offset, $filePath, sourceEnd: $offset);
		}

		throw DecodeException::create(sprintf('Invalid UTF-8 byte 0x%02X', ord($source[$offset])), $source, $offset, $filePath, sourceEnd: $offset);
	}

	/*
	Finds the first byte of the first invalid sequence. Only called on the error path. Each multi-byte sequence is checked by PCRE, rather than by reimplementing the UTF-8 rules for overlong forms, surrogates, and the U+10FFFF limit.
	*/
	private static function findInvalidUtf8(#[\SensitiveParameter] string $source): int
	{
		$length = strlen($source);
		$index = 0;

		// Skip valid chunks before locating the exact bad sequence. End each chunk before a complete character, so a split multibyte sequence is not mistaken for invalid input.
		while ($index + 65_536 < $length) {
			$end = $index + 65_536;

			while ($end > $index && (ord($source[$end]) & 0xC0) === 0x80) {
				$end--;
			}

			if ($end === $index || preg_match('//u', substr($source, $index, $end - $index)) !== 1) {
				break;
			}

			$index = $end;
		}

		while (true) {
			$index += strspn($source, self::ASCII, $index);

			if ($index >= $length) {
				return $index;
			}

			$byte = ord($source[$index]);
			$sequenceLength = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : 2);

			if (preg_match('//u', substr($source, $index, $sequenceLength)) !== 1) {
				return $index;
			}

			$index += $sequenceLength;
		}
	}

	/*
	Whether `$rest`, which starts at the first invalid sequence, is a valid start of a sequence that the end of the input cuts short.
	*/
	private static function isTruncatedUtf8(#[\SensitiveParameter] string $rest): bool
	{
		$lead = ord($rest[0]);

		[$sequenceLength, $low, $high] = match (true) {
			$lead >= 0xC2 && $lead <= 0xDF => [2, 0x80, 0xBF],
			$lead === 0xE0 => [3, 0xA0, 0xBF],
			$lead === 0xED => [3, 0x80, 0x9F],
			$lead >= 0xE1 && $lead <= 0xEF => [3, 0x80, 0xBF],
			$lead === 0xF0 => [4, 0x90, 0xBF],
			$lead >= 0xF1 && $lead <= 0xF3 => [4, 0x80, 0xBF],
			$lead === 0xF4 => [4, 0x80, 0x8F],
			default => [0, 0, 0],
		};

		$length = strlen($rest);

		if ($length >= $sequenceLength) {
			return false;
		}

		for ($index = 1; $index < $length; $index++) {
			$byte = ord($rest[$index]);

			if ($index === 1 ? ($byte < $low || $byte > $high) : ($byte < 0x80 || $byte > 0xBF)) {
				return false;
			}
		}

		return true;
	}

	/** @param non-empty-string $reason */
	private function fail(#[\SensitiveParameter] string $reason, ?int $offset = null): never
	{
		throw DecodeException::create($reason, $this->source, $offset ?? $this->index, $this->filePath);
	}

	/*
	Whether a value ends at `$offset`, so that a suggestion that ends there leaves nothing out. A `/` only starts a comment with a `*` after it, or a `//` comment of another language, which the error for what follows names, so in `km/h` the text goes on.
	*/
	private function isValueEndAt(int $offset): bool
	{
		$byte = $this->byteAt($offset);
		return ($byte === '' || isset(self::VALUE_END[$byte])) && ($byte !== '/' || in_array($this->byteAt($offset + 1), ['*', '/'], true));
	}

	/*
	The byte at `$offset`, or an empty string outside the source, where the JavaScript reference reads `NaN` from `charCodeAt()`. A negative offset is outside too, unlike `$source[-1]`, which reads from the end.
	*/
	private function byteAt(int $offset): string
	{
		return $offset >= 0 ? ($this->source[$offset] ?? '') : '';
	}

	/**
	@return array<mixed>|\stdClass
	*/
	private function parseDocument(): array|\stdClass
	{
		$this->skipTrivia();
		$this->documentStart = $this->index;

		if ($this->index >= $this->length) {
			$this->fail('A document must contain an object or an array, but this one is empty');
		}

		$byte = $this->source[$this->index];

		if ($byte === '{') {
			$value = $this->parseContainer(1, true);
		} elseif ($byte === '[') {
			$value = $this->parseContainer(1, false);
		} else {
			$value = $this->parseBareObject();
		}

		$this->skipTrivia();

		if ($this->index < $this->length) {
			$this->fail("Unexpected {$this->describeHere()} after the end of the document");
		}

		return $value;
	}

	/**
	`bare-object = entry ( entry-sep entry )*`, where a separator is one or more line breaks.

	@return array<mixed>|\stdClass
	*/
	private function parseBareObject(): array|\stdClass
	{
		$members = [];
		$children = [];
		$start = $this->index;

		try {
			$this->parseEntry($members, 1, $children);
		} catch (DecodeException $exception) {
			$this->diagnoseBareValue($start);
			throw $exception;
		}

		$hasLineBreak = $this->skipTrivia();

		while ($this->index < $this->length) {
			if ($this->source[$this->index] === ',') {
				$this->fail('Top-level entries are separated by line breaks, not commas. Use braces for a one-line object');
			}

			if (!$hasLineBreak) {
				$this->fail("Expected a line break before the next entry, but found {$this->describeHere()}{$this->slashCommentHint()}");
			}

			$this->parseEntry($members, 1, $children);
			$hasLineBreak = $this->skipTrivia();
		}

		if ($this->syntax !== null) {
			$end = $this->node->end;
			$this->node = new SyntaxNode('Object', $start, $end, children: $children, braced: false);
			return [];
		}

		// The members are collected in an array and converted once, which is faster than setting each property. `(object)` turns an int key that PHP made of a key such as `404` back into the property `404`.
		return $this->associative ? $members : (object) $members;
	}

	/*
	A whole document that is one scalar, such as `5` or an instant, fails as an entry. That failure is reported as what it is.
	*/
	private function diagnoseBareValue(int $start): void
	{
		$this->index = $start;
		$isBareValue = false;

		try {
			$this->parseValue(1);
			$this->skipTrivia();
			$isBareValue = $this->index >= $this->length;
		} catch (DecodeException) {
			// Not a bare value either, so the original error stands.
		}

		if ($isBareValue) {
			$this->fail('A bare value is not a document. A document is an object or an array, so write it as `key: value` or `[value]`', $start);
		}
	}

	/*
	Skips spaces, tabs, line breaks, and comments, and reports whether a line break was crossed. A line break inside a block comment does not count.
	*/
	private function skipTrivia(): bool
	{
		$source = $this->source;
		$index = $this->index;
		$hasCrossedLineBreak = false;

		while (true) {
			$index += strspn($source, " \t", $index);
			$byte = $source[$index] ?? '';

			if ($byte === "\n") {
				$hasCrossedLineBreak = true;
				$index += strspn($source, " \t\n", $index);
			} elseif ($byte === '#') {
				$start = $index;
				$end = strpos($source, "\n", $index);
				$index = $end === false ? $this->length : $end;
				$this->syntax?->recordComment('Line', $start, $index);
			} elseif ($byte === '/' && ($source[$index + 1] ?? '') === '*') {
				$this->index = $index;
				$this->skipBlockComment();
				$index = $this->index;
			} else {
				$this->index = $index;
				return $hasCrossedLineBreak;
			}
		}
	}

	private function skipBlockComment(): void
	{
		$source = $this->source;
		$start = $this->index;
		$end = strpos($source, '*/', $start + 2);

		if ($end === false) {
			$this->fail('Unterminated block comment', $start);
		}

		$nested = strpos($source, '/*', $start + 2);

		// The body may not contain `/*`. An opening that overlaps the closing `*/`, as in `/*/`, is not inside the body.
		if ($nested !== false && $nested + 2 <= $end) {
			$this->fail('Block comments cannot be nested, and their body may not contain “/*”', $nested);
		}

		$this->index = $end + 2;
		$this->syntax?->recordComment('Block', $start, $this->index);
	}

	/**
	`entry = key ":" ws value`.

	@param array<mixed> $members
	@param list<SyntaxNode> $children
	*/
	private function parseEntry(#[\SensitiveParameter] array &$members, int $depth, #[\SensitiveParameter] array &$children): void
	{
		$source = $this->source;
		$keyStart = $this->index;
		$keyLength = strspn($source, self::BARE_KEY_CHARACTERS, $keyStart);

		// A bare key, the most common key, inline.
		if ($keyLength > 0) {
			$this->index = $keyStart + $keyLength;
		} else {
			$key = $this->parseKey();
		}

		$byte = $source[$this->index] ?? '';

		if ($byte !== ':') {
			if ($byte === '.') {
				$this->failDotInKey($keyStart);
			}

			$this->failMissingColon($keyStart);
		}

		if ($keyLength > 0) {
			$key = substr($source, $keyStart, $keyLength);
		}

		$colon = $this->index; // The colon immediately follows the key.
		$this->index += 1 + strspn($source, " \t", $colon + 1);
		$byte = $source[$this->index] ?? '';

		// A line break or a comment after the colon.
		if ($byte === "\n" || $byte === '#' || $byte === '/') {
			$this->skipTrivia();
		}

		try {
			$value = $this->parseValue($depth + 1);
		} catch (DecodeException $exception) {
			$this->diagnoseBadValue($exception, $key, $keyStart, $colon);
			throw $exception;
		}

		// After the value, as in the reference, so that an error in the value is reported first. Decoded members can be null, so they need `array_key_exists()`; validation retains only true markers and uses `isset()`. PHP makes an int of a key such as `404` both here and when it was added, so the check still compares the decoded strings.
		if ($this->collectValues ? array_key_exists($key, $members) : isset($members[$key])) {
			$this->fail('Duplicate key ' . self::describeKey($key), $keyStart);
		}

		// PHP names a private or protected property with a leading NUL, so an object cannot have such a property.
		if (!$this->associative && ($key[0] ?? '') === "\0") {
			$this->fail('A key that starts with U+0000 cannot be the name of a property. Decode with `associative: true` to read it into an array', $keyStart);
		}

		if ($this->syntax !== null) {
			// Escaped keys share the decoder's escape rules without copying or revalidating the source. Formatting does not retain their decoded identity.
			$this->node = new SyntaxNode('Member', $keyStart, $this->index, value: $this->node, key: $this->syntax->collectKeys ? $key : '', keyEnd: $colon);
			$children[] = $this->node;
		}

		$members[$key] = $this->collectValues ? $value : true;
	}

	/*
	Reports a value that failed to parse as what the entry was meant to be, when that is clear.
	*/
	private function diagnoseBadValue(#[\SensitiveParameter] DecodeException $exception, #[\SensitiveParameter] string $key, int $keyStart, int $colon): void
	{
		// A key that contains a `:`, as in `12:30: 'lunch'`, ends at the first `:`, and the rest is read as the value.
		if (self::isBareKeyCharacter($this->byteAt($colon + 1))) {
			$this->diagnoseKeyWithColon($keyStart);
		}

		$this->index = $colon + 1;
		$isValueOnNextLine = $this->skipTrivia();
		$valueStart = $this->index;
		$commentHint = $this->describeCommentAsValue($colon);

		if ($isValueOnNextLine) {
			$this->diagnoseMissingValue($valueStart, $key, $keyStart, $commentHint);
		}

		// A value that was left out at the end of the document or of an object.
		$byte = $this->byteAt($valueStart);

		if ($commentHint !== '' && $exception->offset === $valueStart && ($byte === '}' || $byte === '')) {
			$this->fail("{$exception->reason}{$commentHint}", $valueStart);
		}
	}

	/*
	A bare key followed directly by `:` and more of a key, up to a `:` that ends the key, as in `a:b: 1`.
	*/
	private function diagnoseKeyWithColon(int $keyStart): void
	{
		// A colon followed by whitespace or EOF must be the last byte of this bounded key run.
		$length = strspn($this->source, self::BARE_KEY_CHARACTERS . ':', $keyStart, self::MAX_DIAGNOSED_LENGTH);
		$end = $keyStart + $length;

		if ($length > 0 && $this->source[$end - 1] === ':' && self::isSpaceOrLineEnd($this->byteAt($end))) {
			$this->fail('A key that contains “:” must be quoted, as in \'' . self::abbreviate(substr($this->source, $keyStart, $length - 1)) . '\'', $keyStart);
		}
	}

	/*
	The hint for a `#` directly after a `:`, as in `color: #FFF`, which starts a comment rather than a value.
	*/
	private function describeCommentAsValue(int $colon): string
	{
		$hash = $this->skipSpaces($colon + 1);

		if ($this->byteAt($hash) !== '#') {
			return '';
		}

		// A `#` followed by a space, or by another `#`, starts an ordinary comment. A separator or a closing bracket after the value is not part of it.
		if (preg_match('/^#[^\t\n #,\]\}][^\t\n ,\]\}]*/', $this->window($hash), $match) !== 1) {
			return '';
		}

		return '. “#” starts a comment, so a value that starts with “#” must be quoted' . self::quotingExample($match[0]);
	}

	/*
	An entry whose value was left out, as in `a:` followed by `'b': 1` on the next line, reads the next key as the value. That failure is reported as what it is.
	*/
	private function diagnoseMissingValue(int $start, #[\SensitiveParameter] string $parent, int $keyStart, #[\SensitiveParameter] string $commentHint): void
	{
		// A YAML block sequence.
		if ($this->byteAt($start) === '-' && self::isSpace($this->byteAt($start + 1))) {
			$this->fail('Expected a value, but found a “-” list. An array is written in brackets, as in [80, 443]', $start);
		}

		$this->index = $start;

		try {
			$key = $this->parseKey();
		} catch (DecodeException) {
			// Not a key either, so the original error stands.
			return;
		}

		if ($this->byteAt($this->index) !== ':') {
			return;
		}

		// Whitespace or the end follows the `:` of a bare key. A digit follows the `:` in an instant such as `2026-09-19T25:00:00Z`, whose own error is more precise. A quoted key cannot be part of a value, so anything may follow its `:`.
		$next = $this->byteAt($this->index + 1);
		$first = $this->byteAt($start);

		if ($first !== '\'' && $first !== '"' && $next !== "\n" && !self::isSpace($next) && $next !== '') {
			return;
		}

		$hint = $commentHint === '' ? $this->describeIndentedKey($parent, $key, $keyStart, $start) : $commentHint;
		$this->fail('Expected a value, but found the key ' . self::describeKey($key) . $hint, $start);
	}

	/*
	The hint for a key at `$start` that is indented under the entry whose value is missing, as YAML nests an object.
	*/
	private function describeIndentedKey(#[\SensitiveParameter] string $parent, #[\SensitiveParameter] string $key, int $keyStart, int $start): string
	{
		$keyIndentation = $this->getIndentation($keyStart);
		$indentation = $this->getIndentation($start);

		if ($keyIndentation === null || $indentation === null || $indentation <= $keyIndentation) {
			return '';
		}

		// The keys are written as in a document, so that the suggestion is valid, rather than as JSON strings like the rest of the message, whose escapes, such as `\b`, are not all valid.
		return '. Indentation does not nest objects, so write ' . Encoder::formatKey(self::abbreviate($parent, 180)) . ': {' . Encoder::formatKey(self::abbreviate($key, 180)) . ': …}';
	}

	private function failMissingColon(int $keyStart): never
	{
		$source = $this->source;
		$next = $this->skipSpaces($this->index);
		$nextByte = $this->byteAt($next);

		if ($nextByte === ':' && $next > $this->index) {
			$this->fail('Whitespace is not allowed between a key and its “:”');
		}

		// A block comment followed by the ":" was meant to come before it.
		if ($nextByte === '/' && $this->byteAt($next + 1) === '*') {
			$commentEnd = strpos($source, '*/', $next + 2);

			if ($commentEnd !== false && $this->byteAt($this->skipSpaces($commentEnd + 2)) === ':') {
				$this->fail('A comment is not allowed between a key and its “:”', $next);
			}
		}

		$colon = strpos($source, ':', $next);

		// A key with a space in it, such as `the name: 1`. The hint is only given for plain words, because quoting a quoted key would change what it means, and for a `:` that whitespace or the end follows, because quoting the words before the `:` in `server localhost:8080` would give a valid document with another meaning. So `the name:1` gets no hint.
		if ($colon !== false && $next > $this->index && self::isBareKeyCharacter($nextByte) && $colon < $this->findLineEnd($next) && self::isSpaceOrLineEnd($this->byteAt($colon + 1))) {
			// Only spaces and tabs are trimmed, because `trim()` would also remove characters that are not whitespace in SOML.
			$keyLength = $this->skipSpacesBack($colon) - $keyStart;

			if (strspn($source, " \t" . self::BARE_KEY_CHARACTERS, $keyStart, $keyLength) === $keyLength) {
				$key = substr($source, $keyStart, min($keyLength, self::MAX_DIAGNOSED_LENGTH));
				$this->fail('A bare key cannot contain spaces. Quote it, as in \'' . self::abbreviate($key) . '\'', $keyStart);
			}
		}

		if ($this->index >= $this->length || $source[$this->index] === "\n") {
			$this->fail('Expected “:” after the key');
		}

		// A character directly after a bare key is most likely meant to be part of it.
		$previous = $this->byteAt($this->index - 1);
		$hint = $previous !== '\'' && $previous !== '"' && $next === $this->index && self::isQuotableKeyStart($this->characterAt($next)) ? self::KEY_QUOTING_HINT : '';
		$this->index = $next;
		$this->fail("Expected “:” after the key, but found {$this->describeHere()}{$hint}");
	}

	private function parseKey(): string
	{
		$source = $this->source;
		$start = $this->index;
		$byte = $source[$start] ?? '';

		if ($byte === '\'' || $byte === '"') {
			if (($source[$start + 1] ?? '') === $byte && ($source[$start + 2] ?? '') === $byte) {
				$this->fail('A block string cannot be a key');
			}

			// A key is a string, so it may hold anything a string can, including a line feed written as an escape.
			// Literal keys need no escape decoding.
			return $byte === '\'' ? $this->parseLiteralString() : $this->parseEscapedString();
		}

		$length = strspn($source, self::BARE_KEY_CHARACTERS, $start);

		if ($length === 0) {
			if ($byte === '[') {
				$this->diagnoseTableHeader();
			}

			$this->fail($this->index >= $this->length ? 'Expected a key' : "Expected a key, but found {$this->describeHere()}{$this->keyQuotingHint()}{$this->slashCommentHint()}");
		}

		$this->index = $start + $length;
		return substr($source, $start, $length);
	}

	/*
	The hint for a key that starts with a character a bare key cannot hold, such as the `$` in `$schema`, or nothing for a character with another meaning, such as `}`.
	*/
	private function keyQuotingHint(): string
	{
		if (!self::isQuotableKeyStart($this->characterAt($this->index))) {
			return '';
		}

		// A key whose run of quotable characters is not directly followed by `:` within the window, such as one that is too long or has a space, gets the hint without the example.
		if (preg_match('/^' . self::QUOTABLE_KEY_CHARACTER . '+(?=:)/u', $this->window($this->index), $match) !== 1) {
			return self::KEY_QUOTING_HINT;
		}

		return self::KEY_QUOTING_HINT . ', as in \'' . self::abbreviate($match[0]) . '\'';
	}

	/*
	A `.` after a key, as in `example.com: 1`. A key is never a path, so the `.` is meant either as part of the key or as nesting.
	*/
	private function failDotInKey(int $keyStart): never
	{
		$end = $this->index;
		$generic = 'A key cannot contain “.” unless it is quoted. Quote the whole key, or use braces to nest, as in a: {b: …}';

		if ($end - $keyStart > self::MAX_DIAGNOSED_LENGTH) {
			$this->fail($generic);
		}

		$end += strspn($this->source, self::BARE_KEY_CHARACTERS . '.', $end, self::MAX_DIAGNOSED_LENGTH - ($end - $keyStart));

		$key = substr($this->source, $keyStart, $end - $keyStart);
		$words = explode('.', $key);

		// The suggestions are only given for a bare key whose dots each have a word on both sides, so that both are valid.
		if ($this->byteAt($end) !== ':' || !self::isBareKeyCharacter($this->byteAt($keyStart)) || in_array('', $words, true)) {
			$this->fail($generic);
		}

		$nested = implode(': {', $words) . ': …' . str_repeat('}', count($words) - 1);
		$this->fail('A bare key cannot contain “.”. Quote it, as in \'' . self::abbreviate($key) . '\', or use braces to nest, as in ' . self::abbreviate($nested));
	}

	/**
	`{` or `[` at depth `$depth`, then items separated by a comma, a line break, or both, with an optional trailing comma, then the closing bracket. A comma must be on the line of the item before it.

	@return array<mixed>|\stdClass
	*/
	private function parseContainer(int $depth, bool $isObject): array|\stdClass
	{
		if ($depth > self::MAX_DEPTH) {
			$this->fail('The document is nested more than ' . self::MAX_DEPTH . ' levels deep');
		}

		$source = $this->source;
		$closing = $isObject ? '}' : ']';
		$items = [];
		$children = [];
		$start = $this->index;
		$this->index++;
		$this->skipTrivia();

		while (($source[$this->index] ?? '') !== $closing) {
			$hasLineBreak = false;
			$itemEnd = $this->index;

			if ($this->index < $this->length) {
				if ($isObject) {
					$this->parseEntry($items, $depth, $children);
				} else {
					$value = $this->parseValue($depth + 1);

					if ($this->syntax !== null) {
						$children[] = $this->node;
					} elseif ($this->collectValues) {
						$items[] = $value;
					}
				}

				$itemEnd = $this->index;
				// The common trivia after an item, inline: spaces, a line break, and the indentation of the next line. A comment needs `skipTrivia()`.
				$index = $itemEnd + strspn($source, " \t", $itemEnd);
				$byte = $source[$index] ?? '';

				if ($byte === "\n") {
					$hasLineBreak = true;
					$index += strspn($source, " \t\n", $index);
					$byte = $source[$index] ?? '';
				}

				$this->index = $index;

				if ($byte === '#' || $byte === '/') {
					$hasLineBreak = $this->skipTrivia() || $hasLineBreak;
				}
			}

			if ($this->index >= $this->length) {
				$this->fail('Unterminated ' . ($isObject ? 'object' : 'array') . ": expected “{$closing}”", $start);
			}

			$byte = $source[$this->index];

			if ($byte === ',') {
				if ($hasLineBreak) {
					$this->fail('A comma must be on the same line as the item before it. The line break already separates the items, so remove the comma');
				}

				$index = $this->index + 1;
				$index += strspn($source, " \t\n", $index);
				$this->index = $index;
				$byte = $source[$index] ?? '';

				if ($byte === '#' || $byte === '/') {
					$this->skipTrivia();
				}
			} elseif (!$hasLineBreak && $byte !== $closing) {
				$item = $isObject ? 'an object member' : 'an array item';
				// Without a line break that separates, a line break in the gap is inside a block comment.
				$hint = strcspn($source, "\n", $itemEnd, $this->index - $itemEnd) < $this->index - $itemEnd ? '. A line break inside a block comment does not separate items' : $this->slashCommentHint();
				$this->fail("Expected “,”, a line break, or “{$closing}” after {$item}, but found {$this->describeHere()}{$hint}");
			}
		}

		$this->index++;

		if ($this->syntax !== null) {
			$this->node = new SyntaxNode($isObject ? 'Object' : 'Array', $start, $this->index, children: $children);
			return [];
		}

		// As for the top-level object, an array converted once.
		return $isObject && !$this->associative ? (object) $items : $items;
	}

	/** @return array<mixed>|\stdClass|string|int|float|bool|null|Instant|Duration */
	private function parseValue(int $depth): array|\stdClass|string|int|float|bool|null|Instant|Duration
	{
		$source = $this->source;
		$start = $this->index;
		$byte = $source[$start] ?? '';

		if ($byte === '{') {
			return $this->parseContainer($depth, true);
		}

		if ($byte === '[') {
			return $this->parseContainer($depth, false);
		}

		// An int or a float written with digits.
		$isNumber = false;

		if ($byte === '\'' && ($source[$start + 1] ?? '') !== '\'') {
			// A literal string, the most common value.
			$value = $this->parseLiteralString($this->collectValues);
		} elseif ($byte === '\'' || $byte === '"') {
			$value = $this->parseString($byte);
		} elseif ($byte === 't' && $this->isKeyword('true')) {
			$value = true;
		} elseif ($byte === 'f' && $this->isKeyword('false')) {
			$value = false;
		} elseif ($byte === 'n' && $this->isKeyword('null')) {
			$value = null;
		} elseif ($byte === 'i' && $this->isKeyword('infinity')) {
			$value = \INF;
		} elseif ($byte === '-' && $this->isKeyword('-infinity')) {
			$value = -\INF;
		} elseif ($byte === '-' || ($byte >= '0' && $byte <= '9')) {
			$value = $this->parsePlainNumber() ?? $this->parseNumberOrInstant();
			$isNumber = is_int($value) || is_float($value);
		} else {
			$this->failUnexpectedValue();
		}

		$next = $source[$this->index] ?? '';

		if ($next !== '' && !isset(self::VALUE_END[$next])) {
			$this->failAfterValue($start, $isNumber);
		}

		if ($isNumber && ($next === ' ' || $next === "\t")) {
			$this->diagnoseUnitAfterSpace($start);
		}

		if ($this->syntax !== null) {
			$isString = $byte === "'" || $byte === '"';
			// Retain validated string spans and block delimiters; keys need no separate value node.
			$isBlock = $isString && ($source[$start + 1] ?? '') === $byte && ($source[$start + 2] ?? '') === $byte;
			$this->node = new SyntaxNode($isString ? 'String' : 'Scalar', $start, $this->index, block: $isBlock);
		}

		return $value;
	}

	/*
	A character that cannot follow the value that starts at `$start`.
	*/
	private function failAfterValue(int $start, bool $isNumber): never
	{
		$source = $this->source;

		// Go writes microseconds as `µs`, with the micro sign or the Greek letter mu. The duration is only suggested when it is valid, which a number with an exponent or a radix prefix, for example, is not.
		if ($isNumber && (substr_compare($source, "\u{B5}s", $this->index, 3) === 0 || substr_compare($source, "\u{3BC}s", $this->index, 3) === 0)) {
			$length = $this->index - $start;
			$number = substr($source, $start, min($length, self::MAX_DIAGNOSED_LENGTH));
			$this->fail('The unit for microseconds is written us' . ($length <= self::MAX_DIAGNOSED_LENGTH && self::isValidValue("{$number}us") ? ', as in ' . self::abbreviate($number) . 'us' : ''));
		}

		// A `''` inside a '...' string, as SQL and YAML escape a quote, ends the string.
		if ($source[$this->index] === '\'' && $source[$start] === '\'') {
			$this->fail('There is no \'\' escape in a \'...\' string. Write a string that contains \' as "...", as in "it\'s"');
		}

		$this->fail("Unexpected {$this->describeHere()} after a value");
	}

	/*
	A number followed by a space and a word, such as `512 MiB` or `10 seconds`, which is an error anyway. A word followed by more than spaces, a separator, or a comment is left to the general errors, because it may be a key, as in `a: 1 b: 2`, or a sentence.
	*/
	private function diagnoseUnitAfterSpace(int $start): void
	{
		$source = $this->source;
		$unitStart = $this->skipSpaces($this->index);
		$unitLength = strspn($source, self::LETTERS, $unitStart);
		$unit = substr($source, $unitStart, min($unitLength, self::MAX_DIAGNOSED_LENGTH));
		// A keyword after a number is a missing comma.
		if ($unit === '' || !$this->isValueEndAt($this->skipSpaces($unitStart + $unitLength)) || in_array($unit, ['true', 'false', 'null', 'infinity'], true)) {
			return;
		}

		$numberLength = $this->index - $start;
		$number = substr($source, $start, min($numberLength, self::MAX_DIAGNOSED_LENGTH));
		// The duration is only suggested when it is valid, which a number with an exponent or a radix prefix, a fraction of a nanosecond, a negative zero, or a value outside the 64-bit range is not. The string is always offered, because a unit such as `m` may mean meters rather than minutes.
		$duration = $numberLength <= self::MAX_DIAGNOSED_LENGTH && isset(Duration::UNITS[$unit]) && self::isValidValue("{$number}{$unit}") ? self::abbreviate($number) . $unit : '10s';
		$this->fail("A unit cannot follow a number after a space. Write a duration without the space, as in {$duration}, and anything else, such as a size, as a string, as in '" . self::abbreviate("{$number} {$unit}") . '\'', $unitStart);
	}

	/**
	@param 'true'|'false'|'null'|'infinity'|'-infinity' $word
	*/
	private function isKeyword(#[\SensitiveParameter] string $word): bool
	{
		$length = strlen($word);

		if (substr_compare($this->source, $word, $this->index, $length) !== 0) {
			return false;
		}

		// A longer word, such as `nullable`, is not the keyword.
		if (self::isBareKeyCharacter($this->byteAt($this->index + $length))) {
			return false;
		}

		$this->index += $length;
		return true;
	}

	private function failUnexpectedValue(): never
	{
		if ($this->index >= $this->length) {
			$this->fail('Expected a value, but reached the end of the document');
		}

		$byte = $this->source[$this->index];

		if ($byte === '+') {
			$this->fail('A “+” sign is not allowed. A number without a sign is positive');
		}

		// A `.` that no digit follows begins a string, such as `.env` or `./foo`, rather than a number.
		if ($byte === '.') {
			$this->fail(self::isDigit($this->byteAt($this->index + 1)) ? 'A number cannot begin with “.”; write a digit before it, as in 0.5' : self::describeUnknownWord('.', $this->unquotedText()));
		}

		$wordLength = strspn($this->source, self::BARE_KEY_CHARACTERS, $this->index);

		if ($wordLength > 0) {
			$wordEnd = $this->index + $wordLength;
			$word = substr($this->source, $this->index, min($wordLength, self::MAX_DIAGNOSED_LENGTH));

			// A key where a value should be, as when the value of an entry is left out and the next line has a key, or as in `[a: 1]`, is reported as a key. A word after the `:` of a member and a space, as in `msg: Error: file not found` or `url: https://example.com`, is that member's value instead, an unquoted string. Without the space, as in `a:b: 1`, the first `:` was most likely meant as part of the key.
			$spacesStart = $this->skipSpacesBack($this->index);
			$isMemberValue = $spacesStart < $this->index && $this->byteAt($spacesStart - 1) === ':';

			if (!$isMemberValue && $this->byteAt($wordEnd) === ':') {
				$this->fail('Expected a value, but found the key ' . self::abbreviate($word));
			}

			$this->diagnoseTableHeader(true);
			$this->fail(self::describeUnknownWord($word, $this->unquotedText()));
		}

		$this->fail("Expected a value, but found {$this->describeHere()}" . ($this->isBlockScalarIndicator() ? '. Write a multiline string as a block string, between \'\'\' lines' : $this->slashCommentHint()));
	}

	/*
	The text from `$start` that was most likely meant as one unquoted string, such as `John Smith`: up to the end of the line, a comma, a closing bracket, or a comment after a space or a tab, without the spaces and tabs at its end.
	*/
	private function unquotedText(?int $start = null): string
	{
		// Note: Not using `strcspn()` to skip ordinary runs because the extra delimiter handling benchmarked slower on hash-heavy input.
		$start ??= $this->index;
		$limit = $this->diagnosedEnd($start);
		$end = $start;

		while ($end < $limit && !$this->isUnquotedTextEnd($end)) {
			$end++;
		}

		$end = $this->skipSpacesBack($end);
		return $end > $start ? substr($this->source, $start, $end - $start) : '';
	}

	private function isUnquotedTextEnd(int $index): bool
	{
		$byte = $this->source[$index];
		$isComment = $byte === '#' || ($byte === '/' && $this->byteAt($index + 1) === '*');
		return $byte === "\n" || $byte === ',' || $byte === ']' || $byte === '}' || ($isComment && self::isSpace($this->byteAt($index - 1)));
	}

	/*
	A TOML table header, as in `[server]` or `[[servers]]` on a line of its own, reads as an array that holds a word, or as a key that starts with "[". Where a value is expected, `$isValue`, it is only a table header when its bracket opens the document, because a table cannot be written as an item of an array inside it.
	*/
	private function diagnoseTableHeader(bool $isValue = false): void
	{
		$lineStart = $this->lineStart($this->index);

		if ($isValue && $this->skipSpaces($lineStart) !== $this->documentStart) {
			return;
		}

		$line = substr($this->source, $lineStart, min($this->findLineEnd($this->index), $lineStart + self::MAX_DIAGNOSED_LENGTH) - $lineStart);

		// The name is a valid key, so that the suggestion is valid.
		if (preg_match('/^[\t ]*\[\[?(?<name>[A-Z_a-z][A-Za-z0-9_\-]*)\]\]?[\t ]*\z/', $line, $match) !== 1) {
			return;
		}

		$this->fail('There are no table headers. Write the table as an object, as in ' . self::abbreviate($match['name']) . ': {…}');
	}

	/*
	A YAML literal block scalar indicator, as in `key: |` or `key: |-`, at the end of its line.
	*/
	private function isBlockScalarIndicator(): bool
	{
		// A folded block scalar, `>`, joins its lines, which a block string does not, so only `|` gets the hint.
		if ($this->byteAt($this->index) !== '|') {
			return false;
		}

		$chomping = $this->byteAt($this->index + 1);
		$end = $this->skipSpaces($chomping === '-' || $chomping === '+' ? $this->index + 2 : $this->index + 1);
		$next = $this->byteAt($end);
		return $next === "\n" || $next === '';
	}

	/*
	The hint for a `//` comment, as in JavaScript.
	*/
	private function slashCommentHint(): string
	{
		return $this->byteAt($this->index) === '/' && $this->byteAt($this->index + 1) === '/' ? '. A comment starts with “#”' : '';
	}

	/**
	@return non-empty-string
	*/
	private function describeHere(): string
	{
		if ($this->index >= $this->length) {
			return 'the end of the document';
		}

		if ($this->source[$this->index] === "\n") {
			return 'a line break';
		}

		return self::describeCharacter(self::codePointOf($this->characterAt($this->index)));
	}

	/**
	@param "'"|'"' $quote
	*/
	private function parseString(string $quote): string
	{
		$source = $this->source;

		if (($source[$this->index + 1] ?? '') === $quote && ($source[$this->index + 2] ?? '') === $quote) {
			return $this->parseBlockString($quote);
		}

		return $quote === '\'' ? $this->parseLiteralString($this->collectValues) : $this->parseEscapedString($this->collectValues);
	}

	/*
	`'...'` has no escapes, so its value is the source text between the quotes.
	*/
	private function parseLiteralString(bool $collectValue = true): string
	{
		// Note: Not using separate `strpos()` searches because each can scan past the other delimiter, making repeated strings on one line quadratic.
		$source = $this->source;
		$start = $this->index;
		$end = $start + 1 + strcspn($source, "'\n", $start + 1);

		if ($end >= $this->length || $source[$end] === "\n") {
			$this->fail('Unterminated string. A \'...\' string must end on the line it starts on; use a block string (\'\'\') for multiple lines', $start);
		}

		$this->index = $end + 1;
		return $collectValue ? substr($source, $start + 1, $end - $start - 1) : '';
	}

	private function parseEscapedString(bool $collectValue = true): string
	{
		// Note: Not using `strpbrk()` because it has no offset parameter and needs copied substrings to scan from an offset and return the matched suffix.
		$source = $this->source;
		$start = $this->index;
		$chunkStart = $start + 1;
		$value = '';

		while (true) {
			$special = $chunkStart + strcspn($source, "\"\\\n", $chunkStart);

			if ($special >= $this->length || $source[$special] === "\n") {
				$this->fail('Unterminated string. A "..." string must end on the line it starts on; use a block string (""") for multiple lines', $start);
			}

			if ($collectValue) {
				$value .= substr($source, $chunkStart, $special - $chunkStart);
			}

			if ($source[$special] === '"') {
				$this->index = $special + 1;
				return $value;
			}

			$chunkStart = $special;
			$text = $this->parseEscape($chunkStart, $collectValue);
			if ($collectValue) {
				$value .= $text;
			}
		}
	}

	/**
	Decodes the escape at `$offset`, which is a backslash, and advances the offset past it. Updating the offset directly avoids allocating an array for every escape.
	*/
	private function parseEscape(int &$offset, bool $collectValue): string
	{
		$source = $this->source;
		$character = $source[$offset + 1] ?? '';

		if (isset(self::SIMPLE_ESCAPES[$character])) {
			$offset += 2;
			return self::SIMPLE_ESCAPES[$character];
		}

		if ($character === 'u') {
			// Uppercase digits too, so that the value of such an escape is checked before its spelling.
			if (preg_match('/\Gu\{([0-9a-fA-F]{1,6})\}/', $source, $match, 0, $offset + 1) !== 1) {
				$this->fail(self::describeBadUnicodeEscape(substr($source, $offset + 1, 12)), $offset);
			}

			$hex = $match[1];
			$codePoint = (int) hexdec($hex);

			// The value is checked before the spelling, so that the uppercase and leading zeros errors never point to an escape that is not allowed either.
			if ($codePoint === 0x0D) {
				$this->fail("A carriage return (U+000D) cannot be represented, so \\u{{$hex}} is not allowed", $offset);
			}

			if ($codePoint >= 0xD800 && $codePoint <= 0xDFFF) {
				$this->fail("\\u{{$hex}} is a surrogate, which is not a Unicode scalar value", $offset);
			}

			if ($codePoint > 0x10FFFF) {
				$this->fail("\\u{{$hex}} is above U+10FFFF, the largest Unicode scalar value", $offset);
			}

			if (strpbrk($hex, 'ABCDEF') !== false) {
				$this->fail('A Unicode escape uses lowercase hexadecimal digits', $offset);
			}

			if (strlen($hex) > 1 && $hex[0] === '0') {
				$this->fail('A Unicode escape may not have leading zeros; write \u{' . (ltrim($hex, '0') ?: '0') . '}', $offset);
			}

			$offset += 1 + strlen($match[0]);
			return $collectValue ? self::encodeCodePoint($codePoint) : '';
		}

		// A backslash at the end of the document is the same mistake as one at the end of a line.
		$reason = self::ESCAPE_MISTAKES[$character === '' ? "\n" : $character] ?? 'Unknown escape “\\' . $this->characterAt($offset + 1) . '”. The escapes are \\\\, \", \n, \t, and \u{…}; use a \'...\' string for literal backslashes';
		$this->fail($reason, $offset);
	}

	/**
	A block string opens with a run of three or more quotes and a line break, and closes at the first line whose first non-whitespace content is a run of exactly that many quotes. The closing line's indentation is removed from every content line, except a blank one, which holds only spaces and tabs and becomes an empty line.

	@param "'"|'"' $quote
	*/
	private function parseBlockString(string $quote): string
	{
		$source = $this->source;
		$start = $this->index;
		$delimiterLength = strspn($source, $quote, $start);
		$this->index = $start + $delimiterLength;

		if ($this->index >= $this->length) {
			$this->fail('Unterminated block string', $start);
		}

		// As in Swift, the opening delimiter is followed directly by a line break, not even by trailing whitespace.
		if ($source[$this->index] !== "\n") {
			$this->fail('A block string\'s opening delimiter must be followed directly by a line break, and its content starts on the next line');
		}

		$contentStart = $this->index + 1;
		$closing = $this->findBlockStringEnd($contentStart, $quote, $delimiterLength);

		if ($closing === null) {
			$this->fail('Unterminated block string' . $this->describeInlineClosingDelimiter($contentStart, $quote, $delimiterLength), $start);
		}

		[$closingLineStart, $delimiterStart] = $closing;
		$indentation = substr($source, $closingLineStart, $delimiterStart - $closingLineStart);
		$indentationLength = strlen($indentation);
		$this->index = $delimiterStart + $delimiterLength;
		if (!$this->collectValues || $quote === '"') {
			$this->validateBlockIndentation($contentStart, $closingLineStart, $indentation);
		}

		if (!$this->collectValues) {
			if ($quote === '"') {
				$this->unescapeSpan($contentStart, $closingLineStart);
			}

			return '';
		}

		$value = '';
		$hasContent = false;
		$pendingLineBreaks = 0;

		for ($lineStart = $contentStart; $lineStart < $closingLineStart; $lineStart = $lineEnd + 1) {
			$lineEnd = $this->findLineEnd($lineStart);
			$lineLength = $lineEnd - $lineStart;
			$blankLength = strspn($source, " \t", $lineStart, $lineLength);

			// A blank line, which is empty or holds only spaces and tabs, may leave out the indentation, and it becomes an empty line. Swift is stricter here: there only a completely empty line may leave it out.
			if ($blankLength === $lineLength) {
				if ($hasContent) {
					$pendingLineBreaks++;
				}

				continue;
			}

			if ($quote === "'" && $indentationLength > 0 && substr_compare($source, $indentation, $lineStart, $indentationLength) !== 0) {
				$this->failBlockIndentation($lineStart);
			}

			$textStart = $lineStart + $indentationLength;
			$text = $quote === '"' ? $this->unescapeSpan($textStart, $lineEnd) : substr($source, $textStart, $lineEnd - $textStart);

			// Blank lines directly after the opening delimiter and directly before the closing one are not content. Every blank line is empty by now.
			if ($hasContent) {
				$value .= $pendingLineBreaks === 0 ? "\n" : str_repeat("\n", $pendingLineBreaks + 1);
			}

			$value .= $text;

			$hasContent = true;
			$pendingLineBreaks = 0;
		}

		return $value;
	}

	/**
	Check every content line before decoding escapes, preserving the precedence of indentation errors while retaining no line arrays.
	*/
	private function validateBlockIndentation(int $start, int $end, #[\SensitiveParameter] string $indentation): void
	{
		$length = strlen($indentation);

		if ($length === 0) {
			return;
		}

		for ($lineStart = $start; $lineStart < $end; $lineStart = $lineEnd + 1) {
			$lineEnd = $this->findLineEnd($lineStart);
			$lineLength = $lineEnd - $lineStart;

			if (strspn($this->source, " \t", $lineStart, $lineLength) !== $lineLength && substr_compare($this->source, $indentation, $lineStart, $length) !== 0) {
				$this->failBlockIndentation($lineStart);
			}
		}
	}

	/**
	Report a nonblank content line whose indentation differs from its closing delimiter.
	*/
	private function failBlockIndentation(int $lineStart): never
	{
		$this->fail('This line does not start with the indentation of its block string\'s closing delimiter. Every line except a blank one must start with exactly the same spaces and tabs', $lineStart);
	}

	/**
	Find the closing delimiter of a block string: the first line from `$offset` on that holds, after zero or more spaces and tabs, a run of exactly `$length` of the `$quote` character. A longer run is content. Returns where that line and its delimiter start, or `null` when no line closes the block.

	@return array{int, int}|null

	@param "'"|'"' $quote
	*/
	private function findBlockStringEnd(int $offset, string $quote, int $length): ?array
	{
		$source = $this->source;

		for ($lineStart = $offset; $lineStart < $this->length; $lineStart = $this->findLineEnd($lineStart) + 1) {
			$delimiterStart = $lineStart + strspn($source, " \t", $lineStart);

			if (strspn($source, $quote, $delimiterStart) === $length) {
				return [$lineStart, $delimiterStart];
			}
		}

		// Past the last line, either because the source ended with a line break or because the last line was content.
		return null;
	}

	/*
	The hint for a content line that ends with the delimiter, as TOML allows. Adding a closing line after it would keep the delimiter as content.
	*/
	private function describeInlineClosingDelimiter(int $contentStart, string $quote, int $delimiterLength): string
	{
		$line = substr_count($this->source, "\n", 0, $contentStart) + 1;

		for ($lineStart = $contentStart; $lineStart < $this->length; $lineStart = $this->findLineEnd($lineStart) + 1) {
			$textStart = $this->skipSpaces($lineStart);
			$textEnd = max($textStart, $this->skipSpacesBack($this->findLineEnd($lineStart)));
			$textLength = $textEnd - $textStart;

			// A line of only quotes, longer than the delimiter, is content.
			if ($textLength >= $delimiterLength && strspn($this->source, $quote, $textEnd - $delimiterLength, $delimiterLength) === $delimiterLength && strspn($this->source, $quote, $textStart, $textLength) !== $textLength) {
				$delimiter = str_repeat($quote, min($delimiterLength, 40)) . ($delimiterLength > 40 ? '…' : '');
				return ". Its closing delimiter must start a line, so move the {$delimiter} at the end of line {$line} to a new line";
			}

			$line++;
		}

		return '';
	}

	/**
	Decode escapes in a validated block-content span, or only validate them when values are discarded. Offsets remain relative to the original source.
	*/
	private function unescapeSpan(int $start, int $end): string
	{
		// Note: Not using `strpos()` because it has no end offset and can repeatedly scan beyond the block-content span, making decoding quadratic.
		$value = '';

		while ($start < $end) {
			$special = $start + strcspn($this->source, '\\', $start, $end - $start);

			if ($this->collectValues) {
				$value .= substr($this->source, $start, $special - $start);
			}

			if ($special === $end) {
				break;
			}

			$start = $special;
			$escaped = $this->parseEscape($start, $this->collectValues);

			if ($this->collectValues) {
				$value .= $escaped;
			}
		}

		return $value;
	}

	private function parseNumberOrInstant(): int|float|Instant|Duration|null
	{
		$start = $this->index;
		$end = $start + 1 + strspn($this->source, self::NUMBER_CHARACTERS, $start + 1);
		$this->index = $end;

		if (($this->source[$start + 4] ?? '') === '-' && preg_match('/\G[0-9]{4}-[0-9]{2}-[0-9]{2}/', $this->source, offset: $start) === 1) {
			// Only a bounded spelling is needed for instant validation and diagnostics, even when the token is enormous.
			return $this->parseInstant(substr($this->source, $start, min($end - $start, self::MAX_DIAGNOSED_LENGTH + 1)), $start);
		}

		$text = substr($this->source, $start, $end - $start);
		$kind = self::classifyNumber($text);

		if ($kind === null && self::isDurationLike($text)) {
			return $this->parseDuration($text, $start);
		}

		if ($kind === 'int') {
			return $this->parseInteger($text, $start);
		}

		if ($kind === 'float') {
			$value = (float) str_replace('_', '', $text);

			if (is_infinite($value)) {
				$this->fail(self::abbreviate($text) . ' is too large to be a finite float. Use ' . ($value < 0 ? '-infinity' : 'infinity') . ' if you mean it', $start);
			}

			if ($value === 0.0) {
				// A nonzero digit before the exponent means the literal is not zero, so it underflowed.
				$mantissaLength = strcspn($text, 'e');

				if (strcspn($text, '123456789', 0, $mantissaLength) < $mantissaLength) {
					$this->fail(self::abbreviate($text) . ' is too small to be told apart from zero. Write 0.0 if you mean zero', $start);
				}

				// Negative zero is the same value as zero.
				return 0.0;
			}

			return $value;
		}

		$this->fail($this->describeThousandsSeparator($text, $start) ?? self::describeBadNumber($text, $this->unquotedText($start)), $start);
	}

	/*
	The common case, a short decimal int or float such as `8080`, `-3`, or `30.5`, without the general path's maximal-run scan and classification. An oversized plain decimal int is rejected without copying its full token. Other forms and errors return `null` and are left to the general path.
	*/
	private function parsePlainNumber(): int|float|null
	{
		$source = $this->source;
		$start = $this->index;
		$index = $source[$start] === '-' ? $start + 1 : $start;
		$integerStart = $index;
		$integerLength = strspn($source, self::DIGITS, $index);

		// No digits, or a leading zero followed by more digits, which the general path reports as an error or reads as an instant before the year 1000.
		if ($integerLength === 0 || ($integerLength > 1 && $source[$integerStart] === '0')) {
			return null;
		}

		$index += $integerLength;
		$isFloat = false;

		if (($source[$index] ?? '') === '.') {
			$fractionLength = strspn($source, self::DIGITS, $index + 1);

			if ($fractionLength === 0) {
				return null;
			}

			$index += 1 + $fractionLength;
			$isFloat = true;
		}

		$next = $source[$index] ?? '';

		// A character that may continue a number, such as `e`, `_`, or `-`, needs the general path. Nineteen-digit ints are checked against the exact signed limits below.
		if ($next !== '' && !isset(self::VALUE_END[$next])) {
			return null;
		}

		if (!$isFloat && $integerLength > 19) {
			$this->failIntegerRange(substr($source, $start, min($index - $start, 41)), $start);
		}

		if ($isFloat && $index - $integerStart > 18) {
			return null;
		}

		$text = substr($source, $start, $index - $start);

		if ($isFloat) {
			$this->index = $index;
			$value = (float) $text;

			// Negative zero is the same value as zero.
			return $value === 0.0 ? 0.0 : $value;
		}

		// `-0` is an error, which the general path reports.
		if ($text === '-0') {
			return null;
		}

		$value = $index - $integerStart === 19 ? self::parseDecimalInteger($text) : (int) $text;

		if ($value === null) {
			return null;
		}

		$this->index = $index;
		return $value;
	}

	/*
	Convert a validated integer. Skip arbitrary radix leading zeros before removing separators, so even a huge spelling needs only a bounded significant-digit copy.
	*/
	private function parseInteger(#[\SensitiveParameter] string $text, int $start): int
	{
		$radix = $text[0] === '0' ? self::RADICES[$text[1] ?? ''] ?? null : null;

		if ($radix !== null) {
			$significantStart = 2 + strspn($text, '0_', 2);
			$significantLength = strlen($text) - $significantStart;

			if ($significantLength === 0) {
				return 0;
			}

			// A validated number has at most one separator between digits. Anything longer must overflow, without allocating its significant suffix.
			if ($significantLength > 2 * $radix['maximumDigits'] - 1) {
				$this->failIntegerRange($text, $start);
			}

			$digits = str_replace('_', '', substr($text, $significantStart));
			$value = match ($radix['base']) {
				16 => hexdec($digits),
				8 => octdec($digits),
				2 => bindec($digits),
			};
		} else {
			// Nineteen decimal digits, optional separators, and a sign fit in at most 38 bytes. Radix integers may have arbitrarily many leading zeros.
			if (strlen($text) > 38) {
				$this->failIntegerRange($text, $start);
			}

			if ($text === '-0') {
				$this->fail('“-0” is not allowed, because zero has one spelling: 0', $start);
			}

			$value = self::parseDecimalInteger(str_replace('_', '', $text));
		}

		if (!is_int($value)) {
			$this->failIntegerRange($text, $start);
		}

		return $value;
	}

	/**
	Report an integer that cannot fit in the signed 64-bit range.
	*/
	private function failIntegerRange(#[\SensitiveParameter] string $text, int $start): never
	{
		$this->fail('The integer ' . self::abbreviate($text) . ' is outside the 64-bit range (-9223372036854775808 to 9223372036854775807)', $start);
	}

	/*
	A decimal int without leading zeros, or `null` when it is outside the 64-bit range, because `(int)` saturates rather than fail.
	*/
	private static function parseDecimalInteger(#[\SensitiveParameter] string $digits): ?int
	{
		// Note: Not using `filter_var()` because it would add a filter extension requirement to core decoding; PHP can be compiled without that extension.
		$isNegative = $digits[0] === '-';
		$length = strlen($digits) - ($isNegative ? 1 : 0);

		if ($length < 19) {
			return (int) $digits;
		}

		if ($length > 19 || strcmp($isNegative ? substr($digits, 1) : $digits, $isNegative ? '9223372036854775808' : '9223372036854775807') > 0) {
			return null;
		}

		return (int) $digits;
	}

	/*
	A comma used as a thousands separator, as in `[1,000]`, makes the group after it a separate item, which fails only when it has a leading zero. Writing that item in octal, as the leading zero message suggests, would parse to the wrong items.
	*/
	/** @return non-empty-string|null */
	private function describeThousandsSeparator(#[\SensitiveParameter] string $text, int $start): ?string
	{
		if (preg_match('/^0[0-9]{2}\z/', $text) !== 1 || $this->byteAt($start - 1) !== ',') {
			return null;
		}

		$getGroupStart = function (int $groupEnd): int {
			$groupStart = $groupEnd;

			while (self::isDigit($this->byteAt($groupStart - 1))) {
				$groupStart--;
			}

			return $groupStart;
		};

		$groupEnd = $start - 1;
		$numberStart = $getGroupStart($groupEnd);

		// Earlier groups of three digits, as in `12,345,000`.
		while ($groupEnd - $numberStart === 3 && $this->byteAt($numberStart - 1) === ',' && self::isDigit($this->byteAt($numberStart - 2))) {
			$groupEnd = $numberStart - 1;
			$numberStart = $getGroupStart($groupEnd);
		}

		$before = $this->byteAt($numberStart - 1);

		// The first group has one to three digits, which are not the end of a float, of a number with underscores, or of a number with a radix prefix.
		if ($numberStart === $groupEnd || $before === '.' || $before === '_' || $numberStart < $groupEnd - 3 || self::isLetter($before)) {
			return null;
		}

		// The sign belongs to the number, as in `-1,000`.
		if ($before === '-') {
			$numberStart--;
		}

		$groupsStart = $start + strlen($text);

		if ($groupsStart - $numberStart > self::MAX_DIAGNOSED_LENGTH) {
			return 'Leading zeros are not allowed in a decimal number. A comma separates items, not digit groups. Use underscores between digits of one number';
		}

		preg_match('/^(?:,[0-9]{3}(?![0-9]))*/', substr($this->source, $groupsStart, self::MAX_DIAGNOSED_LENGTH), $groups);
		$number = substr($this->source, $numberStart, $groupsStart + strlen($groups[0] ?? '') - $numberStart);
		$reason = 'Leading zeros are not allowed in a decimal number. A comma separates items, so ' . self::abbreviate($number) . ' is not one number';
		// Both spellings are the same int, so they are suggested only when it is in range.
		$withUnderscores = str_replace(',', '_', $number);
		return self::isValidValue($withUnderscores) ? "{$reason}. Write it as " . self::abbreviate($withUnderscores) . ' or ' . self::abbreviate(str_replace(',', '', $number)) : $reason;
	}

	/*
	An offset on its own after spaces, as in `2026-09-19T14:00:00 Z`, which belongs directly after the time, or `null`.
	*/
	private function findOffsetAfterSpace(): ?string
	{
		$offsetStart = $this->skipSpaces($this->index);

		if ($offsetStart === $this->index || preg_match('/\GZ|\G[+\-][0-9]{2}:[0-9]{2}/', $this->source, $match, 0, $offsetStart) !== 1) {
			return null;
		}

		return $this->isValueEndAt($offsetStart + strlen($match[0])) ? $match[0] : null;
	}

	private function parseInstant(#[\SensitiveParameter] string $text, int $start): ?Instant
	{
		$isMatch = strlen($text) <= self::MAX_DIAGNOSED_LENGTH && preg_match(self::INSTANT, $text, $match, \PREG_UNMATCHED_AS_NULL) === 1;

		if (!$isMatch) {
			// A date, a space, and a time, as TOML and Python write a date and time.
			$timeStart = $this->index + 1;
			$end = $this->byteAt($this->index) === ' ' && self::isDigit($this->byteAt($timeStart)) ? $timeStart + 1 + strspn($this->source, self::NUMBER_CHARACTERS, $timeStart + 1) : $this->index;
			$time = $end > $timeStart ? substr($this->source, $timeStart, min($end - $timeStart, self::MAX_DIAGNOSED_LENGTH + 1)) : '';
			// More of the instant may follow, as in `14:00:00,5Z` or `14:00:00[Europe/Oslo]`, so it may have an offset.
			$isWholeValue = $this->isValueEndAt($end) && !($this->byteAt($end) === ',' && self::isDigit($this->byteAt($end + 1)));
			$this->fail(self::describeBadInstant($text, $time, $isWholeValue, $this->findOffsetAfterSpace()), $start);
		}

		$year = (int) $match[1];
		$month = (int) $match[2];
		$day = (int) $match[3];
		$hour = (int) $match[4];
		$minute = (int) $match[5];
		$second = (int) $match[6];
		$fraction = $match[7] ?? '';
		$offset = $match[8];
		$offsetHour = (int) ($match[9] ?? 0);
		$offsetMinute = (int) ($match[10] ?? 0);
		$lastDay = Instant::daysInMonth($year, $month);

		// The checks in order, so that the first that fails is reported.
		$reason = match (true) {
			strlen($fraction) > 9 => 'a fractional second has at most nine digits',
			$year === 0 => 'the year must be 0001 to 9999',
			$month < 1 || $month > 12 => 'the month must be 01 to 12',
			$day < 1 || $day > $lastDay => "the day must be 01 to {$lastDay} in that month",
			$hour > 23 => 'the hour must be 00 to 23',
			$minute > 59 => 'the minute must be 00 to 59',
			$second > 59 => 'the second must be 00 to 59, and a leap second is not representable',
			$offsetHour > 23 => 'the offset hour must be 00 to 23',
			$offsetMinute > 59 => 'the offset minute must be 00 to 59',
			$offset === '-00:00' => '-00:00 means “offset unknown” in RFC 3339, which is not representable; use Z or +00:00',
			default => null,
		};

		if ($reason !== null) {
			$this->fail('Invalid instant ' . self::abbreviate($text) . ": {$reason}", $start);
		}

		$offsetSeconds = $offset === 'Z' ? 0 : ($offset[0] === '-' ? -1 : 1) * ($offsetHour * 3600 + $offsetMinute * 60);
		$seconds = Instant::daysFromCivil($year, $month, $day) * 86_400 + $hour * 3600 + $minute * 60 + $second - $offsetSeconds;

		if ($seconds < Instant::MIN_EPOCH_SECONDS || $seconds > Instant::MAX_EPOCH_SECONDS) {
			$this->fail('Invalid instant ' . self::abbreviate($text) . ': in UTC it falls outside the years 0001 to 9999', $start);
		}

		return $this->collectValues ? new Instant($seconds, $fraction === '' ? 0 : (int) $fraction * 10 ** (9 - strlen($fraction))) : null;
	}

	/*
	A scan of the parts in order, so that the error is about the first part that is wrong. The total is added up as a negative number, because the magnitude of the smallest int64 does not fit in a positive one.
	*/
	private function parseDuration(#[\SensitiveParameter] string $text, int $start): ?Duration
	{
		$isNegative = $text[0] === '-';
		$length = strlen($text);
		$index = $isNegative ? 1 : 0;
		$previousRank = -1;
		$total = 0;
		$isOutOfRange = false;
		// The fraction of the last part, whose unit supplies the conversion scale. Only the last part may have one, so the value checks wait until every part is read.
		$fraction = '';

		while ($index < $length) {
			$digitsEnd = self::skipIntegerPart($text, $index);
			$next = $text[$digitsEnd] ?? '';

			if ($digitsEnd === $index) {
				$byte = $text[$index];

				if ($byte === '-' && self::isDigit($text[$index + 1] ?? '')) {
					$this->failDuration($text, $start, 'only the whole duration takes a sign, as in -1h30m');
				}

				$this->failDuration($text, $start, $byte === '+' ? 'a “+” sign is not allowed' : 'expected a number at “' . self::abbreviate(substr($text, $index, 11), 10) . '”');
			}

			if ($text[$index] === '0' && ($next === '_' || self::isDigit($next))) {
				$this->failDuration($text, $start, 'leading zeros are not allowed');
			}

			if ($next === '_') {
				$this->failDuration($text, $start, 'an underscore must be between two digits');
			}

			$fraction = '';
			$unitStart = $digitsEnd;

			if ($next === '.') {
				$unitStart = self::skipDigits($text, $digitsEnd + 1);

				if ($unitStart === $digitsEnd + 1) {
					$this->failDuration($text, $start, 'a “.” must be followed by a digit');
				}

				if (($text[$unitStart] ?? '') === '_') {
					$this->failDuration($text, $start, 'an underscore must be between two digits');
				}

				// Trailing zeros change nothing, and leaving them out keeps the arithmetic small however many there are.
				$fractionStart = $digitsEnd + 1;
				$fractionLength = $unitStart - $fractionStart;
				// Fourteen digits, with a possible underscore between each, suffice to decide nanosecond precision. Only zeros may follow this bounded prefix.
				$prefixLength = min($fractionLength, 27);
				$fraction = rtrim(str_replace('_', '', substr($text, $fractionStart, $prefixLength)), '0');

				if (strspn($text, '0_', $fractionStart + $prefixLength, $fractionLength - $prefixLength) !== $fractionLength - $prefixLength) {
					$fraction = str_repeat('1', 14);
				}
			}

			$unitLength = strspn($text, self::LETTERS, $unitStart);
			$unitEnd = $unitStart + $unitLength;
			$candidate = substr($text, $unitStart, min($unitLength, 11));
			$unitData = Duration::UNITS[$candidate] ?? null;

			if ($unitData === null) {
				$this->failDuration($text, $start, self::describeBadDurationUnit($candidate, $text));
			}

			[$size, $factor, $power, $rank] = $unitData;

			if ($rank <= $previousRank) {
				$this->failDuration($text, $start, 'the units are in the order h, m, s, ms, us, ns, and each appears at most once');
			}

			if ($next === '.' && self::isDigit($text[$unitEnd] ?? '')) {
				$this->failDuration($text, $start, 'only the last part may have a fraction');
			}

			if (!$isOutOfRange) {
				// An integer has at most nineteen digits. With underscores, its raw spelling has at most thirty-seven bytes.
				$part = $digitsEnd - $index > 37 ? null : self::negativeDurationPart(str_replace('_', '', substr($text, $index, $digitsEnd - $index)), $size);

				if ($part === null || $total < \PHP_INT_MIN - $part) {
					$isOutOfRange = true;
				} else {
					$total += $part;
				}
			}

			$index = $unitEnd;
			$previousRank = $rank;
		}

		// More than 13 fraction digits without a trailing zero is finer than a nanosecond in any unit.
		$fractionLength = strlen($fraction);

		if ($fractionLength > 13) {
			$this->failDuration($text, $start, 'it is not a whole number of nanoseconds');
		}

		if ($fraction !== '') {
			// Less than 10^13 times at most 3600, so it fits.
			$numerator = (int) $fraction * $factor;

			if ($fractionLength <= $power) {
				$fractionNanoseconds = $numerator * 10 ** ($power - $fractionLength);
			} else {
				$divisor = 10 ** ($fractionLength - $power);

				if ($numerator % $divisor !== 0) {
					$this->failDuration($text, $start, 'it is not a whole number of nanoseconds');
				}

				$fractionNanoseconds = intdiv($numerator, $divisor);
			}

			if (!$isOutOfRange) {
				if ($total < \PHP_INT_MIN + $fractionNanoseconds) {
					$isOutOfRange = true;
				} else {
					$total -= $fractionNanoseconds;
				}
			}
		}

		if ($isNegative && !$isOutOfRange && $total === 0) {
			$this->failDuration($text, $start, '“-” is not allowed before zero, because zero has one spelling: 0s');
		}

		if ($isOutOfRange || (!$isNegative && $total === \PHP_INT_MIN)) {
			$this->failDuration($text, $start, 'it is outside the 64-bit range of nanoseconds, about 292 years either way');
		}

		return $this->collectValues ? new Duration($isNegative ? $total : -$total) : null;
	}

	private function failDuration(#[\SensitiveParameter] string $text, int $start, #[\SensitiveParameter] string $reason): never
	{
		$this->fail('Invalid duration ' . self::abbreviate($text) . ": {$reason}", $start);
	}

	/*
	`-$digits * $size`, or `null` when its magnitude is more than 2^63.
	*/
	private static function negativeDurationPart(#[\SensitiveParameter] string $digits, int $size): ?int
	{
		if (strlen($digits) > 19) {
			return null;
		}

		$number = self::parseDecimalInteger("-{$digits}");

		if ($number === null || $number < intdiv(\PHP_INT_MIN, $size)) {
			return null;
		}

		return $number * $size;
	}

	/*
	Whether `$text` is one complete valid scalar, so that an error message only suggests a fix that works. Diagnostic suggestions never contain containers or surrounding trivia.
	*/
	private static function isValidValue(#[\SensitiveParameter] string $text): bool
	{
		try {
			// Associative, so that the limit of objects on a key that starts with NUL never makes a suggestion look invalid.
			$decoder = new self($text, true, collectValues: false);
			$decoder->parseValue(1);
			return $decoder->index === $decoder->length;
		} catch (DecodeException) {
			return false;
		}
	}

	/**
	The kind of number `$text` is, an int in any radix or a float, following the grammar, or `null` when it is neither. A run of digits may have single underscores between digits.

	@return 'int'|'float'|null
	*/
	private static function classifyNumber(#[\SensitiveParameter] string $text): ?string
	{
		if (($text[0] ?? '') === '0') {
			$radix = self::RADICES[$text[1] ?? ''] ?? null;

			if ($radix !== null) {
				$length = strlen($text);

				if ($length <= 2 || $text[2] === '_' || $text[$length - 1] === '_') {
					return null;
				}

				return strspn($text, $radix['digits'] . '_', 2) === $length - 2 && !str_contains($text, '__') ? 'int' : null;
			}
		}

		$index = ($text[0] ?? '') === '-' ? 1 : 0;

		// The integer part is `0` or has no leading zero.
		$integerEnd = self::skipIntegerPart($text, $index);

		if ($integerEnd === $index) {
			return null;
		}

		$index = $integerEnd;
		$kind = 'int';

		if (($text[$index] ?? '') === '.') {
			$end = self::skipDigits($text, $index + 1);

			if ($end === $index + 1) {
				return null;
			}

			$index = $end;
			$kind = 'float';
		}

		if (($text[$index] ?? '') === 'e') {
			$isNegative = ($text[$index + 1] ?? '') === '-';
			$exponentStart = $index + ($isNegative ? 2 : 1);
			// The exponent follows the same leading-zero rule as the integer part, and its zero has one spelling, `e0`, so `e-0` is not an exponent.
			$end = $isNegative && ($text[$exponentStart] ?? '') === '0' ? $exponentStart : self::skipIntegerPart($text, $exponentStart);

			if ($end === $exponentStart) {
				return null;
			}

			$index = $end;
			$kind = 'float';
		}

		return $index === strlen($text) ? $kind : null;
	}

	/*
	Returns the index after a run of digits that may have single underscores between them, or `$index` when there is no digit there.
	*/
	private static function skipDigits(#[\SensitiveParameter] string $text, int $index): int
	{
		$end = $index + strspn($text, self::DIGITS, $index);

		if ($end === $index || ($text[$end] ?? '') !== '_') {
			return $end;
		}

		$end += strspn($text, self::DIGITS . '_', $end);
		$invalid = strpos($text, '__', $index);

		if ($invalid !== false && $invalid < $end) {
			return $invalid;
		}

		return $text[$end - 1] === '_' ? $end - 1 : $end;
	}

	/*
	Returns the index after an integer part, which is `0` or digits without a leading zero, or `$index` when there is none. Anything after a leading `0`, such as the `5` in `05`, is left for the caller to reject.
	*/
	private static function skipIntegerPart(#[\SensitiveParameter] string $text, int $index): int
	{
		return ($text[$index] ?? '') === '0' ? $index + 1 : self::skipDigits($text, $index);
	}

	/*
	Whether a token that is not a number or an instant was meant as a duration: its first letter could begin a unit in either case, a day, a week, or a month (`M`), or it is a year unit. A radix prefix, an exponent, and other letters, such as the `T` in `20260919T140000Z` or the `x` in `1.5x`, are left to the number errors.
	*/
	private static function isDurationLike(#[\SensitiveParameter] string $text): bool
	{
		$index = ($text[0] ?? '') === '-' ? 1 : 0;

		if (!self::isDigit($text[$index] ?? '')) {
			return false;
		}

		$index += strspn($text, '0123456789_', $index);

		if (($text[$index] ?? '') === '.') {
			$index++;
			$index += strspn($text, '0123456789_', $index);
		}

		$letter = $text[$index] ?? '';

		// A year unit, in any case, as in `1y` or `2years`, is only one when the token ends after it or another part follows it, so that a word such as `100yen` stays a string to quote. The longest unit and the character after it fit in 6 characters.
		return ($letter !== '' && str_contains('dhmnsuwDHMNSUW', $letter)) || preg_match('/^(?:y|yrs?|years?)(?:\z|[0-9])/i', substr($text, $index, 6)) === 1;
	}

	/**
	@return non-empty-string
	*/
	private static function describeBadDurationUnit(#[\SensitiveParameter] string $unit, #[\SensitiveParameter] string $text): string
	{
		if ($unit === '') {
			return 'every number needs a unit: h, m, s, ms, us, or ns';
		}

		if (preg_match('/^d(?:ays?)?\z/', $unit) === 1) {
			return 'there is no day unit, because a day is not a fixed length. Write 24h for a fixed 24 hours';
		}

		if (preg_match('/^w(?:eeks?)?\z/', $unit) === 1) {
			return 'there is no week unit, because a day is not a fixed length. Write 168h for a fixed 168 hours';
		}

		if (preg_match('/^(?:y|yrs?|years?)\z/i', $unit) === 1) {
			return 'there is no year unit, because a year is not a fixed length. Write 8760h for a fixed 365 days';
		}

		if ($unit === 'M') {
			// A number with `M` alone is more often a size, as in `memory: 512M`, and `512m` would read as minutes.
			$sizeHint = strlen($text) <= self::MAX_DIAGNOSED_LENGTH && preg_match('/^[0-9][0-9_]*M\z/', $text) === 1 ? '. A size, such as 512M, is a string: \'' . self::abbreviate($text) . '\'' : '';
			return "there is no month unit, because a month is not a fixed length{$sizeHint}";
		}

		$lowercase = strtolower($unit);
		return isset(Duration::UNITS[$lowercase]) ? "the units are lowercase: {$lowercase}" : '“' . self::abbreviate($unit, 10) . '” is not a unit. The units are h, m, s, ms, us, and ns, and a string must be quoted';
	}

	/*
	The number of spaces and tabs before `$offset` on its line, or `null` when something else comes before it.
	*/
	private function getIndentation(int $offset): ?int
	{
		$lineStart = $this->lineStart($offset);
		return $this->skipSpaces($lineStart) === $offset ? $offset - $lineStart : null;
	}

	/*
	The offset where the line that contains `$offset` starts.
	*/
	private function lineStart(int $offset): int
	{
		if ($offset <= 0) {
			return 0;
		}

		$lineFeed = strrpos($this->source, "\n", $offset - 1 - $this->length);
		return $lineFeed === false ? 0 : $lineFeed + 1;
	}

	/*
	The offset of the line feed that ends the line containing `$offset`, or the length of the source on the last line.
	*/
	private function findLineEnd(int $offset): int
	{
		if ($offset >= $this->length) {
			return $this->length;
		}

		$end = strpos($this->source, "\n", $offset);
		return $end === false ? $this->length : $end;
	}

	/*
	The offset after the spaces and tabs from `$offset` on.
	*/
	private function skipSpaces(int $offset): int
	{
		return $offset + strspn($this->source, " \t", $offset);
	}

	/*
	The offset after the last character before `$offset` that is not a space or a tab.
	*/
	private function skipSpacesBack(int $offset): int
	{
		while ($offset > 0) {
			$start = max(0, $offset - 4096);
			$length = strlen(rtrim(substr($this->source, $start, $offset - $start), " \t"));

			if ($length > 0) {
				return $start + $length;
			}

			$offset = $start;
		}

		return $offset;
	}

	/*
	The UTF-8 character that starts at `$offset`, or an empty string at the end.
	*/
	private function characterAt(int $offset): string
	{
		if ($offset >= $this->length) {
			return '';
		}

		$byte = ord($this->source[$offset]);
		return substr($this->source, $offset, $byte < 0x80 ? 1 : ($byte < 0xE0 ? 2 : ($byte < 0xF0 ? 3 : 4)));
	}

	/*
	The text from `$offset` that a diagnostic regular expression looks at: up to `MAX_DIAGNOSED_LENGTH` UTF-16 code units, as the JavaScript reference counts it, so that the two give the same message.
	*/
	private function window(int $offset): string
	{
		return substr($this->source, $offset, $this->diagnosedEnd($offset) - $offset);
	}

	/*
	The offset after `MAX_DIAGNOSED_LENGTH` UTF-16 code units from `$offset`, or the end of the source. A character above U+FFFF is two code units, and the end never splits one.
	*/
	private function diagnosedEnd(int $offset): int
	{
		return self::utf16End($this->source, $offset, self::MAX_DIAGNOSED_LENGTH);
	}

	/**
	The byte offset after at most the requested UTF-16 code units of valid UTF-8 text. Both diagnostic windows and abbreviated text stop on whole characters.
	*/
	private static function utf16End(#[\SensitiveParameter] string $text, int $offset, int $maximumLength): int
	{
		$end = strlen($text);
		$units = strspn($text, self::ASCII, $offset, max(0, min($maximumLength, $end - $offset)));
		$offset += $units;

		while ($offset < $end && $units < $maximumLength) {
			$byte = ord($text[$offset]);
			$size = $byte < 0x80 ? 1 : ($byte < 0xE0 ? 2 : ($byte < 0xF0 ? 3 : 4));
			$units += $size === 4 ? 2 : 1;

			if ($units > $maximumLength) {
				break;
			}

			$offset += $size;
		}

		return $offset;
	}

	private static function isQuotableKeyStart(#[\SensitiveParameter] string $character): bool
	{
		return $character !== '' && preg_match('/^' . self::QUOTABLE_KEY_CHARACTER . '/u', $character) === 1;
	}

	private static function isBareKeyCharacter(#[\SensitiveParameter] string $byte): bool
	{
		return $byte !== '' && str_contains(self::BARE_KEY_CHARACTERS, $byte);
	}

	private static function isDigit(#[\SensitiveParameter] string $byte): bool
	{
		return $byte !== '' && $byte >= '0' && $byte <= '9';
	}

	private static function isLetter(#[\SensitiveParameter] string $byte): bool
	{
		return $byte !== '' && str_contains(self::LETTERS, $byte);
	}

	private static function isSpace(#[\SensitiveParameter] string $byte): bool
	{
		return $byte === ' ' || $byte === "\t";
	}

	private static function isSpaceOrLineEnd(#[\SensitiveParameter] string $byte): bool
	{
		return $byte === ' ' || $byte === "\t" || $byte === "\n" || $byte === '';
	}

	/*
	The example in a message that a string must be quoted, which quotes `$text` as a `'...'` string. That cannot hold a `'`, so then there is no simple example.
	*/
	private static function quotingExample(#[\SensitiveParameter] string $text): string
	{
		return str_contains($text, '\'') ? '' : ', as in \'' . self::abbreviate($text) . '\'';
	}

	/**
	`$text` is the unquoted string that `$word` begins, which the suggestion quotes.

	@return non-empty-string
	*/
	private static function describeUnknownWord(#[\SensitiveParameter] string $word, #[\SensitiveParameter] string $text): string
	{
		// A keyword hint is only for the word on its own. In `Yes please` or `Nan Goldin`, following it would leave text behind or change the value.
		$lowercase = $text === $word ? strtolower($word) : '';

		if (in_array($lowercase, ['true', 'false', 'yes', 'no', 'on', 'off'], true)) {
			return "“{$word}” is not a value. Booleans are written true and false, in lowercase";
		}

		if (in_array($lowercase, ['null', 'nil', 'none', 'undefined'], true)) {
			return "“{$word}” is not a value. Null is written null, in lowercase";
		}

		if ($lowercase === 'nan') {
			return 'NaN is not representable. Use null for a missing value';
		}

		return in_array($lowercase, ['inf', 'infinity'], true) ? "“{$word}” is not a value. Infinity is written infinity, in lowercase" : 'Unexpected “' . self::abbreviate($word) . '”. A string value must be quoted' . self::quotingExample($text);
	}

	/**
	`$rest` is the text from the `u` of the escape.

	@return non-empty-string
	*/
	private static function describeBadUnicodeEscape(#[\SensitiveParameter] string $rest): string
	{
		if (preg_match('/^u[0-9A-Fa-f]{4}/', $rest) === 1) {
			return self::describeFourDigitEscape($rest);
		}

		if (preg_match('/^u\{[0-9A-Fa-f]*[A-F]/', $rest) === 1) {
			return 'A Unicode escape uses lowercase hexadecimal digits';
		}

		if (str_starts_with($rest, 'u{}')) {
			return 'A Unicode escape needs one to six hexadecimal digits';
		}

		return preg_match('/^u\{[0-9a-f]{7}/', $rest) === 1 ? 'A Unicode escape has at most six hexadecimal digits' : 'A Unicode escape is written \u{…} with one to six lowercase hexadecimal digits';
	}

	/**
	The JSON form `\uXXXX`, from its `u`, with the escape to write instead. JSON writes a character above U+FFFF as two of them, a surrogate pair, which is one `\u{…}` escape here. Here, a lone surrogate and a carriage return have no escape.

	@return non-empty-string
	*/
	private static function describeFourDigitEscape(#[\SensitiveParameter] string $rest): string
	{
		$code = (int) hexdec(substr($rest, 1, 4));
		$low = preg_match('/^\\\\u[0-9A-Fa-f]{4}/', substr($rest, 5)) === 1 ? substr($rest, 7, 4) : null;
		$lowCode = $low === null ? -1 : (int) hexdec($low);

		if ($code >= 0xD800 && $code <= 0xDBFF && $lowCode >= 0xDC00 && $lowCode <= 0xDFFF) {
			$codePoint = 0x10000 + (($code - 0xD800) * 0x400) + ($lowCode - 0xDC00);
			return 'The four-digit \\' . substr($rest, 0, 5) . "\\u{$low} form is not an escape. Write \\u{" . dechex($codePoint) . '}';
		}

		$form = 'The four-digit \\' . substr($rest, 0, 5) . ' form is not an escape';

		if ($code >= 0xD800 && $code <= 0xDFFF) {
			return "{$form}, and a lone surrogate is not a Unicode scalar value. Write the character it is half of as one \\u{…} escape";
		}

		return $code === 0x0D ? "{$form}, and a carriage return (U+000D) cannot be represented" : "{$form}. Write \\u{" . dechex($code) . '}';
	}

	/**
	`$unquotedText` is the unquoted string that `$fullText` begins, for the suggestion to quote it.

	@return non-empty-string
	*/
	private static function describeBadNumber(#[\SensitiveParameter] string $fullText, #[\SensitiveParameter] string $unquotedText): string
	{
		$text = substr($fullText, 0, self::MAX_DIAGNOSED_LENGTH);

		if (str_contains($text, '+')) {
			return 'A “+” sign is not allowed in a number, including in an exponent';
		}

		if (preg_match('/^-?0[BOX]/', $text) === 1) {
			return 'A number prefix is lowercase: 0x, 0o, or 0b';
		}

		// The whole number, not the part cut at the diagnosed length, because a cut can end before the digit or the underscore that the message is about. Each check below takes linear time.
		if (preg_match('/^(-?)0([box])/', $fullText, $radixMatch) === 1) {
			[$prefix, $sign, $radix] = $radixMatch;
			$digitsStart = strlen($prefix);
			$digitsLength = strlen($fullText) - $digitsStart;
			$radixData = self::RADICES[$radix];
			$name = $radixData['name'];

			if ($sign === '-') {
				return $radixData['article'] . " {$name} integer cannot have a sign, because it states a bit pattern rather than a quantity";
			}

			if ($digitsLength === 0) {
				return "Expected {$name} digits after “0{$radix}”";
			}

			$validCharacters = $radixData['digits'] . ($radix === 'x' ? 'abcdef' : '') . '_';

			if ($radix === 'x' && strcspn($fullText, 'abcdef', $digitsStart) < $digitsLength && strspn($fullText, $validCharacters, $digitsStart) === $digitsLength) {
				// The uppercase spelling is only suggested when it is valid, so a misplaced underscore is reported first, and a value outside the 64-bit range gets no example.
				if ($fullText[$digitsStart] === '_' || str_ends_with($fullText, '_') || str_contains($fullText, '__')) {
					return 'An underscore in a number must be between two digits';
				}

				if (strlen($fullText) > self::MAX_DIAGNOSED_LENGTH) {
					return 'Hexadecimal digits are uppercase';
				}

				$uppercase = strtoupper($fullText);
				$uppercase[1] = 'x';
				return self::isValidValue($uppercase) ? 'Hexadecimal digits are uppercase: ' . self::abbreviate($uppercase) : 'Hexadecimal digits are uppercase';
			}

			// A lowercase hexadecimal digit is a digit in the wrong case, so the one named is a character that is no digit in either case.
			$invalid = strspn($fullText, $validCharacters, $digitsStart);
			return $invalid === $digitsLength ? 'An underscore in a number must be between two digits' : "Invalid {$name} digit “{$fullText[$digitsStart + $invalid]}”";
		}

		// Only when the “E” is where an exponent marker goes, with digits, a `-`, or nothing after it. In `5EUR` or `10EB`, it is part of a word, so lowercasing it would not help.
		if (preg_match('/^-?[0-9][0-9_]*(?:\.[0-9_]+)?E-?[0-9_]*\z/', $text) === 1) {
			return 'An exponent marker is a lowercase “e”';
		}

		if (preg_match('/^-?[0-9_]+\.(?:\z|[^0-9_])/', $text) === 1) {
			return 'A decimal point must be followed by a digit';
		}

		if (str_starts_with($text, '-.')) {
			return 'A number cannot begin with “.”; write a digit before it, as in -0.5';
		}

		if (preg_match('/^[0-9]+(?::[0-9]+)+\z/', $text) === 1) {
			return preg_match('/^[0-9]{1,2}:[0-9]{2}(?::[0-9]{2}(?:\.[0-9]+)?)?\z/', $text) === 1 ? 'A time of day is a string, so it must be quoted' : 'Invalid number “' . self::abbreviate($text) . '”. A value that contains “:” must be quoted, as in \'' . self::abbreviate($text) . '\'';
		}

		// A value with a `:` that starts with a zero, such as the MAC address `00:1A:2B`, is not a number with a leading zero, because removing the zero would not make it valid.
		if (preg_match('/^-?0[0-9_]/', $text) === 1 && !str_contains($text, ':')) {
			// Removing the zero gives a valid number with a different meaning, so the message says what the zero usually meant.
			// A `'...'` string cannot hold a `'`, so then there is no example.
			$identifier = 'an identifier, such as a ZIP code, as a string' . (str_contains($unquotedText, '\'') ? '' : ': \'' . self::abbreviate($unquotedText) . '\'');

			// The octal suggestion is only for the number on its own, not for one that more text follows, as in `0412 345 678`, and only when it is in range. It is decided on the whole number, because a cut can end before a digit that is not octal or that changes the value.
			if ($unquotedText === $fullText && strlen($fullText) > 1 && strspn($fullText, '01234567', 1) === strlen($fullText) - 1) {
				$digitsStart = strspn($fullText, '0');
				$digitsLength = strlen($fullText) - $digitsStart;

				if ($digitsLength <= self::MAX_DIAGNOSED_LENGTH) {
					$octal = '0o' . ($digitsLength === 0 ? '0' : substr($fullText, $digitsStart));

					if (self::isValidValue($octal)) {
						return 'Leading zeros are not allowed in a decimal number. Write an octal number, such as a file mode, as ' . self::abbreviate($octal) . ", and {$identifier}";
					}
				}
			}

			return preg_match('/^0[0-9]+\z/', $text) === 1 ? "Leading zeros are not allowed in a decimal number. Write {$identifier}" : 'Leading zeros are not allowed in a decimal number';
		}

		// Only in a number: an underscore next to a letter other than the exponent marker is part of a word, such as `4k_video`.
		if (preg_match('/_(?:\z|[^0-9])|(?:^|[^0-9])_/', $text) === 1 && strspn($text, '0123456789_.e-') === strlen($text)) {
			return 'An underscore in a number must be between two digits';
		}

		if (preg_match('/^-?[0-9][0-9_]*(?:\.[0-9_]+)?e-?0_?[0-9]/', $text) === 1) {
			return 'Leading zeros are not allowed in an exponent';
		}

		if (preg_match('/^-?[0-9][0-9_]*(?:\.[0-9_]+)?e-0\z/', $text) === 1) {
			return '“e-0” is not allowed, because an exponent of zero has one spelling: e0';
		}

		// Only a number ends with its exponent marker. A word that ends with an “e”, such as `1byte` or `-verbose`, has no exponent.
		if (preg_match('/^-?[0-9][0-9_]*(?:\.[0-9_]+)?e-?\z/', $text) === 1) {
			return 'Expected digits after the exponent marker “e”';
		}

		if (substr_count($text, '.') > 1) {
			return 'Invalid number “' . self::abbreviate($text) . '”. A value with several dots, such as a version number, must be quoted';
		}

		if (preg_match('/^-(?:[^0-9]|\z)/', $text) === 1) {
			// The whole word, as for one without the “-”, so that a word such as `-nano` or `-info` is not taken for NaN or infinity.
			if (preg_match('/^-nan\z/i', $text) === 1) {
				return 'NaN is not representable. Use null for a missing value';
			}

			return preg_match('/^-inf(?:inity)?\z/i', $text) === 1 ? '“' . self::abbreviate($text) . '” is not a value. Negative infinity is written -infinity' : 'Expected a digit or “infinity” after “-”';
		}

		return strpbrk($text, self::LETTERS) !== false ? 'Invalid number “' . self::abbreviate($text) . '”. A string value must be quoted' . self::quotingExample($unquotedText) : 'Invalid number “' . self::abbreviate($text) . '”';
	}

	/**
	A date alone, which is not an instant. The instant it could be is only shown when the date exists, so that the example is valid.

	@return non-empty-string
	*/
	private static function describeDate(#[\SensitiveParameter] string $date): string
	{
		[$year, $month, $day] = array_map(intval(...), explode('-', $date));
		$isExisting = checkdate($month, $day, $year);
		return "{$date} is a date, not an instant. Write a date as a string, as in '{$date}'" . ($isExisting ? ". An instant needs a time and an offset, as in {$date}T00:00:00Z" : '');
	}

	/**
	`$time` is the token after a space that follows `$text`, or an empty string. `$isWholeValue` is whether nothing that may be part of the instant follows `$text`. `$offset` is an offset after a space that follows `$text`, or `null`.

	@return non-empty-string
	*/
	private static function describeBadInstant(#[\SensitiveParameter] string $text, #[\SensitiveParameter] string $time, bool $isWholeValue, #[\SensitiveParameter] ?string $offset): string
	{
		if (strlen($text) > self::MAX_DIAGNOSED_LENGTH) {
			return 'Invalid instant “' . self::abbreviate($text) . '”. ' . self::INSTANT_FORMAT;
		}

		if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $text) === 1) {
			return preg_match('/^[0-9]{2}:[0-9]{2}/', $time) === 1 ? self::describeSpaceSeparatedInstant($text, $time, $isWholeValue) : self::describeDate($text);
		}

		if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}t/', $text) === 1) {
			return 'The date and time separator in an instant is an uppercase “T”';
		}

		if (str_ends_with($text, 'z')) {
			return 'The UTC offset in an instant is an uppercase “Z”';
		}

		if (preg_match('/[+\-][0-9]{4}\z/', $text) === 1) {
			return 'An instant\'s offset is written with a colon, as in +07:00';
		}

		if (preg_match(self::LOCAL_DATE_TIME, $text) !== 1) {
			return 'Invalid instant “' . self::abbreviate($text) . '”. ' . self::INSTANT_FORMAT;
		}

		if ($offset !== null) {
			// The instant has the offset it was meant in, so it is not a local time to write as a string.
			$reason = 'An instant\'s offset follows its time directly, without a space';
			return self::isValidValue("{$text}{$offset}") ? "{$reason}, as in " . self::abbreviate("{$text}{$offset}") : $reason;
		}

		return $isWholeValue ? 'An instant needs an offset: Z or ±HH:MM. A date and time without an offset is a local time, which is not an instant, so write it as a string, as in \'' . self::abbreviate($text) . '\', or add the offset it was meant in' : 'An instant needs an offset: Z or ±HH:MM';
	}

	/**
	A date and a time with a space between them, where an instant has a "T". Following the example must not turn a local time into UTC silently, so a time without an offset is described as what it is. An instant is only shown when it is valid, so a date that does not exist, a time out of range, or an instant outside the years 0001 to 9999 in UTC gets the general format instead.

	@return non-empty-string
	*/
	private static function describeSpaceSeparatedInstant(#[\SensitiveParameter] string $date, #[\SensitiveParameter] string $time, bool $isWholeValue): string
	{
		$separator = 'The date and time separator in an instant is an uppercase “T”, not a space';

		if (strlen($date) + 1 + strlen($time) > self::MAX_DIAGNOSED_LENGTH) {
			return "{$separator}. " . self::INSTANT_FORMAT;
		}

		$instant = "{$date}T{$time}";

		if (self::isValidValue($instant)) {
			return "{$separator}, as in " . self::abbreviate($instant);
		}

		return $isWholeValue && preg_match(self::LOCAL_DATE_TIME, $instant) === 1 && self::isValidValue("{$instant}Z") ? "{$separator}, and an instant needs the offset it was meant in, as in " . self::abbreviate($instant) . 'Z for UTC. A date and time without an offset is a local time, which is not an instant, so write it as a string, as in \'' . self::abbreviate("{$date} {$time}") . '\'' : "{$separator}. " . self::INSTANT_FORMAT;
	}

	/**
	A key as an error message shows it: bare when it can be, and otherwise quoted as `JSON.stringify` quotes it, and cut short when it is long.
	*/
	#[\NoDiscard]
	public static function describeKey(#[\SensitiveParameter] string $key): string
	{
		return Soml::isBareKey($key) ? self::abbreviate($key) : self::jsonQuote(self::abbreviate($key));
	}

	/**
	How `JSON.stringify` escapes the characters it escapes, as the reference shows a quoted key. PHP's JSON encoder supplies the same escapes while retaining Unicode, slashes, and line separators.

	@return non-empty-string
	*/
	private static function jsonQuote(#[\SensitiveParameter] string $text): string
	{
		return \json_encode($text, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_LINE_TERMINATORS | \JSON_THROW_ON_ERROR);
	}

	/**
	Shorten text quoted in an error message, so a huge token does not make a huge message. The length is counted in UTF-16 code units, as the JavaScript reference counts it, so that the two give the same message, and the cut never splits a character.
	*/
	#[\NoDiscard]
	public static function abbreviate(#[\SensitiveParameter] string $text, int $maximumLength = 40): string
	{
		// A character is never more UTF-16 code units than bytes.
		if (strlen($text) <= $maximumLength) {
			return $text;
		}

		// A walk over the characters that are kept, rather than a split of the whole text, which can be a token of millions of characters.
		$end = self::utf16End($text, 0, $maximumLength);
		return $end < strlen($text) ? substr($text, 0, $end) . '…' : $text;
	}

	/**
	Text safe for an error message, with control and format characters escaped. If the text is not valid UTF-8, all non-ASCII bytes are replaced with U+FFFD.
	*/
	#[\NoDiscard]
	public static function describeText(#[\SensitiveParameter] string $text): string
	{
		if (preg_match('//u', $text) !== 1) {
			$text = preg_replace('/[\x80-\xFF]/', "\u{FFFD}", $text) ?? '';
		}

		return preg_replace_callback('/[' . self::DEFAULT_IGNORABLE_CODE_POINT . '\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', static fn (array $match): string => sprintf('\u{%x}', self::codePointOf($match[0])), $text) ?? $text;
	}

	/**
	@return non-empty-string
	*/
	#[\NoDiscard]
	public static function formatCodePoint(int $codePoint): string
	{
		return sprintf('U+%04X', $codePoint);
	}

	/**
	Describe a character for an error message, such as `“$”` or `U+00AD (SOFT HYPHEN)`.

	@return non-empty-string
	*/
	#[\NoDiscard]
	public static function describeCharacter(int $codePoint): string
	{
		$character = self::encodeCodePoint($codePoint);
		$name = self::CHARACTER_NAMES[$codePoint] ?? null;

		if ($name !== null) {
			$isWhitespace = preg_match('/^[' . self::WHITE_SPACE . '\x{200B}\x{FEFF}]\z/u', $character) === 1;
			return self::formatCodePoint($codePoint) . " ({$name}" . ($isWhitespace ? '; only space, tab, and line feed are whitespace' : '') . ')';
		}

		// Control, format, private-use, unassigned, separator, and default-ignorable characters are invisible or ambiguous, so they are shown by code point.
		return $codePoint !== 0x20 && preg_match('/^[' . self::DEFAULT_IGNORABLE_CODE_POINT . '\p{C}\p{Z}]\z/u', $character) === 1 ? self::formatCodePoint($codePoint) : "“{$character}”";
	}

	/**
	The code point of one UTF-8 character.
	*/
	#[\NoDiscard]
	public static function codePointOf(#[\SensitiveParameter] string $character): int
	{
		// Note: Not using `mb_ord()` because it would add an optional mbstring extension dependency.
		$byte = ord($character[0]);

		return match (true) {
			$byte < 0x80 => $byte,
			$byte < 0xE0 => (($byte & 0x1F) << 6) | (ord($character[1]) & 0x3F),
			$byte < 0xF0 => (($byte & 0x0F) << 12) | ((ord($character[1]) & 0x3F) << 6) | (ord($character[2]) & 0x3F),
			default => (($byte & 0x07) << 18) | ((ord($character[1]) & 0x3F) << 12) | ((ord($character[2]) & 0x3F) << 6) | (ord($character[3]) & 0x3F),
		};
	}

	/**
	The UTF-8 encoding of a Unicode scalar value.
	*/
	#[\NoDiscard]
	public static function encodeCodePoint(int $codePoint): string
	{
		// Note: Not using `pack()` because it benchmarked slower for two-byte UTF-8 characters.
		return match (true) {
			$codePoint < 0x80 => chr($codePoint),
			$codePoint < 0x800 => chr(0xC0 | ($codePoint >> 6)) . chr(0x80 | ($codePoint & 0x3F)),
			$codePoint < 0x10000 => chr(0xE0 | ($codePoint >> 12)) . chr(0x80 | (($codePoint >> 6) & 0x3F)) . chr(0x80 | ($codePoint & 0x3F)),
			default => chr(0xF0 | ($codePoint >> 18)) . chr(0x80 | (($codePoint >> 12) & 0x3F)) . chr(0x80 | (($codePoint >> 6) & 0x3F)) . chr(0x80 | ($codePoint & 0x3F)),
		};
	}
}
