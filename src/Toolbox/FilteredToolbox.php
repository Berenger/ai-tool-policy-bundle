<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Toolbox;

use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;
use ToolPolicy\Bundle\Metadata\ToolBridgeMetadata;
use ToolPolicy\Bundle\Registry\ToolBridgeRegistry;

/**
 * Decorates the native Symfony AI Toolbox: execution, schema and argument
 * resolution stay delegated to the inner toolbox. This layer only filters the
 * tool list exposed to the model based on #[ToolBridge] policy (enabled + tags).
 */
final class FilteredToolbox implements ToolboxInterface
{
    /** @var Tool[]|null */
    private ?array $filteredTools = null;

    /**
     * @param list<string> $allowedTags Tags that must match to be visible. Empty = no filtering.
     */
    public function __construct(
        private readonly ToolboxInterface $inner,
        private readonly ToolBridgeRegistry $registry,
        private readonly array $allowedTags = [],
    ) {
    }

    public function getTools(): array
    {
        if (null !== $this->filteredTools) {
            return $this->filteredTools;
        }

        $tools = [];
        foreach ($this->inner->getTools() as $tool) {
            $name = $tool->getName();
            $metadata = $this->registry->get($name);

            // No bridge metadata: keep the tool as-is (it has no policy to enforce).
            if (null === $metadata) {
                $tools[] = $tool;
                continue;
            }

            if (!$metadata->enabled) {
                continue;
            }

            if ([] !== $this->allowedTags && !$this->matchesTags($metadata)) {
                continue;
            }

            $tools[] = $tool;
        }

        return $this->filteredTools = $tools;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        return $this->inner->execute($toolCall);
    }

    /**
     * @return list<string>
     */
    public function getAllowedTags(): array
    {
        return $this->allowedTags;
    }

    private function matchesTags(ToolBridgeMetadata $metadata): bool
    {
        foreach ($this->allowedTags as $tag) {
            if ($metadata->hasTag($tag)) {
                return true;
            }
        }

        return false;
    }
}
