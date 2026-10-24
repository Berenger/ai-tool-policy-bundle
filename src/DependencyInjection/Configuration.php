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
                    => [] === $config['toolboxes'] && null === $config['toolbox_inner'] && !$config['auto'])
                ->thenInvalid('No toolbox to filter: list at least one service id in `tool_policy.toolboxes`, '
                    . 'or set `tool_policy.auto: true`.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool
                    => [] !== $config['toolboxes'] && null !== $config['toolbox_inner'])
                ->thenInvalid('`toolboxes` and `toolbox_inner` are both set: use `toolboxes`, '
                    . '`toolbox_inner` is only kept for backward compatibility.')
            ->end()
            ->children()
                ->arrayNode('allowed_tags')
                    ->info('Tags a tool must expose to be visible. Empty = no tag filtering.')
                    // A whitelist: a later file (when@prod...) replaces it instead of appending to it.
                    ->performNoDeepMerging()
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
                            throw new \InvalidArgumentException(\sprintf(
                                'Service id listed more than once in `tool_policy.toolboxes`: "%s". '
                                    . 'List each toolbox once.',
                                implode('", "', array_unique(array_diff_assoc($ids, array_unique($ids)))),
                            ));
                        })
                    ->end()
                ->end()
                ->booleanNode('auto')
                    ->info('Also decorate the ai.toolbox services not listed in "toolboxes".')
                    ->defaultFalse()
                ->end()
                ->scalarNode('toolbox_inner')
                    ->info('Legacy single toolbox to decorate, kept for backward compatibility. Not with "toolboxes".')
                    ->defaultNull()
                    ->cannotBeEmpty()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
