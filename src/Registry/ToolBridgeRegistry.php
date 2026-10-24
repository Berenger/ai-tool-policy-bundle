<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Registry;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use ToolPolicy\Bundle\Metadata\ToolBridgeMetadata;
use ToolPolicy\Bundle\Metadata\ToolBridgeReader;

/**
 * Maps tool names to their #[ToolBridge] policy metadata. Tool names are read
 * from the matching #[AsTool] attribute on the same class, so the registry
 * stays aligned with what Symfony AI actually exposes to the model.
 * #[ToolBridge(name: ...)] adds an alias for a tool renamed in the `ai:` config;
 * the #[AsTool] name stays registered, since the rename is per agent.
 *
 * The policy is read from the PHP attribute only: a service tagged
 * tool_policy.bridge without #[ToolBridge] yields no entry. Invalid attributes
 * are rejected at compilation by ToolBridgeValidationPass; the checks here only
 * cover a registry built by hand.
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
            foreach (ToolBridgeReader::read(new \ReflectionClass($tool)) as $entry) {
                if (isset($this->metadata[$entry->name])) {
                    throw new \LogicException(\sprintf(
                        'Duplicate tool bridge name "%s" (declared by "%s" and "%s").',
                        $entry->name,
                        $this->metadata[$entry->name]->className,
                        $entry->className,
                    ));
                }
                $this->metadata[$entry->name] = $entry;
            }
        }
    }

    /**
     * @return list<ToolBridgeMetadata>
     */
    public function all(): array
    {
        return array_values($this->metadata);
    }

    public function get(string $name): ?ToolBridgeMetadata
    {
        return $this->metadata[$name] ?? null;
    }
}
