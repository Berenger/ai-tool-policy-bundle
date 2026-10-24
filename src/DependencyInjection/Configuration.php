<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tool_policy');

        $treeBuilder->getRootNode()
            ->validate()
                ->ifTrue(static fn (array $config): bool
                    => [] === $config['toolboxes'] && null === $config['toolbox_inner'])
                ->thenInvalid('configurez `tool_policy.toolboxes` avec au moins un service id')
            ->end()
            ->children()
                ->arrayNode('allowed_tags')
                    ->info('Tags a tool must expose to be visible. Empty = no tag filtering.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end()
                ->arrayNode('toolboxes')
                    ->info('Service ids of the native Symfony AI toolboxes to decorate (ai.toolbox.<agent name>).')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                    ->validate()
                        ->ifTrue(static fn (array $ids): bool => \count($ids) !== \count(array_unique($ids)))
                        ->then(static function (array $ids): never {
                            throw new \InvalidArgumentException(sprintf(
                                'service id déclaré plusieurs fois dans `tool_policy.toolboxes` : "%s"',
                                implode('", "', array_unique(array_diff_assoc($ids, array_unique($ids)))),
                            ));
                        })
                    ->end()
                ->end()
                ->scalarNode('toolbox_inner')
                    ->info('Legacy single toolbox to decorate, used only when "toolboxes" is empty.')
                    ->defaultNull()
                    ->cannotBeEmpty()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
