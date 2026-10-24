<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// when@prod: meant to restrict allowed_tags, not to add to it.
return static function (ContainerConfigurator $container): void {
    $container->extension('tool_policy', [
        'allowed_tags' => ['filesystem'],
    ]);
};
