<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Command;

use PHPSpellcheck\Core\Baseline\BaselineStorage;
use PHPSpellcheck\Core\Checker\SpellcheckRunner;
use PHPSpellcheck\Core\Diagnostics\DiagnosticCollector;
use PHPSpellcheck\Core\Report\ReporterRegistry;
use PHPSpellcheck\Core\Source\SourceInterface;
use PHPSpellcheck\SpellcheckBundle\Checker\RunConfigurationFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;

#[AsCommand(
    name: 'spellcheck',
    description: 'Spell checks every enabled source: translation catalogues and PHP code',
)]
final class SpellcheckCommand extends AbstractSpellcheckCommand
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

    protected function getSources(InputInterface $input): iterable
    {
        if (null !== $this->translations) {
            yield $this->translations;
        }

        if (null !== $this->code) {
            yield $this->code;
        }
    }
}
