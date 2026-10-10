<?php

declare(strict_types=1);

namespace Soml;

use function array_any;
use function array_find;
use function array_is_list;
use function array_key_last;
use function min;
use function preg_match;
use function str_contains;
use function strpos;
use function strspn;
use function substr;
use function substr_replace;

/**
Changes one source span, keeping the surrounding spelling and layout. Comment ownership and comma handling follow the specification and the JavaScript reference editor.

@internal
*/
final class Editor
{
	private bool $isInsideOneLine = false;

	/** @param non-empty-list<string|int> $path */
	private function __construct(
		#[\SensitiveParameter] private readonly SyntaxTree $tree,
		#[\SensitiveParameter] private readonly array $path,
		#[\SensitiveParameter] private readonly mixed $value,
		private readonly bool $remove,
	) {
	}

	/** @param array<mixed> $path */
	#[\NoDiscard]
	public static function edit(#[\SensitiveParameter] string $text, #[\SensitiveParameter] array $path, #[\SensitiveParameter] mixed $value, bool $remove): string
	{
		$path = self::validatePath($path);
		$tree = SyntaxTree::parse($text);
		$editor = new self($tree, $path, $value, $remove);
		$result = $editor->descend($tree->body, 0, $remove ? '' : $editor->indentation($tree->body->start));

		if ($result === $text) {
			return $result;
		}

		// Never return a broken document, including an edit that exceeds the nesting limit.
		try {
			Decoder::validate($result);
		} catch (DecodeException $exception) {
			throw new \LogicException('The edit produced an invalid SOML document', previous: $exception);
		}

		return $result;
	}

	/**
	Validate the shared path contract for edits and decoded-value lookup.

	@param array<mixed> $path
	@return non-empty-list<string|int>
	*/
	public static function validatePath(#[\SensitiveParameter] array $path): array
	{
		if ($path === [] || !array_is_list($path)) {
			throw new \InvalidArgumentException('The path must be a non-empty list of keys and array indexes');
		}

		foreach ($path as $segment) {
			if (!\is_string($segment) && (!\is_int($segment) || $segment < 0)) {
				throw new \InvalidArgumentException('Each path segment must be a string key or a non-negative integer index');
			}

			if (\is_string($segment) && (preg_match('//u', $segment) !== 1 || str_contains($segment, "\r"))) {
				throw new \InvalidArgumentException('A path key must be valid UTF-8 without a carriage return');
			}
		}

		return $path;
	}

	private function descend(#[\SensitiveParameter] SyntaxNode $container, int $index, string $containerIndentation): string
	{
		$depth = $index + 1;
		$segment = $this->path[$index];
		$pathLength = \count($this->path);
		$node = null;

		if ($container->type === 'Array') {
			if (!\is_int($segment)) {
				throw new \InvalidArgumentException('An array requires an integer index, not a key');
			}

			$node = $container->children[$segment] ?? null;
		} elseif ($container->type === 'Object') {
			if (!\is_string($segment)) {
				throw new \InvalidArgumentException('An object requires a string key, not an index');
			}

			$node = array_find($container->children, static fn (SyntaxNode $member): bool => $member->key === $segment);
		} else {
			throw new \InvalidArgumentException('The path leads through a value that is not an object or an array');
		}

		if ($node === null) {
			if ($this->remove) {
				return $this->tree->text;
			}

			if ($container->type === 'Array' && $segment !== \count($container->children)) {
				throw new \InvalidArgumentException('A new array item must use the index at the end of the array');
			}

			$value = $this->value;

			for ($position = $pathLength - 1; $position > $index; $position--) {
				$key = $this->path[$position];

				if (!\is_string($key)) {
					throw new \InvalidArgumentException('Cannot create an array index below a value that does not exist');
				}
			}

			if ($depth + $pathLength - $index - 1 > Decoder::MAX_DEPTH) {
				$exception = Encoder::depthException();

				for ($position = Decoder::MAX_DEPTH - 1; $position >= 0; $position--) {
					$exception->prependPathSegment($this->path[$position]);
				}

				throw $exception;
			}

			for ($position = $pathLength - 1; $position > $index; $position--) {
				$key = $this->path[$position];

				// Casting an array also supports keys starting with NUL, which cannot be assigned as object properties.
				$value = (object) [$key => $value];
			}

			$prefix = $container->type === 'Object' ? Encoder::formatKey((string) $segment) . ': ' : '';
			return $this->insert($container, $containerIndentation, fn (string $indent): string => $prefix . $this->valueText($value, $depth + 1, $indent, $container));
		}

		$valueNode = $node->value ?? $node;

		if ($index < $pathLength - 1) {
			$valueIndentation = $this->remove ? '' : $this->indentation($valueNode->start, $node->type === 'Member' ? $node : null);
			$this->isInsideOneLine = $this->isOneLine($container);
			return $this->descend($valueNode, $index + 1, $valueIndentation);
		}

		if ($this->remove) {
			if (!$container->braced && \count($container->children) === 1) {
				return $this->replace($node->start, $node->end, '{}');
			}

			return $this->removeNode($container, $node);
		}

		// Replacing a block string after a key moves the new value onto the key's line, unless a comment is between them.
		$colonEnd = $node->keyEnd + 1;

		if ($node->type === 'Member' && $valueNode->block && $this->skipWhitespace($colonEnd) === $valueNode->start) {
			return $this->replace($colonEnd, $valueNode->end, ' ' . $this->valueText($this->value, $depth + 1, $this->indentation($node->start), $container));
		}

		$valueIndentation = $this->indentation($valueNode->start, $node->type === 'Member' ? $node : null);
		return $this->replace($valueNode->start, $valueNode->end, $this->valueText($this->value, $depth + 1, $valueIndentation, $container));
	}

	private function replace(int $start, int $end, #[\SensitiveParameter] string $replacement): string
	{
		$length = $end - $start;

		if (\strlen($replacement) === $length && ($length === 0 || \substr_compare($this->tree->text, $replacement, $start, $length) === 0)) {
			return $this->tree->text;
		}

		return substr_replace($this->tree->text, $replacement, $start, $length);
	}

	private function valueText(#[\SensitiveParameter] mixed $value, int $depth, string $indent, #[\SensitiveParameter] SyntaxNode $container): string
	{
		try {
			return Encoder::encodeValue($value, $depth, $indent, $this->isOneLine($container));
		} catch (EncodeException $exception) {
			// Missing objects are already represented in the encoded value's path; prepend only the path consumed before encoding.
			for ($index = $depth - 2; $index >= 0; $index--) {
				$exception->prependPathSegment($this->path[$index]);
			}

			throw $exception;
		}
	}

	private function removeNode(#[\SensitiveParameter] SyntaxNode $container, #[\SensitiveParameter] SyntaxNode $node): string
	{
		$items = $container->children;
		$takeCommaBefore = $node === ($items[array_key_last($items)] ?? null) && $this->character($this->skipTrivia($node->end)) !== ',';
		$ownedStart = $this->ownedStart($node->start);
		$ownedEnd = $this->ownedEnd($node->end);
		$next = $this->skipSpaces($ownedEnd);
		$before = $this->skipSpacesBack($ownedStart);
		$start = $takeCommaBefore && $this->character($before - 1) === ',' ? $before - 1 : $ownedStart;
		$end = $this->character($next) === ',' ? ($this->lineEndAfterTrivia($next + 1) ?? ($next + 1)) : $ownedEnd;
		$lineEnd = $this->lineEndAfterTrivia($end);
		$isWholeLines = $lineEnd !== null && $this->skipSpaces($this->tree->lineStart($start)) === $start;

		if ($isWholeLines) {
			$start = $this->tree->lineStart($start);
			$end = min($lineEnd + 1, $this->tree->length);
			$isBlankBefore = $start > 0 && $this->isBlankLine($this->tree->lineStart($start - 1));
			$isBlankAfter = $end < $this->tree->length && $this->isBlankLine($end);
			$afterBlankLines = $this->skipBlankLines($end);
			$isAtClosing = $afterBlankLines === $this->tree->length || ($container->braced && $this->skipSpaces($afterBlankLines) === $container->end - 1);

			if ($isBlankBefore && ($isBlankAfter || $isAtClosing)) {
				$start = $this->tree->lineStart($start - 1);
			} elseif ($isBlankAfter && $start === $this->contentStart($container)) {
				$end = (int) strpos($this->tree->text, "\n", $end) + 1;
			}
		} else {
			$end = $this->skipSpaces($end);
			$character = $this->character($end);

			if ($character === '' || $character === "\n" || $character === '}' || $character === ']') {
				$start = $this->skipSpacesBack($start);
			}

			// Leave a separating space before a comment that follows a removed last item.
			if ($takeCommaBefore && $this->character($before - 1) === ',' && $this->tree->commentStartingAt($end) !== null) {
				$end = $this->skipSpacesBack($end);
			}
		}

		if (\count($items) === 1 && $container->braced) {
			$commentLeft = array_any($this->tree->comments, static fn (SyntaxNode $comment): bool => $comment->start > $container->start && $comment->start < $container->end && ($comment->start < $start || $comment->start >= $end));

			if (!$commentLeft) {
				return $this->replace($container->start + 1, $container->end - 1, '');
			}
		}

		return $this->replace($start, $end, '');
	}

	/** @param \Closure(string): string $makeItem */
	private function insert(#[\SensitiveParameter] SyntaxNode $container, string $containerIndentation, #[\SensitiveParameter] \Closure $makeItem): string
	{
		$items = $container->children;
		$previous = $items === [] ? null : $items[array_key_last($items)];

		if ($previous === null) {
			$closing = $container->end - 1;

			if ($this->isOneLine($container)) {
				$start = $this->skipSpacesBack($closing);
				return $this->replace($start, $closing, ($start > $container->start + 1 ? ' ' : '') . $makeItem(''));
			}

			$outer = $containerIndentation;
			$inner = "{$outer}\t";
			$lineStart = $this->tree->lineStart($closing);

			if ($lineStart > $container->start && $this->skipSpaces($lineStart) === $closing) {
				return $this->replace($lineStart, $lineStart, $inner . $makeItem($inner) . "\n");
			}

			return $this->replace($this->skipSpacesBack($closing), $closing, "\n{$inner}" . $makeItem($inner) . "\n{$outer}");
		}

		$previousEnd = $this->ownedEnd($previous->end);
		$next = $this->skipTrivia($previousEnd);
		$hasComma = $this->character($next) === ',';
		$afterComma = $hasComma ? $next + 1 : $previousEnd;
		$lineEnd = $this->lineEndAfterTrivia($afterComma);

		if ($lineEnd === null) {
			$item = $makeItem($this->indentation($afterComma));
			return $hasComma ? $this->replace($afterComma, $afterComma, " {$item},") : $this->replace($previousEnd, $previousEnd, ", {$item}");
		}

		$indentation = $this->indentation($previous->start);
		return $this->replace($lineEnd, $lineEnd, "\n{$indentation}" . $makeItem($indentation));
	}

	private function isOneLine(#[\SensitiveParameter] SyntaxNode $container): bool
	{
		return $this->isInsideOneLine || ($container->braced && $this->skipSpaces($container->start + 1) < $container->end - 1 && $this->tree->isSameLine($container->start, $container->end));
	}

	private function ownedStart(int $offset): int
	{
		while (($comment = $this->tree->commentEndingAt($this->skipSpacesBack($offset))) !== null && $comment->type === 'Block') {
			$offset = $comment->start;
		}

		return $offset;
	}

	private function ownedEnd(int $offset): int
	{
		while (($comment = $this->tree->commentStartingAt($this->skipSpaces($offset))) !== null) {
			$offset = $comment->end;
		}

		return $offset;
	}

	private function indentation(int $offset, #[\SensitiveParameter] ?SyntaxNode $member = null): string
	{
		$lineStart = $this->tree->lineStart($offset);

		// A line entered by a block comment uses the indentation of the line where that comment began.
		while (($comment = $this->tree->commentContaining($lineStart)) !== null) {
			// A member value on a later line continues one level deeper than its key, even when the comment's retained text starts that line.
			if ($member !== null && $member->keyEnd < $lineStart) {
				return $this->indentation($member->start) . "\t";
			}

			$lineStart = $this->tree->lineStart($comment->start);
		}

		return substr($this->tree->text, $lineStart, $this->skipSpaces($lineStart) - $lineStart);
	}

	private function contentStart(#[\SensitiveParameter] SyntaxNode $container): ?int
	{
		if (!$container->braced) {
			return $this->skipBlankLines(0);
		}

		$lineEnd = $this->lineEndAfterTrivia($container->start + 1);
		return $lineEnd === null ? null : $this->skipBlankLines($lineEnd + 1);
	}

	private function skipBlankLines(int $offset): int
	{
		// Scan the whitespace in one run, then retain the indentation of the first non-blank line.
		return $this->tree->lastLineStart($offset, $this->skipWhitespace($offset));
	}

	private function isBlankLine(int $offset): bool
	{
		return $this->character($this->skipSpaces($offset)) === "\n";
	}

	private function skipWhitespace(int $offset): int
	{
		return $offset + strspn($this->tree->text, " \t\n", $offset);
	}

	private function skipSpaces(int $offset): int
	{
		return $offset + strspn($this->tree->text, " \t", $offset);
	}

	private function skipSpacesBack(int $offset): int
	{
		while ($offset > 0) {
			$start = \max(0, $offset - 4096);
			$length = \strlen(\rtrim(substr($this->tree->text, $start, $offset - $start), " \t"));

			if ($length > 0) {
				return $start + $length;
			}

			$offset = $start;
		}

		return $offset;
	}

	private function skipTrivia(int $offset): int
	{
		while (true) {
			$offset = $this->skipWhitespace($offset);
			$comment = $this->tree->commentStartingAt($offset);

			if ($comment === null) {
				return $offset;
			}

			$offset = $comment->end;
		}
	}

	private function skipLineTrivia(int $offset): int
	{
		while (true) {
			$offset = $this->skipSpaces($offset);

			$comment = $this->tree->commentStartingAt($offset);

			if ($comment === null) {
				return $offset;
			}

			$offset = $comment->end;
		}
	}

	private function lineEndAfterTrivia(int $offset): ?int
	{
		$offset = $this->skipLineTrivia($offset);
		return $offset === $this->tree->length || $this->character($offset) === "\n" ? $offset : null;
	}

	private function character(int $offset): string
	{
		return $offset < 0 ? '' : ($this->tree->text[$offset] ?? '');
	}
}
