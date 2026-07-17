<?php

declare(strict_types=1);

namespace ToolPolicy\Bundle\Command;

use Symfony\AI\Agent\Toolbox\ToolboxInterface;
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
 * Lists every tool discovered via #[AsTool] together with its #[ToolBridge]
 * policy (enabled / tags / approval) and whether it is actually exposed to the
 * model by FilteredToolbox.
 */
#[AsCommand(
    name: 'debug:llm-tools',
    description: 'List the exposed LLM tools and their configuration #[ToolBridge]',
)]
final class DebugLlmToolsCommand extends Command
{
    public function __construct(
        private readonly ToolBridgeRegistry $registry,
        private readonly FilteredToolbox $filteredToolbox,
        private readonly ToolboxInterface $innerToolbox,
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

        $allowedTags = $this->filteredToolbox->getAllowedTags();
        $io->section('Configuration FilteredToolbox');
        $io->text([] === $allowedTags
            ? 'Aucun tag autorisé (pas de filtrage par tag).'
            : 'Tags autorisés : ' . implode(', ', $allowedTags));

        $exposed = [];
        foreach ($this->filteredToolbox->getTools() as $tool) {
            $exposed[$tool->getName()] = true;
        }

        $tagFilter = $input->getOption('tag');
        $onlyExposed = (bool) $input->getOption('exposed');
        $verbose = $io->isVerbose();

        /** @var list<Tool> $tools */
        $tools = $this->innerToolbox->getTools();
        usort($tools, static fn (Tool $a, Tool $b): int => $a->getName() <=> $b->getName());

        $rows = [];
        foreach ($tools as $tool) {
            $metadata = $this->registry->get($tool->getName());
            $tags = null === $metadata ? [] : $metadata->tags;
            $isExposed = isset($exposed[$tool->getName()]);

            if (null !== $tagFilter && !\in_array($tagFilter, $tags, true)) {
                continue;
            }
            if ($onlyExposed && !$isExposed) {
                continue;
            }

            $rows[] = [
                $tool->getName(),
                $tool->getReference()->getClass(),
                null === $metadata ? '—' : ($metadata->enabled ? 'yes' : 'no'),
                [] === $tags ? '—' : implode(', ', $tags),
                null === $metadata ? '—' : ($metadata->requiresApproval ? 'yes' : 'no'),
                $isExposed ? 'yes' : 'no',
            ];

            if ($verbose) {
                $rows[] = ['', $this->describeVerbose($tool), '', '', '', ''];
            }
        }

        if ([] === $rows) {
            $io->warning('Aucun tool ne correspond aux filtres.');

            return Command::SUCCESS;
        }

        $io->table(['Name', 'Class', 'Enabled', 'Tags', 'Approval', 'Exposed'], $rows);

        return Command::SUCCESS;
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
