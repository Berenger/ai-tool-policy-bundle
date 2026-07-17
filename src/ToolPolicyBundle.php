<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use ToolPolicy\Bundle\Attribute\ToolBridge;
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

        $container->registerAttributeForAutoconfiguration(
            ToolBridge::class,
            static function (ChildDefinition $definition, ToolBridge $attribute): void {
                $definition->addTag('tool_policy.bridge', [
                    'enabled' => $attribute->enabled,
                    'tags' => $attribute->tags,
                    'requiresApproval' => $attribute->requiresApproval,
                ]);
            },
        );
    }
}
