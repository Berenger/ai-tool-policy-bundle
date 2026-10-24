<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use ToolPolicy\Bundle\Command\DebugLlmToolsCommand;
use ToolPolicy\Bundle\Toolbox\FilteredToolbox;

/**
 * With `tool_policy.auto: true`, decorates every ai.toolbox service that the configured
 * `toolboxes` did not already cover, so that an agent added later is filtered too.
 * Runs before the optimization passes: decorations are resolved by DecoratorServicePass.
 */
final class AutoDecorateToolboxesPass implements CompilerPassInterface
{
    /** Registered by ToolPolicyExtension only when `auto` is enabled. */
    public const PROTOTYPE = '.tool_policy.filtered_toolbox.prototype';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::PROTOTYPE)) {
            return;
        }

        // Exact ids, aliases not resolved: decorating an alias leaves the service behind it undecorated,
        // and the agent references the service.
        $decorated = [];
        foreach ($container->getDefinitions() as $id => $definition) {
            if (str_starts_with($id, 'tool_policy.filtered_toolbox') && null !== $definition->getDecoratedService()) {
                $decorated[$definition->getDecoratedService()[0]] = true;
            }
        }

        $index = 0;
        while ($container->hasDefinition($this->decoratorId($index))) {
            ++$index;
        }

        $decorators = [];
        foreach (array_keys($container->findTaggedServiceIds('ai.toolbox')) as $toolboxId) {
            // Never stack a filter on a filter.
            if (
                str_starts_with($toolboxId, 'tool_policy.filtered_toolbox') || isset($decorated[$toolboxId])
                || $container->getDefinition($toolboxId)->isAbstract()
            ) {
                continue;
            }

            $decoratorId = $this->decoratorId($index++);
            $container->setDefinition($decoratorId, clone $container->getDefinition(self::PROTOTYPE))
                ->setAbstract(false)
                ->setDecoratedService($toolboxId)
                ->setArgument('$inner', new Reference($decoratorId . '.inner'));
            $decorators[$toolboxId] = new Reference($decoratorId);
        }

        if ([] === $decorators) {
            return;
        }

        if (!$container->hasAlias(FilteredToolbox::class)) {
            $container->setAlias(FilteredToolbox::class, $this->decoratorId(0));
        }

        $command = $container->getDefinition(DebugLlmToolsCommand::class);
        $toolboxes = $command->getArgument('$toolboxes');
        \assert($toolboxes instanceof IteratorArgument);
        $toolboxes->setValues($toolboxes->getValues() + $decorators);
    }

    /**
     * Same ids as ToolPolicyExtension: tool_policy.filtered_toolbox, then .1, .2...
     */
    private function decoratorId(int $index): string
    {
        return 'tool_policy.filtered_toolbox' . (0 === $index ? '' : '.' . $index);
    }
}
