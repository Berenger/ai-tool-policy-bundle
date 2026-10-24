<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use ToolPolicy\Bundle\Approval\ApprovalGateInterface;
use ToolPolicy\Bundle\Approval\DenyAllGate;
use ToolPolicy\Bundle\Command\DebugLlmToolsCommand;
use ToolPolicy\Bundle\Toolbox\FilteredToolbox;

final class ToolPolicyExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');

        // Fail closed: without an application gate, a tool requiring approval is denied
        // instead of the container crashing at compile time. An application that defines
        // its own ApprovalGateInterface service keeps the upper hand, the alias is a default.
        if (!$container->has(ApprovalGateInterface::class)) {
            $container->setAlias(ApprovalGateInterface::class, DenyAllGate::class);
        }

        // toolbox_inner is the legacy single-toolbox key; Configuration forbids combining it with toolboxes.
        $toolboxes = array_values([] !== $config['toolboxes'] ? $config['toolboxes'] : [$config['toolbox_inner']]);

        // Decorate (not just wrap) each configured toolbox, at the default priority (0): the filter sits
        // under ai-bundle's FaultTolerantToolbox (-1024) and under any decorator of positive priority.
        // A decorator of the application with a negative priority wraps the filter instead: the tools
        // it adds are not filtered.
        // Ids are the bundle's own (tool_policy.filtered_toolbox, .1, .2...) so that no application
        // service can replace a decorator by id collision; the class name is only an alias to the first.
        // The class is no longer derived from the id: set it on the pristine definition, cloned below.
        $base = $container->getDefinition(FilteredToolbox::class)->setClass(FilteredToolbox::class);
        $container->removeDefinition(FilteredToolbox::class);
        $decorators = [];
        foreach ($toolboxes as $index => $toolboxId) {
            $decoratorId = 'tool_policy.filtered_toolbox' . (0 === $index ? '' : '.' . $index);
            $container->setDefinition($decoratorId, clone $base)
                ->setDecoratedService($toolboxId)
                ->setArgument('$inner', new Reference($decoratorId . '.inner'))
                ->setArgument('$allowedTags', $config['allowed_tags']);
            $decorators[$toolboxId] = new Reference($decoratorId);
        }
        $container->setAlias(FilteredToolbox::class, 'tool_policy.filtered_toolbox');

        $container->getDefinition(DebugLlmToolsCommand::class)
            ->setArgument('$toolboxes', new IteratorArgument($decorators));
    }

    public function getAlias(): string
    {
        return 'tool_policy';
    }
}
