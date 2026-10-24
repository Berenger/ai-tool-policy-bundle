<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// config/packages/tool_policy.yaml
return static function (ContainerConfigurator $container): void {
    $container->extension('tool_policy', [
        'toolboxes' => ['tb.a'],
        'allowed_tags' => ['filesystem', 'http'],
    ]);
};
