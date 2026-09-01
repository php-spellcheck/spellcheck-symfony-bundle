<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\Command;

use Acme\Spellcheck\Baseline\BaselineStorage;
use Acme\Spellcheck\Checker\ExitCodeCalculator;
use Acme\Spellcheck\Dictionary\DictionaryInterface;
use Acme\Spellcheck\Dictionary\LocaleDictionaryMap;
use Acme\Spellcheck\Exception\BaselineSchemaException;
use Acme\Spellcheck\Report\ReporterRegistry;
use Acme\Spellcheck\Speller\CachingSpeller;
use Acme\Spellcheck\Speller\ChainSpeller;
use Acme\Spellcheck\Speller\SpellerInterface;
use Acme\Spellcheck\Version;
use Acme\SpellcheckBundle\Locale\LocaleResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnoses the environment: which backends are usable, which locale maps to
 * which dictionary, and what is installed where.
 */
#[AsCommand(
    name: 'spellcheck:doctor',
    description: 'Checks the spell checking environment and reports what is missing',
)]
final class SpellcheckDoctorCommand extends Command
{
    private const INSTALL_HINTS = [
        'hunspell' => 'apt-get install hunspell hunspell-<locale>   (macOS: brew install hunspell)',
        'aspell' => 'apt-get install aspell aspell-<locale>',
        'pspell' => 'install and enable ext-pspell',
    ];

    /**
     * @param list<string> $dictionaryPaths
     */
    public function __construct(
        private readonly SpellerInterface $speller,
        private readonly DictionaryInterface $dictionary,
        private readonly LocaleDictionaryMap $localeMap,
        private readonly ?LocaleResolver $localeResolver,
        private readonly BaselineStorage $baselineStorage,
        private readonly ReporterRegistry $reporters,
        private readonly string $configuredBackend,
        private readonly string $baselinePath,
        private readonly array $dictionaryPaths,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title(sprintf('Spellcheck environment (acme/spellcheck %s)', Version::string()));

        $warnings = 0;
        $errors = 0;

        $inner = $this->speller instanceof CachingSpeller ? $this->speller->getInner() : $this->speller;

        $io->section('Backend');
        $io->definitionList(
            ['configured' => $this->configuredBackend],
            ['resolved' => $inner->getName()],
            ['available' => $inner->isAvailable() ? 'yes' : 'no'],
            ['detail' => $inner->describe()],
        );

        if (!$inner->isAvailable()) {
            ++$errors;
            $io->error(sprintf(
                "The selected backend is not usable.\n%s",
                self::INSTALL_HINTS[$inner->getName()] ?? 'Configure acme_spellcheck.backend: wordlist to run without binaries.',
            ));
        }

        if ($inner instanceof ChainSpeller) {
            $io->text('Chain order and availability: '.$inner->describe());
        }

        $languages = $inner->getSupportedLanguages();
        $io->section('Dictionaries installed on the system');

        if ([] === $languages) {
            $io->text('<comment>The backend cannot enumerate its dictionaries.</comment>');
        } else {
            sort($languages);
            $io->text(implode(', ', $languages));
            $this->localeMap->setAvailable($languages);
        }

        $io->section('Locale resolution');

        if (null === $this->localeResolver) {
            $io->text('<comment>The translator is not available: translation checking is disabled.</comment>');
            ++$warnings;
        } else {
            $rows = [];

            foreach ($this->localeResolver->resolve() as $locale) {
                $resolved = $this->localeMap->resolve($locale);

                if (null === $resolved) {
                    ++$warnings;
                }

                $rows[] = [$locale, $resolved ?? '<comment>(missing)</comment>'];
            }

            if ([] === $rows) {
                $io->text('<comment>No locale could be determined.</comment>');
                ++$warnings;
            } else {
                $io->table(['Locale', 'System dictionary'], $rows);
            }
        }

        $io->section('Project dictionaries');
        $io->definitionList(
            ['files' => [] === $this->dictionaryPaths ? '(none)' : implode(', ', $this->dictionaryPaths)],
            ['words' => (string) $this->dictionary->count()],
            ['version hash' => $this->dictionary->getVersionHash()],
        );

        $io->section('Baseline');

        if (!$this->baselineStorage->exists($this->baselinePath)) {
            $io->text(sprintf('No baseline at %s. Create one with "spellcheck:baseline".', $this->baselinePath));
        } else {
            try {
                $baseline = $this->baselineStorage->load($this->baselinePath);
                $io->text(sprintf('%s (%d entries)', $this->baselinePath, $baseline->count()));
            } catch (BaselineSchemaException $e) {
                ++$errors;
                $io->error($e->getMessage());
            }
        }

        $io->section('Report formats');
        $io->text(implode(', ', $this->reporters->getNames()));

        if ($errors > 0) {
            $io->error(sprintf('%d problem(s) must be fixed before the tool can run.', $errors));

            return ExitCodeCalculator::ENVIRONMENT_ERROR;
        }

        if ($warnings > 0) {
            $io->warning(sprintf('Environment usable with %d warning(s).', $warnings));

            return ExitCodeCalculator::WARNINGS_ONLY;
        }

        $io->success('Environment fully usable.');

        return ExitCodeCalculator::SUCCESS;
    }
}
