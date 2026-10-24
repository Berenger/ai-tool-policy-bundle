<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\EventDispatcher\DependencyInjection\RegisterListenersPass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use ToolPolicy\Bundle\Approval\ApprovalGateInterface;
use ToolPolicy\Bundle\Command\DebugLlmToolsCommand;
use ToolPolicy\Bundle\DependencyInjection\ToolPolicyExtension;
use ToolPolicy\Bundle\Registry\ToolBridgeRegistry;
use ToolPolicy\Bundle\Tests\Fixtures\GatedTool;
use ToolPolicy\Bundle\Tests\Fixtures\HiddenTool;
use ToolPolicy\Bundle\Toolbox\FilteredToolbox;
use ToolPolicy\Bundle\ToolPolicyBundle;

final class ToolPolicyExtensionTest extends TestCase
{
    /**
     * An application container: the bundle is registered like the kernel does (build() then the
     * extension, merged by MergeExtensionConfigurationPass), with an event dispatcher wired like
     * FrameworkBundle does, tb.a exposing GatedTool and HiddenTool, and tb.b empty.
     */
    private function container(bool $withApplicationGate = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new ToolPolicyExtension());
        (new ToolPolicyBundle())->build($container);

        // Same autoconfiguration as FrameworkBundle for #[AsEventListener].
        $container->registerAttributeForAutoconfiguration(
            AsEventListener::class,
            static function (ChildDefinition $definition, AsEventListener $attribute, \Reflector $reflector): void {
                $tagAttributes = get_object_vars($attribute);
                if ($reflector instanceof \ReflectionMethod) {
                    $tagAttributes['method'] = $reflector->getName();
                }
                $definition->addTag('kernel.event_listener', $tagAttributes);
            },
        );
        $container->addCompilerPass(new RegisterListenersPass());
        $container->register('event_dispatcher', EventDispatcher::class)->setPublic(true);

        $container->register(GatedTool::class)->setAutoconfigured(true);
        $container->register(HiddenTool::class)->setAutoconfigured(true);
        $container->register('tb.a', Toolbox::class)->setPublic(true)
            ->setArguments([[new Reference(GatedTool::class), new Reference(HiddenTool::class)]]);
        $container->register('tb.b', Toolbox::class)->setArguments([[]])->setPublic(true);
        if ($withApplicationGate) {
            $container->register(ApprovalGateInterface::class)->setSynthetic(true);
        }

        $container->setAlias('test.command', DebugLlmToolsCommand::class)->setPublic(true);
        $container->setAlias('test.registry', ToolBridgeRegistry::class)->setPublic(true);

        return $container;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function compiled(array $config, ?ContainerBuilder $container = null): ContainerBuilder
    {
        $container ??= $this->container();
        $container->loadFromExtension('tool_policy', $config);
        $container->compile();

        return $container;
    }

    private function gate(bool $decision): ApprovalGateInterface
    {
        return new class ($decision) implements ApprovalGateInterface {
            public function __construct(private readonly bool $decision)
            {
            }

            public function approve(ToolCall $toolCall): bool
            {
                return $this->decision;
            }
        };
    }

    public function testEveryToolboxIsDecorated(): void
    {
        $container = $this->compiled(['toolboxes' => ['tb.a', 'tb.b']]);

        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.a'));
        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.b'));
    }

    public function testLegacyToolboxInnerStillWorks(): void
    {
        $container = $this->compiled(['toolbox_inner' => 'tb.a']);

        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.a'));
        self::assertInstanceOf(Toolbox::class, $container->get('tb.b'));
    }

    public function testToolboxesAndToolboxInnerTogetherAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('`toolboxes` and `toolbox_inner` are both set: use `toolboxes`');

        $this->compiled(['toolboxes' => ['tb.a'], 'toolbox_inner' => 'tb.b']);
    }

    public function testApplicationServicesCannotReplaceADecoratorById(): void
    {
        $container = $this->container();
        // Ids an application could pick: the class name (former first decorator) and its ".1" variant.
        $container->register(FilteredToolbox::class, \ArrayObject::class);
        $container->register(FilteredToolbox::class . '.1', \ArrayObject::class);

        $this->compiled(['toolboxes' => ['tb.a', 'tb.b']], $container);

        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.a'));
        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.b'));
    }

    public function testAllowedTagsAreWiredIntoEveryFilter(): void
    {
        $container = $this->compiled(['toolboxes' => ['tb.a', 'tb.b'], 'allowed_tags' => ['shell']]);

        foreach (['tb.a', 'tb.b'] as $id) {
            $toolbox = $container->get($id);
            self::assertInstanceOf(FilteredToolbox::class, $toolbox);
            self::assertSame(['shell'], $toolbox->getAllowedTags(), $id);
        }
    }

    public function testLaterConfigurationFileReplacesAllowedTags(): void
    {
        $container = $this->container();
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/Fixtures/config'));
        $loader->load('tool_policy.php');
        $loader->load('tool_policy_prod.php');
        $container->compile();

        $toolbox = $container->get('tb.a');
        self::assertInstanceOf(FilteredToolbox::class, $toolbox);
        self::assertSame(['filesystem'], $toolbox->getAllowedTags());
    }

    public function testWithoutAnApplicationGateTheDefaultDeniesGatedTools(): void
    {
        // No ApprovalGateInterface service in the application: the container must still compile.
        $container = $this->compiled(['toolboxes' => ['tb.a']], $this->container(false));
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $event = new ToolCallRequested(
            new ToolCall('call-1', 'gated', []),
            new Tool(new ExecutionReference(GatedTool::class), 'gated', 'Gated tool'),
        );
        $dispatcher->dispatch($event);

        self::assertTrue($event->isDenied(), 'the default gate must fail closed');
    }

    public function testApplicationGateOverridesTheDefault(): void
    {
        $container = $this->compiled(['toolboxes' => ['tb.a']]);
        $container->set(ApprovalGateInterface::class, $this->gate(true));
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $event = new ToolCallRequested(
            new ToolCall('call-2', 'gated', []),
            new Tool(new ExecutionReference(GatedTool::class), 'gated', 'Gated tool'),
        );
        $dispatcher->dispatch($event);

        self::assertFalse($event->isDenied(), 'an application gate must replace the default');
    }

    public function testApprovalListenerIsWiredOnTheDispatcher(): void
    {
        $container = $this->compiled(['toolboxes' => ['tb.a']]);
        $container->set(ApprovalGateInterface::class, $this->gate(false));
        $dispatcher = $container->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $event = new ToolCallRequested(
            new ToolCall('call-1', 'gated', []),
            new Tool(new ExecutionReference(GatedTool::class), 'gated', 'Gated tool'),
        );
        $dispatcher->dispatch($event);

        self::assertTrue($event->isDenied());
        self::assertSame('Tool "gated" execution was not approved.', $event->getDenialReason());

        // Same name, another class: GatedTool's policy does not apply to it.
        $other = new ToolCallRequested(
            new ToolCall('call-2', 'gated', []),
            new Tool(new ExecutionReference(HiddenTool::class), 'gated', 'Another tool'),
        );
        $dispatcher->dispatch($other);

        self::assertFalse($other->isDenied());
    }

    public function testDebugCommandShowsExposureAndNeverExposedTools(): void
    {
        $container = $this->compiled(['toolboxes' => ['tb.a']]);
        $command = $container->get('test.command');
        self::assertInstanceOf(DebugLlmToolsCommand::class, $command);

        $tester = new CommandTester($command);
        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $display = $tester->getDisplay();

        // Name, Class, Enabled, Tags, Approval, Exposed.
        self::assertMatchesRegularExpression('/^\s*gated\s+\S+GatedTool\s+yes\s+shell\s+yes\s+yes\s*$/m', $display);
        self::assertMatchesRegularExpression(
            '/^\s*hidden_tool\s+\S+HiddenTool\s+no\s+filesystem\s+no\s+no\s*$/m',
            $display,
        );
        // Name, Enabled, Tags, Approval.
        $neverExposed = substr($display, (int) strpos($display, 'never exposed'));
        self::assertMatchesRegularExpression('/^\s*hidden_tool\s+no\s+filesystem\s+no\s*$/m', $neverExposed);
        self::assertStringNotContainsString('gated', $neverExposed);
    }

    public function testTagAttributesAreNotAPolicy(): void
    {
        $container = $this->container();
        $container->register('manual_tool')->setSynthetic(true)
            ->addTag('tool_policy.bridge', ['requiresApproval' => true, 'tags' => ['shell']]);
        $this->compiled(['toolboxes' => ['tb.a']], $container);

        $container->set('manual_tool', new #[AsTool('manual', 'Manual')] class {
        });
        $registry = $container->get('test.registry');

        self::assertInstanceOf(ToolBridgeRegistry::class, $registry);
        self::assertNull($registry->get('manual'));
    }

    public function testEmptyConfigurationIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('No toolbox to filter: list at least one service id in `tool_policy.toolboxes`');

        $this->compiled([]);
    }

    public function testDuplicateToolboxIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('"tb.a"');

        $this->compiled(['toolboxes' => ['tb.a', 'tb.b', 'tb.a']]);
    }

    public function testAutoDecoratesEveryTaggedToolbox(): void
    {
        $container = $this->container();
        $container->getDefinition('tb.a')->addTag('ai.toolbox');
        $container->getDefinition('tb.b')->addTag('ai.toolbox');
        $this->compiled(['toolboxes' => [], 'auto' => true], $container);

        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.a'));
        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.b'));
        $this->assertDebugCommandLists($container, ['tb.a', 'tb.b']);
    }

    public function testAutoCompletesTheConfiguredToolboxes(): void
    {
        $container = $this->container();
        $container->getDefinition('tb.a')->addTag('ai.toolbox');
        $container->getDefinition('tb.b')->addTag('ai.toolbox');
        $this->compiled(['toolboxes' => ['tb.a'], 'auto' => true], $container);

        $first = $container->get('tb.a');
        self::assertInstanceOf(FilteredToolbox::class, $first);
        // Already listed: decorated once, not a second time by auto.
        self::assertNotInstanceOf(FilteredToolbox::class, $first->getInner());
        self::assertInstanceOf(FilteredToolbox::class, $container->get('tb.b'));
        $this->assertDebugCommandLists($container, ['tb.a', 'tb.b']);
    }

    public function testAutoWithoutAnyTaggedToolboxCompiles(): void
    {
        $container = $this->compiled(['auto' => true]);

        self::assertInstanceOf(Toolbox::class, $container->get('tb.a'));
    }

    public function testWithoutAutoUnlistedToolboxesAreLeftAlone(): void
    {
        $container = $this->container();
        $container->getDefinition('tb.b')->addTag('ai.toolbox');
        $this->compiled(['toolboxes' => ['tb.a']], $container);

        self::assertInstanceOf(Toolbox::class, $container->get('tb.b'));
    }

    /**
     * @param list<string> $toolboxIds
     */
    private function assertDebugCommandLists(ContainerBuilder $container, array $toolboxIds): void
    {
        $command = $container->get('test.command');
        self::assertInstanceOf(DebugLlmToolsCommand::class, $command);
        $tester = new CommandTester($command);
        $tester->execute([]);
        foreach ($toolboxIds as $id) {
            self::assertStringContainsString('Toolbox ' . $id, $tester->getDisplay());
        }
    }
}
