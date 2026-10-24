<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests\Fixtures;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use ToolPolicy\Bundle\Attribute\ToolBridge;

#[AsTool('hidden_tool', 'Hidden tool')]
#[ToolBridge(enabled: false, tags: ['filesystem'])]
final class HiddenTool
{
    public function __invoke(): string
    {
        return 'hidden ran';
    }
}
