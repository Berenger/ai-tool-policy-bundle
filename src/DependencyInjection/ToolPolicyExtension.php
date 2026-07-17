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

        $innerRef = new Reference($config['toolbox_inner']);

        $container->getDefinition(FilteredToolbox::class)
            ->setArgument('$inner', $innerRef)
            ->setArgument('$allowedTags', $config['allowed_tags']);

        $container->getDefinition(DebugLlmToolsCommand::class)
            ->setArgument('$innerToolbox', $innerRef);
    }

    public function getAlias(): string
    {
        return 'tool_policy';
    }
}
