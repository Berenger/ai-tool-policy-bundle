<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests;

use PHPUnit\Framework\TestCase;
use ToolPolicy\Bundle\Attribute\ToolBridge;

final class ToolBridgeAttributeTest extends TestCase
{
    public function testDefaults(): void
    {
        $bridge = new ToolBridge();

        self::assertTrue($bridge->enabled);
        self::assertSame([], $bridge->tags);
        self::assertFalse($bridge->requiresApproval);
    }

    public function testCustomValues(): void
    {
        $bridge = new ToolBridge(enabled: false, tags: ['filesystem'], requiresApproval: true);

        self::assertFalse($bridge->enabled);
        self::assertSame(['filesystem'], $bridge->tags);
        self::assertTrue($bridge->requiresApproval);
    }
}
