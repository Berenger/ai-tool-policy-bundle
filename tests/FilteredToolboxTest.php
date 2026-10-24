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
    /** @var array<string, object> */
    private array $bridged;

    protected function setUp(): void
    {
        $this->bridged = [
            'visible' => new #[AsTool('visible', 'Visible')] #[ToolBridge(tags: ['fs'])] class {
            },
            'hidden' => new #[AsTool('hidden', 'Hidden')] #[ToolBridge(enabled: false, tags: ['fs'])] class {
            },
            'net' => new #[AsTool('net', 'Net')] #[ToolBridge(tags: ['net'])] class {
            },
        ];
    }

    /**
     * Inner toolbox double whose tool list can change between calls, counting execute() calls.
     *
     * @param list<Tool>|\ArrayObject<int, Tool> $tools
     */
    private function inner(array|\ArrayObject $tools, int &$calls = 0): ToolboxInterface
    {
        return new class ($tools, $calls) implements ToolboxInterface {
            /**
             * @param list<Tool>|\ArrayObject<int, Tool> $tools
             */
            public function __construct(private array|\ArrayObject $tools, private int &$calls)
            {
            }

            public function getTools(): array
            {
                return [...$this->tools];
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                ++$this->calls;

                return new ToolResult($toolCall, 'executed');
            }
        };
    }

    /**
     * 'visible', 'hidden' (enabled: false), 'net' (tag net), each from its #[ToolBridge] class,
     * and 'plain' from a class without #[ToolBridge].
     *
     * @return list<Tool>
     */
    private function tools(): array
    {
        $tools = [];
        foreach ($this->bridged as $name => $object) {
            $tools[] = $this->tool($name, $object::class);
        }
        $tools[] = $this->tool('plain', \stdClass::class);

        return $tools;
    }

    private function tool(string $name, string $class): Tool
    {
        return new Tool(new ExecutionReference($class), $name, $name);
    }

    private function registry(): ToolBridgeRegistry
    {
        return new ToolBridgeRegistry(array_values($this->bridged));
    }

    /**
     * @return list<string>
     */
    private function names(FilteredToolbox $toolbox): array
    {
        return array_values(array_map(static fn (Tool $tool): string => $tool->getName(), $toolbox->getTools()));
    }

    private function call(FilteredToolbox $toolbox, string $name): mixed
    {
        return $toolbox->execute(new ToolCall('call-1', $name, []))->getResult();
    }

    public function testHiddenToolIsNeverExecuted(): void
    {
        $calls = 0;
        $toolbox = new FilteredToolbox($this->inner($this->tools(), $calls), $this->registry());

        $result = $toolbox->execute(new ToolCall('call-1', 'hidden', []));

        self::assertSame('Tool "hidden" is not available.', $result->getResult());
        self::assertSame(0, $calls);
    }

    public function testUnknownToolIsNeverExecuted(): void
    {
        $calls = 0;
        $toolbox = new FilteredToolbox($this->inner($this->tools(), $calls), $this->registry());

        $result = $toolbox->execute(new ToolCall('call-1', 'nope', []));

        self::assertSame('Tool "nope" is not available.', $result->getResult());
        self::assertSame(0, $calls);
    }

    public function testExposedToolIsDelegated(): void
    {
        $calls = 0;
        $toolbox = new FilteredToolbox($this->inner($this->tools(), $calls), $this->registry());

        $result = $toolbox->execute(new ToolCall('call-1', 'visible', []));

        self::assertSame('executed', $result->getResult());
        self::assertSame(1, $calls);
    }

    public function testToolWithoutBridgeIsVisibleWithoutTagFilter(): void
    {
        $toolbox = new FilteredToolbox($this->inner($this->tools()), $this->registry());

        self::assertSame(['visible', 'net', 'plain'], $this->names($toolbox));
    }

    public function testTagFilterHidesAndRefusesToolsWithoutAMatchingTag(): void
    {
        $calls = 0;
        $toolbox = new FilteredToolbox($this->inner($this->tools(), $calls), $this->registry(), ['fs']);

        // 'net' is bridged but tagged net; 'plain' has no #[ToolBridge], so no tag to match.
        self::assertSame(['visible'], $this->names($toolbox));
        self::assertSame('Tool "net" is not available.', $this->call($toolbox, 'net'));
        self::assertSame('Tool "plain" is not available.', $this->call($toolbox, 'plain'));
        self::assertSame(0, $calls);
    }

    public function testToolAddedToTheInnerToolboxLaterIsExecutable(): void
    {
        $calls = 0;
        // Only a hidden tool at first: nothing exposed, and the empty list gets cached.
        $tools = new \ArrayObject([$this->tool('hidden', $this->bridged['hidden']::class)]);
        $toolbox = new FilteredToolbox($this->inner($tools, $calls), $this->registry());
        self::assertSame([], $toolbox->getTools());

        $tools[] = $this->tool('late', \stdClass::class);

        self::assertSame('executed', $this->call($toolbox, 'late'));
        self::assertSame(1, $calls);
    }

    public function testAliasDoesNotHandThePolicyToAToolOfAnotherClass(): void
    {
        $readFile = new #[AsTool('read_file', 'Read')] #[ToolBridge(name: 'shell_exec', tags: ['fs'])] class {
        };
        $calls = 0;
        // shell_exec comes from a class without #[ToolBridge]: the read_file alias must not cover it.
        $inner = $this->inner([$this->tool('shell_exec', \stdClass::class)], $calls);
        $toolbox = new FilteredToolbox($inner, new ToolBridgeRegistry([$readFile]), ['fs']);

        self::assertSame([], $this->names($toolbox));
        self::assertSame('Tool "shell_exec" is not available.', $this->call($toolbox, 'shell_exec'));
        self::assertSame(0, $calls);
    }

    public function testRenamedToolKeepsItsPolicyUnderBothNames(): void
    {
        $tool = new #[AsTool('native', 'Native')] #[ToolBridge(name: 'renamed', enabled: false)] class {
        };
        // One agent renames the tool, another exposes it under its #[AsTool] name.
        $inner = $this->inner([$this->tool('native', $tool::class), $this->tool('renamed', $tool::class)]);

        self::assertSame([], $this->names(new FilteredToolbox($inner, new ToolBridgeRegistry([$tool]))));
    }
}
