# AI Tool Policy Bundle

Policy layer on top of the Symfony AI toolbox.

Filters which tools the model can see, and can ask for confirmation before running them.

## Requirements

- PHP >= 8.4
- Symfony 7.4 or 8
- `symfony/ai-agent` ^0.15

## Installation

```bash
composer require berenger/ai-tool-policy-bundle
```

## Configuration

```yaml
# config/packages/tool_policy.yaml
tool_policy:
    toolbox_inner: 'ai.toolbox.assistant' # ai.toolbox.<agent name>, the toolbox to decorate
    allowed_tags: []                      # empty = no tag filtering
```

`symfony/ai-bundle` creates one toolbox service per agent, named
`ai.toolbox.<agent name>` (here the agent declared under `ai.agent.assistant`).
There is no `ai.toolbox` service: that name is only a tag.

Example with a filter:

```yaml
tool_policy:
    toolbox_inner: 'ai.toolbox.assistant'
    allowed_tags: ['filesystem', 'http']
```

## `#[ToolBridge]` attribute

Complements `#[AsTool]`. Name and description stay handled by Symfony AI.

```php
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use ToolPolicy\Bundle\Attribute\ToolBridge;

#[AsTool(name: 'read_file', description: 'Read a file')]
#[ToolBridge(enabled: true, tags: ['filesystem'], requiresApproval: true)]
final class ReadFileTool
{
    // ...
}
```

| Option | Default | Role |
|---|---|---|
| `enabled` | `true` | Hides the tool when `false` |
| `tags` | `[]` | Tags used by the `allowed_tags` filter |
| `requiresApproval` | `false` | Asks for confirmation before execution |

Without `#[ToolBridge]`, the tool stays visible as-is when `allowed_tags` is
empty, and is hidden as soon as `allowed_tags` is set.

A hidden tool is not executable either: calling it by name returns
`Tool "<name>" is not available.` instead of running it.

`#[AsTool]` is repeatable. A single `#[ToolBridge]` applies to every `#[AsTool]`
of the class; otherwise declare exactly one `#[ToolBridge]` per `#[AsTool]`,
paired by declaration order. Any other combination fails at boot.

## Approval

If `requiresApproval: true`, the configured `ApprovalGateInterface` service is
asked before running the tool. The consuming application must provide an
implementation (for example an interactive CLI prompt).

## Debug

```bash
php bin/console debug:llm-tools
php bin/console debug:llm-tools --exposed
php bin/console debug:llm-tools --tag=filesystem
```

Shows enabled, tags, approval, and whether the tool is exposed to the model.