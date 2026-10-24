<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests\Fixtures;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use ToolPolicy\Bundle\Attribute\ToolBridge;

// Its #[ToolBridge] name collides with the #[AsTool] name of GatedTool.
#[AsTool('gated_v2', 'Gated tool, second version')]
#[ToolBridge(name: 'gated')]
final class GatedAliasTool
{
    public function __invoke(): void
    {
    }
}
