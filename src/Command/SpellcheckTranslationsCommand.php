<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Command;

use PHPSpellcheck\Core\Baseline\BaselineStorage;
use PHPSpellcheck\Core\Checker\SpellcheckRunner;
use PHPSpellcheck\Core\Diagnostics\DiagnosticCollector;
use PHPSpellcheck\Core\Report\ReporterRegistry;
use PHPSpellcheck\Core\Source\SourceInterface;
use PHPSpellcheck\SpellcheckBundle\Checker\RunConfigurationFactory;
use PHPSpellcheck\SpellcheckBundle\Source\TranslationFilesSource;
use PHPSpellcheck\SpellcheckBundle\Source\TranslatorCatalogueSource;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * #[AsCommand] is available since Symfony 5.3, so it can be used on a 5.4
 * target.
 */
#[AsCommand(
    name: 'spellcheck:translations',
    description: 'Spell checks the translation catalogues, each locale with its own dictionary',
)]
final class SpellcheckTranslationsCommand extends AbstractSpellcheckCommand
{
    public function __construct(
        SpellcheckRunner $runner,
        ReporterRegistry $reporters,
        BaselineStorage $baselineStorage,
        RunConfigurationFactory $configurationFactory,
        DiagnosticCollector $diagnostics,
        string $baselinePath,
        private readonly TranslationFilesSource|TranslatorCatalogueSource $source,
    ) {
        parent::__construct($runner, $reporters, $baselineStorage, $configurationFactory, $diagnostics, $baselinePath);
    }

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('locale', 'l', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict to these locales')
            ->addOption('domain', 'd', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict to these domains')
            ->addOption('force-domain', null, InputOption::VALUE_NONE, 'Allow domains listed in exclude_domains')
        ;
    }

    protected function getSources(InputInterface $input): iterable
    {
        yield $this->source->restrict(
            $this->stringListOption($input, 'locale'),
            $this->stringListOption($input, 'domain'),
            true === $input->getOption('force-domain'),
        );
    }

    /**
     * @return iterable<SourceInterface>
     */
    public function sourcesFor(InputInterface $input): iterable
    {
        return $this->getSources($input);
    }
}
