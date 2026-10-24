<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
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

        $container->setParameter('tool_policy.allowed_tags', $config['allowed_tags']);
        $container->setParameter('tool_policy.toolbox_inner', $config['toolbox_inner']);

        // Decorate (not just wrap) the configured toolbox so the agent receives the filtered one.
        $container->getDefinition(FilteredToolbox::class)
            ->setDecoratedService($config['toolbox_inner'])
            ->setArgument('$inner', new Reference(FilteredToolbox::class . '.inner'))
            ->setArgument('$allowedTags', $config['allowed_tags']);

        // The command compares against the undecorated toolbox, otherwise it would compare the decorator with itself.
        $container->getDefinition(DebugLlmToolsCommand::class)
            ->setArgument('$innerToolbox', new Reference(FilteredToolbox::class . '.inner'));
    }

    public function getAlias(): string
    {
        return 'tool_policy';
    }
}
