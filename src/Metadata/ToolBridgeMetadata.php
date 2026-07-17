<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Metadata;

/**
 * Policy metadata for a tool, sourced from #[ToolBridge]. The tool name is the
 * key: it mirrors the name declared on the matching #[AsTool] attribute.
 */
final readonly class ToolBridgeMetadata
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public array $tags,
        public bool $requiresApproval,
    ) {
    }

    public function hasTag(string $tag): bool
    {
        return \in_array($tag, $this->tags, true);
    }
}
