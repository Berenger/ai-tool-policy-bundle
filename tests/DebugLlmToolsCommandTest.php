<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolFactory\MemoryToolFactory;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use ToolPolicy\Bundle\Attribute\ToolBridge;
use ToolPolicy\Bundle\Command\DebugLlmToolsCommand;
use ToolPolicy\Bundle\Registry\ToolBridgeRegistry;
use ToolPolicy\Bundle\Toolbox\FilteredToolbox;

final class DebugLlmToolsCommandTest extends TestCase
{
    private function runCommand(object $tool, ?string $exposedAs): CommandTester
    {
        $factory = new MemoryToolFactory();
        $factory->addTool($tool, $exposedAs ?? 'real_name', 'Tool');
        $registry = new ToolBridgeRegistry([$tool]);
        $command = new DebugLlmToolsCommand($registry, [
            'tb.a' => new FilteredToolbox(new Toolbox([$tool], $factory), $registry),
        ]);

        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }

    public function testAliasMatchingNoToolFails(): void
    {
        $tool = new #[AsTool('real_name', 'Tool')] #[ToolBridge(name: 'real_nmae', requiresApproval: true)] class {
            public function __invoke(): void
            {
            }
        };

        $tester = $this->runCommand($tool, null);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('real_nmae', $tester->getDisplay());
    }

    public function testAliasExposedByAToolboxPasses(): void
    {
        $tool = new #[AsTool('real_name', 'Tool')] #[ToolBridge(name: 'renamed', requiresApproval: true)] class {
            public function __invoke(): void
            {
            }
        };

        $tester = $this->runCommand($tool, 'renamed');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        // The renamed tool shows its policy (Approval: yes), not dashes.
        self::assertMatchesRegularExpression('/^\s*renamed\s.*\syes\s+yes\s*$/m', $tester->getDisplay());
    }
}
