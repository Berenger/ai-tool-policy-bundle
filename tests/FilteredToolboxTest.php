<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use ToolPolicy\Bundle\Attribute\ToolBridge;
use ToolPolicy\Bundle\Registry\ToolBridgeRegistry;
use ToolPolicy\Bundle\Toolbox\FilteredToolbox;

final class FilteredToolboxTest extends TestCase
{
    /**
     * Inner toolbox double exposing 'visible', 'hidden' (enabled: false) and
     * 'plain' (no #[ToolBridge]), and counting execute() calls.
     */
    private function inner(int &$calls = 0): ToolboxInterface
    {
        return new class ($calls) implements ToolboxInterface {
            public function __construct(private int &$calls)
            {
            }

            public function getTools(): array
            {
                return array_map(
                    static fn (string $name): Tool => new Tool(new ExecutionReference(self::class), $name, $name),
                    ['visible', 'hidden', 'plain'],
                );
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                ++$this->calls;

                return new ToolResult($toolCall, 'executed');
            }
        };
    }

    private function registry(): ToolBridgeRegistry
    {
        return new ToolBridgeRegistry([
            new #[AsTool('visible', 'Visible')] #[ToolBridge(tags: ['fs'])] class {
            },
            new #[AsTool('hidden', 'Hidden')] #[ToolBridge(enabled: false, tags: ['fs'])] class {
            },
        ]);
    }

    /**
     * @return list<string>
     */
    private function names(FilteredToolbox $toolbox): array
    {
        return array_values(array_map(static fn (Tool $tool): string => $tool->getName(), $toolbox->getTools()));
    }

    public function testHiddenToolIsNeverExecuted(): void
    {
        $calls = 0;
        $toolbox = new FilteredToolbox($this->inner($calls), $this->registry());

        $result = $toolbox->execute(new ToolCall('call-1', 'hidden', []));

        self::assertSame('Tool "hidden" is not available.', $result->getResult());
        self::assertSame(0, $calls);
    }

    public function testUnknownToolIsNeverExecuted(): void
    {
        $calls = 0;
        $toolbox = new FilteredToolbox($this->inner($calls), $this->registry());

        $result = $toolbox->execute(new ToolCall('call-1', 'nope', []));

        self::assertSame('Tool "nope" is not available.', $result->getResult());
        self::assertSame(0, $calls);
    }

    public function testExposedToolIsDelegated(): void
    {
        $calls = 0;
        $toolbox = new FilteredToolbox($this->inner($calls), $this->registry());

        $result = $toolbox->execute(new ToolCall('call-1', 'visible', []));

        self::assertSame('executed', $result->getResult());
        self::assertSame(1, $calls);
    }

    public function testToolWithoutBridgeIsVisibleWithoutTagFilter(): void
    {
        self::assertSame(['visible', 'plain'], $this->names(new FilteredToolbox($this->inner(), $this->registry())));
    }

    public function testToolWithoutBridgeIsHiddenByTagFilter(): void
    {
        $toolbox = new FilteredToolbox($this->inner(), $this->registry(), ['fs']);

        self::assertSame(['visible'], $this->names($toolbox));
    }
}
