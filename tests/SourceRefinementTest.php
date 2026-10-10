<?php

declare(strict_types=1);

namespace Soml\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Soml\Soml;
use Soml\SyntaxTree;

final class SourceRefinementTest extends TestCase
{
	#[Test]
	public function commentBoundaryLookupSharesOneOrderedIndex(): void
	{
		$source = "/* first\ncontinued */ /* adjacent */\n[1, # last\n2]";
		$tree = SyntaxTree::parse($source);
		$starts = [];
		$ends = [];

		foreach ($tree->comments as $comment) {
			$starts[$comment->start] = $comment;
			$ends[$comment->end] = $comment;
		}

		for ($offset = -1; $offset <= strlen($source) + 1; $offset++) {
			$this->assertSame($starts[$offset] ?? null, $tree->commentStartingAt($offset));
			$this->assertSame($ends[$offset] ?? null, $tree->commentEndingAt($offset));
		}
	}

	#[Test]
	public function unchangedReplacementDoesNotCopyOrRevalidateALargeDocument(): void
	{
		$source = '#' . str_repeat('x', 4_000_000) . "\na: 1\n";
		$warmup = Soml::edit('a: 1', ['a'], 1);
		$before = memory_get_usage();
		memory_reset_peak_usage();
		$result = Soml::edit($source, ['a'], 1);
		$this->assertLessThan(1_000_000, memory_get_peak_usage() - $before);
		$this->assertSame($source, $result);
	}

	#[Test]
	public function removalTrimsLongTriviaWithoutConsumingAComment(): void
	{
		$source = "[1 /* keep */," . str_repeat(" \t", 65_536) . '2]';
		$this->assertSame('[1 /* keep */]', Soml::edit($source, [1]));
		$this->assertSame([1], Soml::decode(Soml::edit($source, [1])));
	}
}
