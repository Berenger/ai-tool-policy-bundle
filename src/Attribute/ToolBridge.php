<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Attribute;

/**
 * Complementary to Symfony AI's #[AsTool]: carries only the policy metadata that
 * the native attribute does not cover (enabled, tags, approval). The tool name
 * and description stay the responsibility of #[AsTool]; `name` only overrides the
 * policy key when the application renames the tool in its `ai:` configuration.
 * This attribute is the single source of truth: the tag added in
 * ToolPolicyBundle::build() only collects the service.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class ToolBridge
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public bool $enabled = true,
        public array $tags = [],
        public bool $requiresApproval = false,
        public ?string $name = null,
    ) {
    }
}
