<?php

declare(strict_types=1);

namespace Soml;

use function intdiv;
use function strcspn;
use function strrpos;
use function strspn;

/**
Retains source spans and comments during the decoder's validation pass. Decoded scalar values are not retained; only object keys need their decoded text for editing.

@internal
*/
final class SyntaxTree
{
	public readonly SyntaxNode $body;
	/** @var list<SyntaxNode> */
	public private(set) array $comments = [];

	public readonly int $length;

	private function __construct(#[\SensitiveParameter] public readonly string $text, public readonly bool $collectKeys)
	{
		$this->length = \strlen($text);
		$this->body = Decoder::parseTree($this);
	}

	/**
	Build source spans and comments. Formatting can omit decoded key identities; validation still checks duplicate keys and all string escapes.
	*/
	public static function parse(#[\SensitiveParameter] string $text, bool $collectKeys = true): self
	{
		return new self($text, $collectKeys);
	}

	/**
	Retain one validated comment in source order, using offsets instead of a copied body.

	@param 'Line'|'Block' $type
	*/
	public function recordComment(string $type, int $start, int $end): void
	{
		$this->comments[] = new SyntaxNode($type, $start, $end);
	}

	/**
	Whether a source span contains no line feed. Scan only the requested span without allocating a temporary copy.
	*/
	public function isSameLine(int $start, int $end): bool
	{
		// Note: Not using `strpos()` because it has no end offset and can repeatedly scan beyond each span, making adjacent checks quadratic.
		return strcspn($this->text, "\n", $start, $end - $start) === $end - $start;
	}

	/**
	Whether a source span contains a blank line. Scan the source directly, without a regex or temporary copy of a long comment.
	*/
	public function hasBlankLine(int $start, int $end): bool
	{
		// Note: Not using `preg_match()` because it has no end offset and matching a bounded substring would copy the entire span.
		while ($start < $end) {
			$start += strcspn($this->text, "\n", $start, $end - $start);

			if ($start >= $end) {
				return false;
			}

			$start++;
			$start += strspn($this->text, " \t", $start, $end - $start);

			if ($start < $end && $this->text[$start] === "\n") {
				return true;
			}
		}

		return false;
	}

	/**
	The offset where the line containing `$offset` starts.
	*/
	public function lineStart(int $offset): int
	{
		if ($offset === 0) {
			return 0;
		}

		$previous = strrpos($this->text, "\n", $offset - $this->length - 1);
		return $previous === false ? 0 : $previous + 1;
	}

	/**
	The start of the last line in a span, or `$start` when the span has no line feed. Scans only the span, so repeated adjacent gaps take linear time.
	*/
	public function lastLineStart(int $start, int $end): int
	{
		if ($start === $end || $this->isSameLine($start, $end)) {
			return $start;
		}

		return $this->lineStart($end);
	}

	/**
	The comment whose interior contains an offset. Boundaries are excluded.
	*/
	public function commentContaining(int $offset): ?SyntaxNode
	{
		$comment = $this->commentAt($offset);
		return $comment !== null && $comment->start < $offset ? $comment : null;
	}

	/**
	The comment beginning at an exact source offset.
	*/
	public function commentStartingAt(int $offset): ?SyntaxNode
	{
		$comment = $this->commentAt($offset);
		return $comment !== null && $comment->start === $offset ? $comment : null;
	}

	/**
	The comment ending at an exact source offset.
	*/
	public function commentEndingAt(int $offset): ?SyntaxNode
	{
		$comment = $this->commentAt($offset - 1);
		return $comment !== null && $comment->end === $offset ? $comment : null;
	}

	/**
	Comments are ordered and never overlap, so lookup takes logarithmic time without a second index. The starting byte belongs to the comment; the ending offset does not.
	*/
	private function commentAt(int $offset): ?SyntaxNode
	{
		$low = 0;
		$high = \count($this->comments) - 1;

		while ($low <= $high) {
			$middle = intdiv($low + $high, 2);
			$comment = $this->comments[$middle];

			if ($comment->end <= $offset) {
				$low = $middle + 1;
			} elseif ($comment->start > $offset) {
				$high = $middle - 1;
			} else {
				return $comment;
			}
		}

		return null;
	}
}
