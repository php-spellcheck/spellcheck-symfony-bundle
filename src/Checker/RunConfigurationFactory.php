<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Checker;

use PHPSpellcheck\Core\Checker\RunConfiguration;
use PHPSpellcheck\Core\Diagnostics\DiagnosticCollector;
use PHPSpellcheck\Core\Dictionary\LocaleDictionaryMap;
use PHPSpellcheck\Core\Speller\SpellerInterface;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Merges the static configuration with the command line options.
 */
final class RunConfigurationFactory
{
    private bool $languagesDetected = false;

    /**
     * @param list<string>                 $excludedLanguages
     * @param array<string, array<mixed>>  $profiles
     */
    public function __construct(
        private readonly LocaleDictionaryMap $localeMap,
        private readonly SpellerInterface $speller,
        private readonly DiagnosticCollector $diagnostics,
        private readonly int $maxSuggestions = 3,
        private readonly bool $baselineEnabled = true,
        private readonly bool $cacheEnabled = true,
        private readonly array $excludedLanguages = [],
        private readonly array $profiles = [],
    ) {
    }

    public function create(InputInterface $input): RunConfiguration
    {
        $this->detectAvailableLanguages();

        $maxSuggestions = $this->maxSuggestions;
        $profile = $this->profileOptions($input);

        if (isset($profile['max_suggestions']) && \is_int($profile['max_suggestions'])) {
            $maxSuggestions = $profile['max_suggestions'];
        }

        $withSuggestions = $maxSuggestions > 0 && !$this->flag($input, 'no-suggestions');

        return new RunConfiguration(
            $this->localeMap,
            $withSuggestions,
            $maxSuggestions,
            $this->baselineEnabled && !$this->flag($input, 'no-baseline'),
            $this->cacheEnabled && !$this->flag($input, 'no-cache'),
            $this->flag($input, 'fail-on-warning'),
            $this->flag($input, 'ignore-warnings'),
            $this->flag($input, 'report-outdated'),
            $this->excludedLanguages,
            $this->diagnostics,
        );
    }

    /**
     * The locale to dictionary resolution is much better when it can be checked
     * against the dictionaries actually installed.
     */
    private function detectAvailableLanguages(): void
    {
        if ($this->languagesDetected) {
            return;
        }

        $this->languagesDetected = true;

        $available = $this->speller->getSupportedLanguages();

        if ([] !== $available) {
            $this->localeMap->setAvailable($available);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function profileOptions(InputInterface $input): array
    {
        if (!$input->hasOption('config-profile')) {
            return [];
        }

        $name = $input->getOption('config-profile');

        if (!\is_string($name) || '' === $name) {
            return [];
        }

        if (!isset($this->profiles[$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown profile "%s". Available profiles: %s.',
                $name,
                [] === $this->profiles ? '(none)' : implode(', ', array_keys($this->profiles)),
            ));
        }

        /** @var array<string, mixed> $options */
        $options = $this->profiles[$name];

        return $options;
    }

    private function flag(InputInterface $input, string $name): bool
    {
        return $input->hasOption($name) && true === $input->getOption($name);
    }
}
