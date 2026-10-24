<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use ToolPolicy\Bundle\Approval\DenyAllGate;
use ToolPolicy\Bundle\Command\DebugLlmToolsCommand;
use ToolPolicy\Bundle\EventListener\ToolApprovalListener;
use ToolPolicy\Bundle\Registry\ToolBridgeRegistry;
use ToolPolicy\Bundle\Toolbox\FilteredToolbox;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire()
        ->autoconfigure();

    $services->set(DenyAllGate::class);
    $services->set(FilteredToolbox::class);
    $services->set(ToolBridgeRegistry::class);
    $services->set(ToolApprovalListener::class);
    $services->set(DebugLlmToolsCommand::class);
};
