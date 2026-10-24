<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Metadata;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use ToolPolicy\Bundle\Attribute\ToolBridge;

/**
 * Reads the #[ToolBridge] policy of a class, paired with its #[AsTool] attributes.
 * Shared by ToolBridgeValidationPass (at compilation) and ToolBridgeRegistry (at runtime).
 */
final class ToolBridgeReader
{
    /**
     * One entry per #[AsTool], plus one per #[ToolBridge(name:)] alias. With as many
     * #[ToolBridge] as #[AsTool] they are paired by index; a single #[ToolBridge]
     * applies to every tool of the class. A class without #[ToolBridge] yields no entry.
     *
     * @param \ReflectionClass<object> $reflection
     *
     * @return list<ToolBridgeMetadata>
     *
     * @throws \LogicException When the attributes cannot be paired
     */
    public static function read(\ReflectionClass $reflection): array
    {
        $bridgeAttributes = $reflection->getAttributes(ToolBridge::class);
        if ([] === $bridgeAttributes) {
            return [];
        }

        $toolAttributes = $reflection->getAttributes(AsTool::class);
        if ([] === $toolAttributes) {
            throw new \LogicException(\sprintf(
                'Class "%s" declares #[ToolBridge] but no #[AsTool]: add #[AsTool], or remove #[ToolBridge].',
                $reflection->getName(),
            ));
        }

        $bridgeCount = \count($bridgeAttributes);
        $toolCount = \count($toolAttributes);
        if (1 !== $bridgeCount && $bridgeCount !== $toolCount) {
            throw new \LogicException(\sprintf(
                'Class "%s" declares %d #[AsTool] and %d #[ToolBridge]: use one #[ToolBridge] for all tools, '
                    . 'or exactly one per #[AsTool] (paired by declaration order).',
                $reflection->getName(),
                $toolCount,
                $bridgeCount,
            ));
        }

        if (1 === $bridgeCount && $toolCount > 1 && null !== $bridgeAttributes[0]->newInstance()->name) {
            throw new \LogicException(\sprintf(
                'Class "%s" declares %d #[AsTool] and a single #[ToolBridge] with a name: '
                    . 'a shared #[ToolBridge] cannot set "name", declare one #[ToolBridge] per #[AsTool].',
                $reflection->getName(),
                $toolCount,
            ));
        }

        $entries = [];
        foreach ($toolAttributes as $index => $toolAttribute) {
            $bridge = $bridgeAttributes[1 === $bridgeCount ? 0 : $index]->newInstance();
            if (null !== $bridge->name && '' === trim($bridge->name)) {
                throw new \LogicException(\sprintf(
                    'Class "%s" declares #[ToolBridge] with an empty name: omit it to use the #[AsTool] name.',
                    $reflection->getName(),
                ));
            }
            $names = [$toolAttribute->newInstance()->name];
            if (null !== $bridge->name && $bridge->name !== $names[0]) {
                $names[] = $bridge->name;
            }
            foreach ($names as $name) {
                $entries[] = new ToolBridgeMetadata(
                    name: $name,
                    enabled: $bridge->enabled,
                    tags: $bridge->tags,
                    requiresApproval: $bridge->requiresApproval,
                    className: $reflection->getName(),
                );
            }
        }

        return $entries;
    }
}
