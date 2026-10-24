<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use ToolPolicy\Bundle\Attribute\ToolBridge;
use ToolPolicy\Bundle\Registry\ToolBridgeRegistry;

final class ToolBridgeRegistryTest extends TestCase
{
    public function testSingleBridgeAppliesToEveryToolOfTheClass(): void
    {
        $tool = new #[AsTool('db_read', 'Read', method: 'read')]
        #[AsTool('db_drop', 'Drop', method: 'drop')]
        #[ToolBridge(enabled: false, tags: ['database'], requiresApproval: true)]
        class {
        };

        $registry = new ToolBridgeRegistry([$tool]);

        foreach (['db_read', 'db_drop'] as $name) {
            $metadata = $registry->get($name);
            self::assertNotNull($metadata, $name);
            self::assertFalse($metadata->enabled, $name);
            self::assertSame(['database'], $metadata->tags, $name);
            self::assertTrue($metadata->requiresApproval, $name);
        }
    }

    public function testBridgesArePairedWithToolsByIndex(): void
    {
        $tool = new #[AsTool('fs_read', 'Read', method: 'read')]
        #[AsTool('fs_write', 'Write', method: 'write')]
        #[ToolBridge(tags: ['fs'])]
        #[ToolBridge(tags: ['fs', 'write'], requiresApproval: true)]
        class {
        };

        $registry = new ToolBridgeRegistry([$tool]);

        $read = $registry->get('fs_read');
        $write = $registry->get('fs_write');
        self::assertNotNull($read);
        self::assertNotNull($write);
        self::assertSame(['fs'], $read->tags);
        self::assertFalse($read->requiresApproval);
        self::assertSame(['fs', 'write'], $write->tags);
        self::assertTrue($write->requiresApproval);
    }

    public function testMismatchedAttributeCountsAreRejected(): void
    {
        $tool = new #[AsTool('a', 'A', method: 'a')]
        #[AsTool('b', 'B', method: 'b')]
        #[ToolBridge]
        #[ToolBridge]
        #[ToolBridge]
        class {
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('2 #[AsTool] and 3 #[ToolBridge]');

        new ToolBridgeRegistry([$tool]);
    }

    public function testDuplicateToolNameIsRejected(): void
    {
        $first = new #[AsTool('same', 'First')] #[ToolBridge] class {
        };
        $second = new #[AsTool('same', 'Second')] #[ToolBridge] class {
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate tool bridge name "same"');

        new ToolBridgeRegistry([$first, $second]);
    }
}
