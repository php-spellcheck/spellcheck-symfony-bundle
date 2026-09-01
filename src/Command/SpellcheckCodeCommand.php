<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\Command;

use Acme\Spellcheck\Baseline\BaselineStorage;
use Acme\Spellcheck\Checker\SpellcheckRunner;
use Acme\Spellcheck\Diagnostics\DiagnosticCollector;
use Acme\Spellcheck\Report\ReporterRegistry;
use Acme\SpellcheckBundle\Checker\RunConfigurationFactory;
use Acme\SpellcheckBundle\Factory\PhpSourceFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
    name: 'spellcheck:code',
    description: 'Spell checks PHP identifiers, docblocks and comments',
)]
final class SpellcheckCodeCommand extends AbstractSpellcheckCommand
{
    public function __construct(
        SpellcheckRunner $runner,
        ReporterRegistry $reporters,
        BaselineStorage $baselineStorage,
        RunConfigurationFactory $configurationFactory,
        DiagnosticCollector $diagnostics,
        string $baselinePath,
        private readonly PhpSourceFactory $sourceFactory,
    ) {
        parent::__construct($runner, $reporters, $baselineStorage, $configurationFactory, $diagnostics, $baselinePath);
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY,
                'Files or directories to check; defaults to the configured code.paths',
            )
            ->addOption('kind', 'k', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict to these node categories')
        ;
    }

    protected function getSources(InputInterface $input): iterable
    {
        /** @var list<string> $paths */
        $paths = array_values(array_filter((array) $input->getArgument('paths')));

        yield $this->sourceFactory->create(
            [] === $paths ? null : $paths,
            $this->stringListOption($input, 'kind'),
        );
    }
}
