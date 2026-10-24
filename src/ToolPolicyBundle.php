<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use ToolPolicy\Bundle\Attribute\ToolBridge;
use ToolPolicy\Bundle\DependencyInjection\Compiler\AutoDecorateToolboxesPass;
use ToolPolicy\Bundle\DependencyInjection\Compiler\ToolBridgeValidationPass;
use ToolPolicy\Bundle\DependencyInjection\ToolPolicyExtension;

final class ToolPolicyBundle extends Bundle
{
    public function getContainerExtension(): ToolPolicyExtension
    {
        return new ToolPolicyExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Collection marker only: the policy is read from the #[ToolBridge] attribute
        // (ToolBridgeValidationPass, ToolBridgeRegistry), never from tag attributes.
        $container->registerAttributeForAutoconfiguration(
            ToolBridge::class,
            static function (ChildDefinition $definition): void {
                $definition->addTag('tool_policy.bridge');
            },
        );

        $container->addCompilerPass(new ToolBridgeValidationPass());
        $container->addCompilerPass(new AutoDecorateToolboxesPass());
    }
}
