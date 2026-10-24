# AI Tool Policy Bundle

[![Latest version](https://img.shields.io/packagist/v/berenger/ai-tool-policy-bundle.svg)](https://packagist.org/packages/berenger/ai-tool-policy-bundle)

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

## Versions

The bundle follows the `symfony/ai` minor it supports: the minor number is the
same on both sides, so the installed pair is readable at a glance.

| Bundle   | `symfony/ai-agent` |
|----------|--------------------|
| `0.15.x` | `^0.15`            |

The `0.10` line was withdrawn: that release predates the fix that actually
wires the filter, so it exposed every tool, including the ones marked
`enabled: false`. It is not installable anymore.

A release only happens when there is something to ship. A patch fixes the
bundle; the minor changes when `symfony/ai` breaks and this adapter follows.

When a new `symfony/ai` minor is published: widen the constraint, run the test
suite and the sandbox project against it, then tag `<minor>.0`.

## Configuration

```yaml
# config/packages/tool_policy.yaml
tool_policy:
    toolboxes:                            # ai.toolbox.<agent name>, the toolboxes to decorate
        - 'ai.toolbox.assistant'
    allowed_tags: []                      # empty = no tag filtering
```

`symfony/ai-bundle` creates one toolbox service per agent, named
`ai.toolbox.<agent name>` (here the agent declared under `ai.agent.assistant`).
There is no `ai.toolbox` service: that name is only a tag. A toolbox that is
not listed in `toolboxes` is not filtered: its agent sees every tool, including
the ones marked `enabled: false`.

The filter decorates each toolbox with the default priority (0). A decorator of
your own on the same toolbox with a negative priority wraps the filter: tools it
adds are not filtered. Use a positive priority to stay under the filter.

Example with two agents and a filter:

```yaml
# config/packages/ai.yaml
ai:
    agent:
        assistant:
            platform: 'ai.platform.openai'
            model: 'gpt-4o-mini'
            tools: true
        support:
            platform: 'ai.platform.openai'
            model: 'gpt-4o-mini'
            tools: true

# config/packages/tool_policy.yaml
tool_policy:
    toolboxes:
        - 'ai.toolbox.assistant'
        - 'ai.toolbox.support'
    allowed_tags: ['filesystem', 'http']
```

The same policy (`allowed_tags`) applies to every listed toolbox. Listing the
same toolbox twice, or configuring no toolbox at all, fails at container
compilation.

`allowed_tags` is not merged across configuration files: a later declaration
(for example under `when@prod`) replaces the previous list instead of adding to
it.

`toolbox_inner: 'ai.toolbox.assistant'` (single toolbox) is still accepted for
backward compatibility. Setting both `toolboxes` and `toolbox_inner` fails at
container compilation: use `toolboxes`.

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
| `name` | `null` | Policy key, defaults to the `#[AsTool]` name (see below) |

The policy is read from the PHP attribute; the `tool_policy.bridge` tag only
serves to collect the service. Tagging a service by hand with
`tool_policy.bridge` and attributes (`requiresApproval`, ...) has no effect.

Without `#[ToolBridge]`, the tool stays visible as-is when `allowed_tags` is
empty, and is hidden as soon as `allowed_tags` is set.

A hidden tool is not executable either: calling it by name returns
`Tool "<name>" is not available.` instead of running it.

`#[AsTool]` is repeatable. A single `#[ToolBridge]` applies to every `#[AsTool]`
of the class; otherwise declare exactly one `#[ToolBridge]` per `#[AsTool]`,
paired by declaration order. Any other combination throws when the policy
registry is first built (first use of an agent toolbox or of `debug:llm-tools`),
not at container compilation: run `debug:llm-tools` in CI to catch it early.

### Renamed tools

An application can rename a tool in its `ai:` configuration. The toolbox then
exposes it under the new name, and the policy keyed on the `#[AsTool]` name no
longer matches: the tool is treated as if it had no `#[ToolBridge]` (no filter,
no approval). Align the `#[ToolBridge]` name with the exposed name:

```yaml
# config/packages/ai.yaml
ai:
    agent:
        assistant:
            tools:
                services:
                    - service: 'App\Tool\ReadFileTool'
                      name: 'read_file_v2'          # name and description are both
                      description: 'Read a file'    # required for the rename to apply
```

```php
#[AsTool(name: 'read_file', description: 'Read a file')]
#[ToolBridge(name: 'read_file_v2', tags: ['filesystem'], requiresApproval: true)]
final class ReadFileTool
{
    // ...
}
```

The policy is then registered under both `read_file` and `read_file_v2`: the
rename is per agent, so another agent may still expose the tool as `read_file`.
A policy only applies to a tool of the class that declares it: a `name` equal to
the name of a tool from another class does not hand the policy over to it (that
tool is treated as having no `#[ToolBridge]`). `debug:llm-tools` fails on a
`name` that matches no tool of its class.

An empty `name` throws in the same way as above (first use, not compilation),
and so does a name already used by another `#[ToolBridge]` tool. A single
`#[ToolBridge]` shared by several `#[AsTool]` cannot set `name`.

## Approval

If `requiresApproval: true`, the configured `ApprovalGateInterface` service is
asked before running the tool.

The bundle ships a `DenyAllGate` and aliases the interface to it, so an
application always boots: a gated tool is then simply denied
(`Tool "<name>" execution was not approved.`) instead of the container failing
to compile. Provide your own implementation, for example an interactive CLI
prompt, to decide case by case.

```php
// config/services.yaml
ToolPolicy\Bundle\Approval\ApprovalGateInterface:
    alias: App\Agent\MyApprovalGate
```

## Debug

```bash
php bin/console debug:llm-tools
php bin/console debug:llm-tools --exposed
php bin/console debug:llm-tools --tag=filesystem
```

Shows, for each filtered toolbox, enabled, tags, approval, and whether the tool
is exposed to the model, then the `#[ToolBridge]` tools exposed by no toolbox.
`--tag` and `--exposed` apply to every section. Exits with an error when a
`#[ToolBridge(name:)]` alias matches no tool of its class in any toolbox.

The decorators are registered as `tool_policy.filtered_toolbox`,
`tool_policy.filtered_toolbox.1`, ... (one per entry of `toolboxes`, in order);
`ToolPolicy\Bundle\Toolbox\FilteredToolbox` is an alias to the first one.