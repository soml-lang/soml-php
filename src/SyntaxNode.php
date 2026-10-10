<?php

declare(strict_types=1);

namespace Soml;

/**
A source span used by the formatter and editor. Offsets count UTF-8 bytes, as PHP string operations do. Only members have a value node, and only containers have children.

@internal
*/
final class SyntaxNode
{
	/**
	The number of source bytes covered by the node.
	*/
	public int $length {
		get => $this->end - $this->start;
	}

	/**
	Whether the node owns object members or array items.
	*/
	public bool $isContainer {
		get => $this->type === 'Object' || $this->type === 'Array';
	}

	/**
	@param 'Object'|'Array'|'Member'|'String'|'Scalar'|'Line'|'Block' $type
	@param list<SyntaxNode> $children
	*/
	public function __construct(
		public readonly string $type,
		public readonly int $start,
		public readonly int $end,
		#[\SensitiveParameter] public readonly array $children = [],
		#[\SensitiveParameter] public readonly ?self $value = null,
		#[\SensitiveParameter] public readonly string $key = '',
		public readonly int $keyEnd = 0,
		public readonly bool $braced = true,
		public readonly bool $block = false,
	) {}
}
