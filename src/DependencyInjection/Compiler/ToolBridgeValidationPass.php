<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ToolPolicy\Bundle\Metadata\ToolBridgeReader;

/**
 * Validates the #[ToolBridge] attributes of every tool_policy.bridge service at
 * compilation, instead of at the first use of the registry. Reads the class declared
 * by the definition, before decoration moves the tag to a decorator.
 */
final class ToolBridgeValidationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        /** @var array<string, array{0: string, 1: string}> $declaredBy tool name => [service id, class] */
        $declaredBy = [];
        foreach (array_keys($container->findTaggedServiceIds('tool_policy.bridge')) as $id) {
            $definition = $container->getDefinition($id);
            if ($definition->isAbstract()) {
                continue;
            }
            while (
                null === $definition->getClass() && $definition instanceof ChildDefinition
                && $container->hasDefinition($definition->getParent())
            ) {
                $definition = $container->getDefinition($definition->getParent());
            }
            // No class (synthetic, factory without class) or unknown class: nothing to read here,
            // other passes report an unknown class.
            $class = $container->getParameterBag()->resolveValue($definition->getClass());
            if (!\is_string($class) || null === $reflection = $container->getReflectionClass($class, false)) {
                continue;
            }

            try {
                $entries = ToolBridgeReader::read($reflection);
            } catch (\LogicException $e) {
                throw new InvalidConfigurationException(
                    \sprintf('Invalid #[ToolBridge] on service "%s" (class "%s"): %s', $id, $class, $e->getMessage()),
                    0,
                    $e,
                );
            }

            foreach ($entries as $entry) {
                if (isset($declaredBy[$entry->name])) {
                    throw new InvalidConfigurationException(\sprintf(
                        'Duplicate tool bridge name "%s" (declared by service "%s" of class "%s" and service "%s" '
                            . 'of class "%s"): give each tool a distinct name.',
                        $entry->name,
                        $declaredBy[$entry->name][0],
                        $declaredBy[$entry->name][1],
                        $id,
                        $class,
                    ));
                }
                $declaredBy[$entry->name] = [$id, $class];
            }
        }
    }
}
