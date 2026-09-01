<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\Command;

use Acme\Spellcheck\Checker\ExitCodeCalculator;
use Acme\Spellcheck\Dictionary\DictionaryLoader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'spellcheck:dictionary:add',
    description: 'Adds words to a project dictionary, keeping it sorted and deduplicated',
)]
final class SpellcheckDictionaryAddCommand extends Command
{
    private const HEADER = "# Project dictionary. One word per line; lines starting with # are ignored.\n"
        ."# Managed by \"bin/console spellcheck:dictionary:add\".";

    /**
     * @param list<string> $configuredDictionaries
     */
    public function __construct(
        private readonly DictionaryLoader $loader,
        private readonly array $configuredDictionaries,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('words', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Words to add')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Target dictionary file')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Write to the dictionary of this locale')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $words */
        $words = array_values(array_filter(array_map('strval', (array) $input->getArgument('words'))));

        $path = $this->resolvePath($input);

        if (null === $path) {
            $io->error(
                'No dictionary file configured. Set acme_spellcheck.dictionaries or pass --file.',
            );

            return ExitCodeCalculator::ENVIRONMENT_ERROR;
        }

        $existing = is_file($path)
            ? $this->loader->parse((string) file_get_contents($path))
            : [];

        $before = \count($existing);
        $merged = array_merge($existing, $words);

        $this->loader->save($path, $merged, self::HEADER);

        $after = \count($this->loader->parse((string) file_get_contents($path)));

        $io->success(sprintf('%d word(s) added to %s (%d -> %d).', $after - $before, $path, $before, $after));

        return ExitCodeCalculator::SUCCESS;
    }

    private function resolvePath(InputInterface $input): ?string
    {
        $file = $input->getOption('file');

        if (\is_string($file) && '' !== $file) {
            return $file;
        }

        $locale = $input->getOption('locale');

        if (\is_string($locale) && '' !== $locale) {
            foreach ($this->configuredDictionaries as $candidate) {
                if ($locale === $this->loader->detectLanguage($candidate)) {
                    return $candidate;
                }
            }
        }

        foreach ($this->configuredDictionaries as $candidate) {
            if (null === $this->loader->detectLanguage($candidate)) {
                return $candidate;
            }
        }

        return $this->configuredDictionaries[0] ?? null;
    }
}
