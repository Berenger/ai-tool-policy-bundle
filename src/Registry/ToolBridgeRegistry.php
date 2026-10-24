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
 * stays aligned with what Symfony AI actually exposes to the model, unless
 * #[ToolBridge(name: ...)] overrides them for a tool renamed in the `ai:` config.
 *
 * The policy is read from the PHP attribute only: a service tagged
 * tool_policy.bridge without #[ToolBridge] yields no entry.
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
            foreach ($this->buildMetadata($tool) as $entry) {
                if (isset($this->metadata[$entry->name])) {
                    throw new \LogicException(sprintf('Duplicate tool bridge name "%s".', $entry->name));
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

    public function has(string $name): bool
    {
        return isset($this->metadata[$name]);
    }

    public function get(string $name): ?ToolBridgeMetadata
    {
        return $this->metadata[$name] ?? null;
    }

    /**
     * One entry per #[AsTool]. With as many #[ToolBridge] as #[AsTool] they are
     * paired by index; a single #[ToolBridge] applies to every tool of the class.
     *
     * @return list<ToolBridgeMetadata>
     */
    private function buildMetadata(object $tool): array
    {
        $reflection = new \ReflectionClass($tool);

        $bridgeAttributes = $reflection->getAttributes(ToolBridge::class);
        if ([] === $bridgeAttributes) {
            return [];
        }

        $toolAttributes = $reflection->getAttributes(AsTool::class);
        if ([] === $toolAttributes) {
            throw new \LogicException(sprintf(
                'Service "%s" is tagged tool_policy.bridge via #[ToolBridge] but is missing #[AsTool].',
                $reflection->getName(),
            ));
        }

        $bridgeCount = \count($bridgeAttributes);
        $toolCount = \count($toolAttributes);
        if (1 !== $bridgeCount && $bridgeCount !== $toolCount) {
            throw new \LogicException(sprintf(
                'Class "%s" declares %d #[AsTool] and %d #[ToolBridge]: use one #[ToolBridge] for all tools, '
                    . 'or exactly one per #[AsTool] (paired by declaration order).',
                $reflection->getName(),
                $toolCount,
                $bridgeCount,
            ));
        }

        $entries = [];
        foreach ($toolAttributes as $index => $toolAttribute) {
            $bridge = $bridgeAttributes[1 === $bridgeCount ? 0 : $index]->newInstance();
            if (null !== $bridge->name && '' === trim($bridge->name)) {
                throw new \LogicException(sprintf(
                    'Class "%s" declares #[ToolBridge] with an empty name: omit it to use the #[AsTool] name.',
                    $reflection->getName(),
                ));
            }
            $entries[] = new ToolBridgeMetadata(
                name: $bridge->name ?? $toolAttribute->newInstance()->name,
                enabled: $bridge->enabled,
                tags: $bridge->tags,
                requiresApproval: $bridge->requiresApproval,
            );
        }

        return $entries;
    }
}
