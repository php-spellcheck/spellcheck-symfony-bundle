<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\Command;

use Acme\Spellcheck\Baseline\BaselineStorage;
use Acme\Spellcheck\Checker\SpellcheckRunner;
use Acme\Spellcheck\Diagnostics\DiagnosticCollector;
use Acme\Spellcheck\Report\ReporterRegistry;
use Acme\Spellcheck\Source\SourceInterface;
use Acme\SpellcheckBundle\Checker\RunConfigurationFactory;
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
