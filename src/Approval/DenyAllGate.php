<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Approval;

use Symfony\AI\Platform\Result\ToolCall;

/**
 * Default approval gate: refuses every call to a tool flagged requiresApproval.
 *
 * It exists so an application that never configured approval still boots. The
 * bundle aliases ApprovalGateInterface to this gate, which means a gated tool is
 * denied until the application provides its own implementation: failing closed
 * is the only safe default for a policy layer.
 */
final class DenyAllGate implements ApprovalGateInterface
{
    public function approve(ToolCall $toolCall): bool
    {
        return false;
    }
}
