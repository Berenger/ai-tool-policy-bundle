<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\EventListener;

use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use ToolPolicy\Bundle\Approval\ApprovalGateInterface;
use ToolPolicy\Bundle\Registry\ToolBridgeRegistry;

/**
 * Denies tool calls flagged requiresApproval via #[ToolBridge] when the
 * configured approval gate refuses them. Relies on Symfony AI's
 * ToolCallRequested::deny() so the native toolbox short-circuits execution.
 */
final class ToolApprovalListener
{
    public function __construct(
        private readonly ToolBridgeRegistry $registry,
        private readonly ApprovalGateInterface $gate,
    ) {
    }

    #[AsEventListener(event: ToolCallRequested::class)]
    public function onToolCallRequested(ToolCallRequested $event): void
    {
        $metadata = $this->registry->get($event->getToolCall()->getName());
        if (null === $metadata || !$metadata->requiresApproval) {
            return;
        }

        if (!$this->gate->approve($event->getToolCall())) {
            $event->deny(sprintf('Tool "%s" execution was not approved.', $event->getToolCall()->getName()));
        }
    }
}
