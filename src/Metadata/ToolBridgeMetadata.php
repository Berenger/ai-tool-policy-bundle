<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Metadata;

/**
 * Policy metadata for a tool, sourced from #[ToolBridge]. The tool name is the
 * key: the #[AsTool] name, or the #[ToolBridge(name:)] alias. The policy only
 * applies to a tool whose execution reference is $className.
 */
final readonly class ToolBridgeMetadata
{
    /**
     * @param list<string> $tags
     * @param class-string $className The class declaring #[ToolBridge]
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public array $tags,
        public bool $requiresApproval,
        public string $className,
    ) {
    }

    public function hasTag(string $tag): bool
    {
        return \in_array($tag, $this->tags, true);
    }
}
