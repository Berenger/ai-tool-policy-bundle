<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Command;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use ToolPolicy\Bundle\Registry\ToolBridgeRegistry;
use ToolPolicy\Bundle\Toolbox\FilteredToolbox;

/**
 * Lists, for each filtered toolbox, every tool discovered via #[AsTool]
 * together with its #[ToolBridge] policy (enabled / tags / approval) and
 * whether it is actually exposed to the model by FilteredToolbox. Ends with
 * the #[ToolBridge] tools exposed by no toolbox at all, and fails on any
 * #[ToolBridge(name:)] alias that matches no tool of its class (typo, stale rename).
 */
#[AsCommand(
    name: 'debug:llm-tools',
    description: 'List the exposed LLM tools and their configuration #[ToolBridge]',
)]
final class DebugLlmToolsCommand extends Command
{
    /**
     * @param iterable<string, FilteredToolbox> $toolboxes Filters, indexed by the id of the toolbox they decorate
     */
    public function __construct(
        private readonly ToolBridgeRegistry $registry,
        private readonly iterable $toolboxes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tag', null, InputOption::VALUE_OPTIONAL, 'Filter the list by ToolBridge tag')
            ->addOption('exposed', null, InputOption::VALUE_NONE, 'List only tools exposed to the model');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $toolboxes = iterator_to_array($this->toolboxes);

        // Every filter shares the same configuration.
        $allowedTags = (array_values($toolboxes)[0] ?? null)?->getAllowedTags() ?? [];
        $io->section('Configuration FilteredToolbox');
        $io->text([] === $allowedTags
            ? 'Aucun tag autorisé (pas de filtrage par tag).'
            : 'Tags autorisés : ' . implode(', ', $allowedTags));

        $tagFilter = $input->getOption('tag');
        $onlyExposed = (bool) $input->getOption('exposed');
        $verbose = $io->isVerbose();
        $keep = static fn (array $tags, bool $isExposed): bool
            => (null === $tagFilter || \in_array($tagFilter, $tags, true)) && (!$onlyExposed || $isExposed);

        $exposedAnywhere = [];
        $knownAnywhere = [];
        foreach ($toolboxes as $toolboxId => $toolbox) {
            $exposed = [];
            foreach ($toolbox->getTools() as $tool) {
                $exposed[$tool->getName()] = true;
            }
            $exposedAnywhere += $exposed;

            /** @var list<Tool> $tools */
            $tools = $toolbox->getInner()->getTools();
            usort($tools, static fn (Tool $a, Tool $b): int => $a->getName() <=> $b->getName());

            $rows = [];
            foreach ($tools as $tool) {
                $metadata = $this->registry->get($tool->getName());
                // Same rule as FilteredToolbox: a policy only applies to the class that declares it.
                if (null !== $metadata && $metadata->className !== $tool->getReference()->getClass()) {
                    $metadata = null;
                }
                if (null !== $metadata) {
                    $knownAnywhere[$tool->getName()] = true;
                }
                $tags = null === $metadata ? [] : $metadata->tags;
                $isExposed = isset($exposed[$tool->getName()]);

                if (!$keep($tags, $isExposed)) {
                    continue;
                }

                $rows[] = [
                    $tool->getName(),
                    $tool->getReference()->getClass(),
                    null === $metadata ? '-' : ($metadata->enabled ? 'yes' : 'no'),
                    [] === $tags ? '-' : implode(', ', $tags),
                    null === $metadata ? '-' : ($metadata->requiresApproval ? 'yes' : 'no'),
                    $isExposed ? 'yes' : 'no',
                ];

                if ($verbose) {
                    $rows[] = ['', $this->describeVerbose($tool), '', '', '', ''];
                }
            }

            $io->section('Toolbox ' . $toolboxId);
            if ([] === $rows) {
                $io->warning('Aucun tool ne correspond aux filtres.');
            } else {
                $io->table(['Name', 'Class', 'Enabled', 'Tags', 'Approval', 'Exposed'], $rows);
            }
        }

        $rows = [];
        $orphans = [];
        foreach ($this->registry->all() as $metadata) {
            if (!isset($knownAnywhere[$metadata->name]) && $this->isAlias($metadata->name, $metadata->className)) {
                $orphans[] = \sprintf('%s (%s)', $metadata->name, $metadata->className);
                continue;
            }
            if (isset($exposedAnywhere[$metadata->name]) || !$keep($metadata->tags, false)) {
                continue;
            }
            $rows[$metadata->name] = [
                $metadata->name,
                $metadata->enabled ? 'yes' : 'no',
                [] === $metadata->tags ? '-' : implode(', ', $metadata->tags),
                $metadata->requiresApproval ? 'yes' : 'no',
            ];
        }
        ksort($rows);

        $io->section('Outils déclarés mais jamais exposés');
        if ([] === $rows) {
            $io->text('Aucun.');
        } else {
            $io->table(['Name', 'Enabled', 'Tags', 'Approval'], array_values($rows));
        }

        if ([] !== $orphans) {
            sort($orphans);
            $io->error(\sprintf(
                "#[ToolBridge(name:)] sans outil correspondant dans aucun toolbox\n"
                    . "(nom mal orthographié, renommage `ai:` modifié ?) :\n%s",
                implode("\n", $orphans),
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param class-string $className
     */
    private function isAlias(string $name, string $className): bool
    {
        foreach ((new \ReflectionClass($className))->getAttributes(AsTool::class) as $attribute) {
            if ($attribute->newInstance()->name === $name) {
                return false;
            }
        }

        return true;
    }

    private function describeVerbose(Tool $tool): string
    {
        $lines = ['  ' . $tool->getDescription()];

        $parameters = $tool->getParameters();
        if (null !== $parameters && isset($parameters['properties']) && \is_array($parameters['properties'])) {
            $names = array_keys($parameters['properties']);
            $required = $parameters['required'] ?? [];
            $parts = array_map(
                static fn (string $name): string => \in_array($name, $required, true) ? $name . '*' : $name,
                $names,
            );
            if ([] !== $parts) {
                $lines[] = '  Params: ' . implode(', ', $parts);
            }
        }

        return implode("\n", $lines);
    }
}
