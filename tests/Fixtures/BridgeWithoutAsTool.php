<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests\Fixtures;

use ToolPolicy\Bundle\Attribute\ToolBridge;

#[ToolBridge(tags: ['shell'])]
final class BridgeWithoutAsTool
{
    public function __invoke(): void
    {
    }
}
