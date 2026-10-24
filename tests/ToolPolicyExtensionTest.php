<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ToolPolicy\Bundle\Approval\ApprovalGateInterface;
use ToolPolicy\Bundle\Command\DebugLlmToolsCommand;
use ToolPolicy\Bundle\DependencyInjection\ToolPolicyExtension;
use ToolPolicy\Bundle\Registry\ToolBridgeRegistry;
use ToolPolicy\Bundle\Toolbox\FilteredToolbox;

final class ToolPolicyExtensionTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('tb.a', Toolbox::class)->setArguments([[]])->setPublic(true);
        $container->register('tb.b', Toolbox::class)->setArguments([[]])->setPublic(true);
        $container->register(ApprovalGateInterface::class)->setSynthetic(true);

        (new ToolPolicyExtension())->load([$config], $container);

        return $container;
    }

    public function testEveryToolboxIsDecorated(): void
    {
        $container = $this->container(['toolboxes' => ['tb.a', 'tb.b']]);

        $expected = [FilteredToolbox::class => 'tb.a', FilteredToolbox::class . '.1' => 'tb.b'];
        foreach ($expected as $decoratorId => $toolboxId) {
            $definition = $container->getDefinition($decoratorId);
            self::assertSame($toolboxId, $definition->getDecoratedService()[0] ?? null);
            self::assertSame($decoratorId . '.inner', (string) $definition->getArgument('$inner'));
        }
        self::assertSame(['tb.a', 'tb.b'], $container->getParameter('tool_policy.toolboxes'));

        $container->getDefinition(DebugLlmToolsCommand::class)->setPublic(true);
        $container->compile();

        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.a'));
        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.b'));

        $command = $container->get(DebugLlmToolsCommand::class);
        self::assertInstanceOf(DebugLlmToolsCommand::class, $command);
        $tester = new CommandTester($command);
        $tester->execute([]);
        self::assertStringContainsString('Toolbox tb.a', $tester->getDisplay());
        self::assertStringContainsString('Toolbox tb.b', $tester->getDisplay());
    }

    public function testLegacyToolboxInnerStillWorks(): void
    {
        $container = $this->container(['toolbox_inner' => 'tb.a']);

        self::assertSame('tb.a', $container->getDefinition(FilteredToolbox::class)->getDecoratedService()[0] ?? null);
        self::assertFalse($container->hasDefinition(FilteredToolbox::class . '.1'));
        self::assertSame(['tb.a'], $container->getParameter('tool_policy.toolboxes'));

        $container->compile();

        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.a'));
        self::assertInstanceOf(Toolbox::class, $container->get('tb.b'));
    }

    public function testTagAttributesAreNotAPolicy(): void
    {
        $container = $this->container(['toolboxes' => ['tb.a']]);
        $container->register('manual_tool')->setSynthetic(true)
            ->addTag('tool_policy.bridge', ['requiresApproval' => true, 'tags' => ['shell']]);
        $container->getDefinition(ToolBridgeRegistry::class)->setPublic(true);
        $container->compile();

        $container->set('manual_tool', new #[AsTool('manual', 'Manual')] class {
        });
        $registry = $container->get(ToolBridgeRegistry::class);

        self::assertInstanceOf(ToolBridgeRegistry::class, $registry);
        self::assertNull($registry->get('manual'));
        self::assertSame([], $registry->all());
    }

    public function testEmptyConfigurationIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('configurez `tool_policy.toolboxes` avec au moins un service id');

        $this->container([]);
    }

    public function testDuplicateToolboxIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"tb.a"');

        $this->container(['toolboxes' => ['tb.a', 'tb.b', 'tb.a']]);
    }
}
