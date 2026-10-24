<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use ToolPolicy\Bundle\Tests\Fixtures\BridgeWithoutAsTool;
use ToolPolicy\Bundle\Tests\Fixtures\GatedAliasTool;
use ToolPolicy\Bundle\Tests\Fixtures\GatedTool;
use ToolPolicy\Bundle\Tests\Fixtures\HiddenTool;
use ToolPolicy\Bundle\ToolPolicyBundle;

final class ToolBridgeValidationPassTest extends TestCase
{
    /**
     * @param array<string, class-string> $tools service id => class, autoconfigured like an application tool
     */
    private function compile(array $tools): void
    {
        $container = new ContainerBuilder();
        (new ToolPolicyBundle())->build($container);
        foreach ($tools as $id => $class) {
            $container->register($id, $class)->setAutoconfigured(true)->setPublic(true);
        }
        $container->compile();
    }

    public function testValidToolsCompile(): void
    {
        $this->compile(['app.gated' => GatedTool::class, 'app.hidden' => HiddenTool::class]);

        $this->addToAssertionCount(1);
    }

    public function testBridgeWithoutAsToolFailsAtCompilation(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'service "app.orphan" (class "' . BridgeWithoutAsTool::class . '"): Class "'
            . BridgeWithoutAsTool::class . '" declares #[ToolBridge] but no #[AsTool]',
        );

        $this->compile(['app.orphan' => BridgeWithoutAsTool::class]);
    }

    public function testDuplicateFinalNameAcrossServicesFailsAtCompilation(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage(
            'Duplicate tool bridge name "gated" (declared by service "app.gated" of class "' . GatedTool::class
            . '" and service "app.gated_v2" of class "' . GatedAliasTool::class . '")',
        );

        $this->compile(['app.gated' => GatedTool::class, 'app.gated_v2' => GatedAliasTool::class]);
    }
}
