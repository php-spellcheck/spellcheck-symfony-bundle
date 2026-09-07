<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Command;

use PHPSpellcheck\Core\Baseline\Baseline;
use PHPSpellcheck\Core\Baseline\BaselineStorage;
use PHPSpellcheck\Core\Checker\ExitCodeCalculator;
use PHPSpellcheck\Core\Checker\RunConfiguration;
use PHPSpellcheck\Core\Checker\RunResult;
use PHPSpellcheck\Core\Checker\SpellcheckRunner;
use PHPSpellcheck\Core\Diagnostics\DiagnosticCollector;
use PHPSpellcheck\Core\Exception\BaselineSchemaException;
use PHPSpellcheck\Core\Exception\SpellcheckException;
use PHPSpellcheck\Core\Report\ReporterRegistry;
use PHPSpellcheck\Core\Source\SourceInterface;
use PHPSpellcheck\SpellcheckBundle\Checker\RunConfigurationFactory;
use PHPSpellcheck\SpellcheckBundle\Report\ConsoleWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractSpellcheckCommand extends Command
{
    public function __construct(
        private readonly SpellcheckRunner $runner,
        private readonly ReporterRegistry $reporters,
        private readonly BaselineStorage $baselineStorage,
        private readonly RunConfigurationFactory $configurationFactory,
        private readonly DiagnosticCollector $diagnostics,
        private readonly string $baselinePath,
    ) {
        parent::__construct();
    }

    /**
     * @return iterable<SourceInterface>
     */
    abstract protected function getSources(InputInterface $input): iterable;

    protected function configure(): void
    {
        $this
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Report format: table, json, github, checkstyle, junit, gitlab, csv', 'table')
            ->addOption('no-suggestions', null, InputOption::VALUE_NONE, 'Skip suggestion computation (faster)')
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Ignore the baseline file')
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Bypass the result cache')
            ->addOption('fail-on-warning', null, InputOption::VALUE_NONE, 'Exit with 1 instead of 3 when only warnings are found')
            ->addOption('ignore-warnings', null, InputOption::VALUE_NONE, 'Exit with 0 instead of 3 when only warnings are found')
            ->addOption('report-outdated', null, InputOption::VALUE_NONE, 'Fail if the baseline contains entries that were not reproduced')
            // "profile" collides with FrameworkBundle's global --profile (profiler toggle).
            ->addOption('config-profile', null, InputOption::VALUE_REQUIRED, 'Configuration profile to apply')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $io = new SymfonyStyle($input, $errorOutput);

        try {
            $configuration = $this->configurationFactory->create($input);
            $reporter = $this->reporters->get((string) $input->getOption('format'));
            $baseline = $this->loadBaseline($configuration);
            $result = $this->runner->run($this->getSources($input), $configuration, $baseline);
        } catch (BaselineSchemaException $e) {
            $io->error($e->getMessage());

            return ExitCodeCalculator::ENVIRONMENT_ERROR;
        } catch (SpellcheckException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return ExitCodeCalculator::ENVIRONMENT_ERROR;
        }

        $reporter->report($result, new ConsoleWriter($output));

        return $this->finish($result, $configuration);
    }

    protected function finish(RunResult $result, RunConfiguration $configuration): int
    {
        return ExitCodeCalculator::calculate($result, $configuration);
    }

    protected function loadBaseline(RunConfiguration $configuration): ?Baseline
    {
        if (!$configuration->useBaseline || !$this->baselineStorage->exists($this->baselinePath)) {
            return null;
        }

        return $this->baselineStorage->load($this->baselinePath);
    }

    protected function getBaselinePath(): string
    {
        return $this->baselinePath;
    }

    protected function getBaselineStorage(): BaselineStorage
    {
        return $this->baselineStorage;
    }

    protected function getRunner(): SpellcheckRunner
    {
        return $this->runner;
    }

    protected function getConfigurationFactory(): RunConfigurationFactory
    {
        return $this->configurationFactory;
    }

    protected function getDiagnostics(): DiagnosticCollector
    {
        return $this->diagnostics;
    }

    /**
     * @return list<string>
     */
    protected function stringListOption(InputInterface $input, string $name): array
    {
        if (!$input->hasOption($name)) {
            return [];
        }

        /** @var mixed $value */
        $value = $input->getOption($name);

        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $v): bool => '' !== $v));
    }
}
