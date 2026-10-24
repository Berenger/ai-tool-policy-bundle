<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
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

        // toolbox_inner is the legacy single-toolbox key, only used when toolboxes is empty.
        $toolboxes = array_values([] !== $config['toolboxes'] ? $config['toolboxes'] : [$config['toolbox_inner']]);

        $container->setParameter('tool_policy.allowed_tags', $config['allowed_tags']);
        $container->setParameter('tool_policy.toolbox_inner', $config['toolbox_inner']);
        $container->setParameter('tool_policy.toolboxes', $toolboxes);

        // Decorate (not just wrap) each configured toolbox so every agent receives a filtered one.
        // Default priority keeps the filter under ai-bundle's FaultTolerantToolbox (-1024).
        $base = $container->getDefinition(FilteredToolbox::class);
        $decorators = [];
        foreach ($toolboxes as $index => $toolboxId) {
            $decoratorId = 0 === $index ? FilteredToolbox::class : FilteredToolbox::class . '.' . $index;
            $definition = 0 === $index ? $base : $container->setDefinition($decoratorId, clone $base);
            $definition
                ->setClass(FilteredToolbox::class)
                ->setDecoratedService($toolboxId)
                ->setArgument('$inner', new Reference($decoratorId . '.inner'))
                ->setArgument('$allowedTags', $config['allowed_tags']);
            $decorators[$toolboxId] = new Reference($decoratorId);
        }

        $container->getDefinition(DebugLlmToolsCommand::class)
            ->setArgument('$toolboxes', new IteratorArgument($decorators));
    }

    public function getAlias(): string
    {
        return 'tool_policy';
    }
}
