<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests\Fixtures;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use ToolPolicy\Bundle\Attribute\ToolBridge;

#[AsTool('gated', 'Gated tool')]
#[ToolBridge(tags: ['shell'], requiresApproval: true)]
final class GatedTool
{
    public function __invoke(): string
    {
        return 'gated ran';
    }
}
