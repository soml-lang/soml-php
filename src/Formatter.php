<?php

declare(strict_types=1);

namespace Soml;

use function preg_replace;
use function rtrim;
use function str_repeat;
use function strpos;
use function strspn;
use function substr;

/**
Normalizes layout while preserving the tokens and comments of a validated syntax tree.

@internal
*/
final class Formatter
{
	private string $output = '';
	private int $commentIndex = 0;

	private function __construct(#[\SensitiveParameter] private readonly SyntaxTree $tree) {}

	#[\NoDiscard]
	public static function format(#[\SensitiveParameter] string $text): string
	{
		return new self(SyntaxTree::parse($text, collectKeys: false))->print();
	}

	private function print(): string
	{
		$body = $this->tree->body;
		$items = $body->type === 'Object' && !$body->braced ? $body->children : [$body];
		$this->writeList($items, 0, $this->tree->length, 0, false);
		return $this->output . "\n";
	}

	private function newLine(int $level, bool $blank = false): void
	{
		if ($this->output !== '') {
			$this->output .= ($blank ? "\n\n" : "\n") . str_repeat("\t", $level);
		}
	}

	private function peekComment(int $end): ?SyntaxNode
	{
		$comment = $this->tree->comments[$this->commentIndex] ?? null;
		return $comment !== null && $comment->end <= $end ? $comment : null;
	}

	private function writeComment(#[\SensitiveParameter] SyntaxNode $comment): void
	{
		$this->commentIndex++;
		$text = substr($this->tree->text, $comment->start, $comment->length);
		if ($comment->type === 'Line') {
			$this->output .= rtrim($text, " \t");
			return;
		}

		// Block comments retain their indentation, but not trailing whitespace or repeated blank lines.
		// Match each whitespace run only at its start, without backtracking along long indented lines.
		$this->output .= preg_replace(['/(?<![ \t])[ \t]++$/m', '/\n{3,}/'], ['', "\n\n"], $text) ?? $text;
	}

	private function findComma(int $start, int $end): ?int
	{
		// Note: Not using `strpos()` because it has no end offset and can repeatedly scan beyond each gap, making formatting quadratic.
		$text = $this->tree->text;

		if ($start < $end && $text[$start] === ',') {
			return $start;
		}

		for ($index = $this->commentIndex; $start < $end; $index++) {
			$comment = $this->tree->comments[$index] ?? null;
			$gapEnd = $comment === null ? $end : \min($end, $comment->start);
			$comma = $start + \strcspn($text, ',', $start, $gapEnd - $start);

			if ($comma < $gapEnd) {
				return $comma;
			}

			if ($comment === null || $comment->end >= $end) {
				return null;
			}

			// Skip comments so their commas cannot separate items. Their validated spans make rescanning their bodies unnecessary.
			$start = $comment->end;
		}

		return null;
	}

	/** @param list<SyntaxNode> $items */
	private function writeList(#[\SensitiveParameter] array $items, int $start, int $end, int $level, bool $bracketed): void
	{
		$previousEnd = $start;
		$itemEnd = null;
		$canTrail = $bracketed;
		$hasWritten = false;
		$onSameLine = false;

		for ($index = 0, $count = \count($items); $index <= $count; $index++) {
			$item = $items[$index] ?? null;
			$itemStart = $item->start ?? $end;
			$comment = $this->peekComment($itemStart);
			$comma = $comment !== null && $bracketed ? $this->findComma($previousEnd, $itemStart) : null;
			$itemLineStart = $comment === null || $item === null ? null : $this->tree->lastLineStart($previousEnd, $itemStart);

			while ($comment !== null) {
				$trailing = $canTrail && !$onSameLine && $this->isTrailing($comment, $previousEnd, $itemEnd, $itemLineStart, $comma);
				if ($trailing || $onSameLine) {
					$this->output .= ' ';
				} else {
					$this->newLine($level, $hasWritten && $this->tree->hasBlankLine($previousEnd, $comment->start));
				}

				$this->writeComment($comment);
				$hasWritten = $hasWritten || !$trailing;
				$nextComment = $this->peekComment($itemStart);
				$nextStart = $nextComment->start ?? $itemStart;
				$onSameLine = $comment->type === 'Block' && !$trailing && $this->tree->isSameLine($comment->end, $nextStart);
				$previousEnd = $comment->end;
				$comment = $nextComment;
			}

			if ($item === null) {
				return;
			}

			if ($onSameLine) {
				$this->output .= ' ';
			} else {
				$this->newLine($level, $hasWritten && $this->tree->hasBlankLine($previousEnd, $itemStart));
			}

			$this->writeItem($item, $level);
			$previousEnd = $item->end;
			$itemEnd = $previousEnd;
			$canTrail = true;
			$hasWritten = true;
			$onSameLine = false;
		}
	}

	private function isTrailing(#[\SensitiveParameter] SyntaxNode $comment, int $previousEnd, ?int $itemEnd, ?int $itemLineStart, ?int $comma): bool
	{
		$afterComma = $comma !== null && $comment->start > $comma;
		// A block comment before an item on the same line stays with that item.
		if ($itemLineStart !== null && $comment->type === 'Block' && ($comma === null || $afterComma) && $comment->end >= $itemLineStart) {
			return false;
		}

		$lineEnd = $afterComma && $previousEnd === $itemEnd ? $comma + 1 : $previousEnd;
		return $this->tree->isSameLine($lineEnd, $comment->start);
	}

	/** @param list<SyntaxNode> $items */
	private function writeOneLine(#[\SensitiveParameter] array $items, int $start, int $end, int $level, string $opening, string $closing): void
	{
		$this->output .= $opening;
		$previousEnd = $start + 1;
		$hasWrittenToken = false;

		for ($index = 0, $count = \count($items); $index <= $count; $index++) {
			$item = $items[$index] ?? null;
			$itemStart = $item->start ?? ($end - 1);
			$comment = $this->peekComment($itemStart);
			// Validation guarantees a comma between same-line items. Its exact position matters only when a comment shares the gap.
			$comma = $item === null || $index === 0 ? null : ($comment === null ? $previousEnd : $this->findComma($previousEnd, $itemStart));
			$hasWrittenComma = false;

			while ($comment !== null) {
				if (!$hasWrittenComma && $comma !== null && $comma < $comment->start) {
					$this->output .= ',';
					$hasWrittenComma = true;
				}

				$this->output .= $hasWrittenToken ? ' ' : '';
				$this->writeComment($comment);
				$hasWrittenToken = true;
				$comment = $this->peekComment($itemStart);
			}

			if ($item === null) {
				break;
			}

			if ($comma !== null && !$hasWrittenComma) {
				$this->output .= ', ';
			} elseif ($hasWrittenToken) {
				$this->output .= ' ';
			}

			$this->writeItem($item, $level);
			$previousEnd = $item->end;
			$hasWrittenToken = true;
		}

		$this->output .= $closing;
	}

	private function writeItem(#[\SensitiveParameter] SyntaxNode $item, int $level): void
	{
		if ($item->type === 'Member') {
			$this->writeMember($item, $level);
		} else {
			$this->writeValue($item, $level);
		}
	}

	private function writeMember(#[\SensitiveParameter] SyntaxNode $member, int $level): void
	{
		$value = $member->value;
		if ($value === null) {
			throw new \LogicException('A member must have a value.');
		}

		$this->output .= substr($this->tree->text, $member->start, $member->keyEnd - $member->start) . ':';
		$blockString = $value->block;
		$previousEnd = $member->keyEnd + 1;
		$comment = $this->peekComment($value->start);
		$valueLevel = $blockString || ($comment !== null && !$this->tree->isSameLine($member->keyEnd, $value->start)) ? $level + 1 : $level;
		while ($comment !== null) {
			$this->writeSeparator($previousEnd, $comment->start, $level + 1);
			$this->writeComment($comment);
			$previousEnd = $comment->end;
			$comment = $this->peekComment($value->start);
		}

		if ($blockString) {
			$this->newLine($level + 1);
		} elseif ($previousEnd === $member->keyEnd + 1) {
			$this->output .= ' ';
		} else {
			$this->writeSeparator($previousEnd, $value->start, $level + 1);
		}

		$this->writeValue($value, $valueLevel);
	}

	private function writeSeparator(int $start, int $end, int $level): void
	{
		if ($this->tree->isSameLine($start, $end)) {
			$this->output .= ' ';
			return;
		}

		$this->newLine($level);
	}

	private function writeValue(#[\SensitiveParameter] SyntaxNode $node, int $level): void
	{
		if ($node->isContainer) {
			$opening = $node->type === 'Object' ? '{' : '[';
			$closing = $node->type === 'Object' ? '}' : ']';
			if ($node->children === [] && $this->peekComment($node->end) === null) {
				$this->output .= $opening . $closing;
				return;
			}

			if ($this->tree->isSameLine($node->start, $node->end)) {
				$this->writeOneLine($node->children, $node->start, $node->end, $level, $opening, $closing);
				return;
			}

			$this->output .= $opening;
			$this->writeList($node->children, $node->start + 1, $node->end - 1, $level + 1, true);
			$this->newLine($level);
			$this->output .= $closing;
			return;
		}

		if ($node->block) {
			$this->writeBlockString($node, $level);
		} else {
			$this->output .= substr($this->tree->text, $node->start, $node->length);
		}
	}

	private function writeBlockString(#[\SensitiveParameter] SyntaxNode $node, int $level): void
	{
		$text = $this->tree->text;
		$closingStart = $this->tree->lineStart($node->end);
		$oldIndentationLength = strspn($text, " \t", $closingStart);
		$indentation = str_repeat("\t", $level);
		$openingEnd = (int) strpos($text, "\n", $node->start);
		$this->output .= substr($text, $node->start, $openingEnd - $node->start);

		for ($start = $openingEnd + 1; $start < $node->end; $start = $end + 1) {
			$end = $start + \strcspn($text, "\n", $start, $node->end - $start);
			$lineLength = $end - $start;
			$this->output .= "\n";

			if (strspn($text, " \t", $start, $lineLength) === $lineLength) {
				$this->output .= substr($text, $start, $lineLength);
			} else {
				$this->output .= $indentation . substr($text, $start + $oldIndentationLength, $lineLength - $oldIndentationLength);
			}
		}
	}
}
