<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Approval;

use Symfony\AI\Platform\Result\ToolCall;

/**
 * Decides whether a tool call requiring approval may proceed.
 */
interface ApprovalGateInterface
{
    /**
     * Called for every tool call flagged requiresApproval via #[ToolBridge].
     */
    public function approve(ToolCall $toolCall): bool;
}
