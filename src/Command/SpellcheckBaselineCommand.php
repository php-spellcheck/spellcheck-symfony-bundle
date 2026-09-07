<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Command;

use PHPSpellcheck\Core\Baseline\Baseline;
use PHPSpellcheck\Core\Baseline\BaselineStorage;
use PHPSpellcheck\Core\Checker\ExitCodeCalculator;
use PHPSpellcheck\Core\Checker\SpellcheckRunner;
use PHPSpellcheck\Core\Diagnostics\DiagnosticCollector;
use PHPSpellcheck\Core\Exception\SpellcheckException;
use PHPSpellcheck\Core\Report\ReporterRegistry;
use PHPSpellcheck\Core\Source\SourceInterface;
use PHPSpellcheck\SpellcheckBundle\Checker\RunConfigurationFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'spellcheck:baseline',
    description: 'Records the current issues in the baseline file so that only regressions fail the build',
)]
final class SpellcheckBaselineCommand extends AbstractSpellcheckCommand
{
    public function __construct(
        SpellcheckRunner $runner,
        ReporterRegistry $reporters,
        BaselineStorage $baselineStorage,
        RunConfigurationFactory $configurationFactory,
        DiagnosticCollector $diagnostics,
        string $baselinePath,
        private readonly ?SourceInterface $translations = null,
        private readonly ?SourceInterface $code = null,
    ) {
        parent::__construct($runner, $reporters, $baselineStorage, $configurationFactory, $diagnostics, $baselinePath);
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('merge', null, InputOption::VALUE_NONE, 'Keep the existing entries and add the new ones')
            ->addOption('prune', null, InputOption::VALUE_NONE, 'Only remove the entries that were not reproduced')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing the file')
        ;
    }

    protected function getSources(InputInterface $input): iterable
    {
        if (null !== $this->translations) {
            yield $this->translations;
        }

        if (null !== $this->code) {
            yield $this->code;
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = $this->getBaselinePath();
        $storage = $this->getBaselineStorage();

        $merge = true === $input->getOption('merge');
        $prune = true === $input->getOption('prune');
        $dryRun = true === $input->getOption('dry-run');

        if ($merge && $prune) {
            $io->error('--merge and --prune are mutually exclusive.');

            return ExitCodeCalculator::ENVIRONMENT_ERROR;
        }

        try {
            $configuration = $this->getConfigurationFactory()->create($input);
            $existing = $storage->exists($path) ? $storage->load($path) : Baseline::empty();

            // The run must see every issue, baseline included, otherwise the
            // regenerated file would only contain the new ones.
            $result = $this->getRunner()->run($this->getSources($input), $configuration, null);
        } catch (SpellcheckException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return ExitCodeCalculator::ENVIRONMENT_ERROR;
        }

        $fresh = Baseline::fromMisspellings($result->misspellings);

        if ($prune) {
            foreach (array_keys($fresh->entries()) as $fingerprint) {
                $existing->contains($fingerprint);
            }

            $updated = $existing->withoutOutdated();
        } elseif ($merge) {
            $updated = $existing->merge($fresh);
        } else {
            $updated = $fresh;
        }

        $before = \count($existing->entries());
        $after = \count($updated->entries());

        $io->writeln(sprintf(
            '<info>%s</info> %d entries before, %d after (%+d).',
            $dryRun ? 'Would write' : 'Wrote',
            $before,
            $after,
            $after - $before,
        ));

        foreach ($result->diagnostics as $diagnostic) {
            $io->warning($diagnostic->message);
        }

        if ($dryRun) {
            return ExitCodeCalculator::SUCCESS;
        }

        $storage->save($path, $updated);

        $io->success(sprintf('Baseline written to %s', $path));

        return ExitCodeCalculator::SUCCESS;
    }
}
