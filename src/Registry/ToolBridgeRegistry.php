<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Registry;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use ToolPolicy\Bundle\Attribute\ToolBridge;
use ToolPolicy\Bundle\Metadata\ToolBridgeMetadata;

/**
 * Maps tool names to their #[ToolBridge] policy metadata. Tool names are read
 * from the matching #[AsTool] attribute on the same class, so the registry
 * stays aligned with what Symfony AI actually exposes to the model.
 *
 * Disabled tools are kept here (enabled=false) so FilteredToolbox can drop
 * them from the exposed list; without the metadata they would pass through.
 */
final class ToolBridgeRegistry
{
    /** @var array<string, ToolBridgeMetadata> */
    private array $metadata = [];

    /**
     * @param iterable<object> $bridgedTools
     */
    public function __construct(
        #[AutowireIterator('tool_policy.bridge')]
        iterable $bridgedTools,
    ) {
        foreach ($bridgedTools as $tool) {
            $entry = $this->buildMetadata($tool);
            if (null === $entry) {
                continue;
            }
            if (isset($this->metadata[$entry->name])) {
                throw new \LogicException(sprintf('Duplicate tool bridge name "%s".', $entry->name));
            }
            $this->metadata[$entry->name] = $entry;
        }
    }

    /**
     * @return list<ToolBridgeMetadata>
     */
    public function all(): array
    {
        return array_values($this->metadata);
    }

    public function has(string $name): bool
    {
        return isset($this->metadata[$name]);
    }

    public function get(string $name): ?ToolBridgeMetadata
    {
        return $this->metadata[$name] ?? null;
    }

    private function buildMetadata(object $tool): ?ToolBridgeMetadata
    {
        $reflection = new \ReflectionClass($tool);

        $bridgeAttributes = $reflection->getAttributes(ToolBridge::class);
        if ([] === $bridgeAttributes) {
            return null;
        }
        $bridge = $bridgeAttributes[0]->newInstance();

        $toolAttributes = $reflection->getAttributes(AsTool::class);
        if ([] === $toolAttributes) {
            throw new \LogicException(sprintf(
                'Service "%s" is tagged tool_policy.bridge via #[ToolBridge] but is missing #[AsTool].',
                $reflection->getName(),
            ));
        }

        $name = $toolAttributes[0]->newInstance()->name;

        return new ToolBridgeMetadata(
            name: $name,
            enabled: $bridge->enabled,
            tags: $bridge->tags,
            requiresApproval: $bridge->requiresApproval,
        );
    }
}
