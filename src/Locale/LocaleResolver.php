<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\Locale;

use Acme\Spellcheck\Diagnostics\DiagnosticCollector;
use Acme\Spellcheck\Model\DiagnosticCode;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Determines which locales to check.
 *
 * The enumeration is driven by the locale list, never by the translator:
 * TranslatorBagInterface::getCatalogues() only returns the catalogues that
 * happen to be loaded already, which on a freshly booted command is close to
 * none. See also the fact that getCatalogues() is not part of the interface in
 * Symfony 5.4 at all.
 */
final class LocaleResolver
{
    /** @var list<string>|null */
    private ?array $resolved = null;

    /**
     * @param list<string> $configuredLocales
     * @param list<string> $enabledLocales    %kernel.enabled_locales%
     */
    public function __construct(
        private readonly TranslatorBagInterface $translatorBag,
        private readonly array $configuredLocales,
        private readonly array $enabledLocales,
        private readonly DiagnosticCollector $diagnostics,
        private readonly string $defaultLocale = 'en',
    ) {
    }

    /**
     * @param list<string> $only restricts the result, typically from --locale
     *
     * @return list<string>
     */
    public function resolve(array $only = []): array
    {
        $locales = $this->resolved ??= $this->determine();

        if ([] === $only) {
            return $locales;
        }

        $only = $this->normalize($only);

        return array_values(array_intersect($locales, $only));
    }

    /**
     * @return list<string>
     */
    private function determine(): array
    {
        if ([] !== $this->configuredLocales) {
            return $this->normalize($this->configuredLocales);
        }

        if ([] !== $this->enabledLocales) {
            return $this->normalize($this->enabledLocales);
        }

        $locales = [];

        if ($this->translatorBag instanceof LocaleAwareInterface) {
            $locales[] = $this->translatorBag->getLocale();
        } else {
            $locales[] = $this->defaultLocale;
        }

        // getFallbackLocales() is not part of TranslatorBagInterface and is not
        // forwarded by every decorator (DataCollectorTranslator does not).
        if (method_exists($this->translatorBag, 'getFallbackLocales')) {
            /** @var list<string> $fallbacks */
            $fallbacks = $this->translatorBag->getFallbackLocales();
            $locales = array_merge($locales, $fallbacks);
        }

        $locales = $this->normalize($locales);

        if ([] === $locales) {
            $this->diagnostics->add(
                DiagnosticCode::MISSING_LOCALES,
                'No locale could be determined. Set framework.enabled_locales or acme_spellcheck.translations.locales.',
            );

            return [];
        }

        $this->diagnostics->add(
            DiagnosticCode::MISSING_LOCALES,
            sprintf(
                'Locales were inferred from the translator (%s). Configure framework.enabled_locales or '
                .'acme_spellcheck.translations.locales for reproducible runs.',
                implode(', ', $locales),
            ),
        );

        return $locales;
    }

    /**
     * @param list<string> $locales
     *
     * @return list<string>
     */
    private function normalize(array $locales): array
    {
        $normalized = [];

        foreach ($locales as $locale) {
            $locale = str_replace('-', '_', trim($locale));

            if ('' !== $locale) {
                $normalized[$locale] = true;
            }
        }

        return array_keys($normalized);
    }
}
