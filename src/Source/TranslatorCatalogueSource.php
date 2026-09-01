<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\Source;

use Acme\Spellcheck\Diagnostics\DiagnosticCollector;
use Acme\Spellcheck\Locator\TranslationFileLocator;
use Acme\Spellcheck\Model\DiagnosticCode;
use Acme\Spellcheck\Model\FragmentContext;
use Acme\Spellcheck\Model\Location;
use Acme\Spellcheck\Model\TextFragment;
use Acme\Spellcheck\Model\TokenizerMode;
use Acme\Spellcheck\Source\SourceInterface;
use Acme\SpellcheckBundle\Locale\LocaleResolver;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Reads every catalogue known to the Translator, vendor messages included.
 *
 * The catalogues are loaded explicitly, locale by locale: getCatalogues() only
 * returns what happens to be loaded already, and in Symfony 5.4 it is not even
 * part of TranslatorBagInterface.
 */
final class TranslatorCatalogueSource implements SourceInterface
{
    /** @var list<string> */
    private array $onlyLocales = [];

    private DomainFilter $domainFilter;

    public function __construct(
        private readonly TranslatorBagInterface $translatorBag,
        private readonly LocaleResolver $localeResolver,
        DomainFilter $domainFilter,
        private readonly TranslationFileLocator $locator,
        private readonly DiagnosticCollector $diagnostics,
        private readonly bool $includeFallbacks = false,
        private readonly bool $checkKeys = false,
        private readonly string $keyLanguage = 'en_US',
    ) {
        $this->domainFilter = $domainFilter;
    }

    public function getName(): string
    {
        return 'translations';
    }

    /**
     * @param list<string> $locales
     * @param list<string> $domains
     */
    public function restrict(array $locales, array $domains, bool $forceDomains = false): self
    {
        $clone = clone $this;
        $clone->onlyLocales = $locales;
        $clone->domainFilter = $this->domainFilter->restrictTo($domains, $forceDomains);

        return $clone;
    }

    public function fragments(): iterable
    {
        foreach ($this->localeResolver->resolve($this->onlyLocales) as $locale) {
            $catalogue = $this->loadCatalogue($locale);

            if (null === $catalogue) {
                continue;
            }

            yield from $this->walk($catalogue, $locale);

            if (!$this->includeFallbacks) {
                continue;
            }

            $seen = [$locale => true];

            while (null !== ($catalogue = $catalogue->getFallbackCatalogue())) {
                $fallback = $catalogue->getLocale();

                // Defensive: a misconfigured fallback chain can be circular.
                if (isset($seen[$fallback])) {
                    break;
                }

                $seen[$fallback] = true;

                yield from $this->walk($catalogue, $fallback);
            }
        }
    }

    private function loadCatalogue(string $locale): ?MessageCatalogueInterface
    {
        try {
            return $this->translatorBag->getCatalogue($locale);
        } catch (\Throwable $e) {
            $this->diagnostics->add(
                DiagnosticCode::SKIPPED_FILE,
                sprintf('Unable to load the catalogue for locale "%s": %s', $locale, $e->getMessage()),
            );

            return null;
        }
    }

    /**
     * @return iterable<TextFragment>
     */
    private function walk(MessageCatalogueInterface $catalogue, string $locale): iterable
    {
        $domains = $catalogue->getDomains();

        if ([] === $domains) {
            $this->diagnostics->add(
                DiagnosticCode::SKIPPED_FILE,
                sprintf('The catalogue for locale "%s" contains no message.', $locale),
            );

            return;
        }

        foreach ($domains as $domain) {
            $isIcuDomain = str_ends_with($domain, MessageCatalogueInterface::INTL_DOMAIN_SUFFIX);

            $logicalDomain = $isIcuDomain
                ? substr($domain, 0, -\strlen(MessageCatalogueInterface::INTL_DOMAIN_SUFFIX))
                : $domain;

            if (!$this->domainFilter->accepts($logicalDomain)) {
                continue;
            }

            foreach ($catalogue->all($domain) as $key => $message) {
                if (!\is_string($message) || '' === trim($message)) {
                    continue;
                }

                $context = FragmentContext::translation($locale, $logicalDomain, (string) $key)
                    ->with('icu', $isIcuDomain ? '1' : '0');

                yield new TextFragment(
                    $message,
                    $locale,
                    $context,
                    $this->locator->locate($logicalDomain, $locale, (string) $key, $message)
                        ?? Location::logical($this->locator->descriptor($locale, $logicalDomain, (string) $key)),
                );

                if ($this->checkKeys) {
                    yield new TextFragment(
                        (string) $key,
                        $this->keyLanguage,
                        $context->with('checked', 'key'),
                        Location::logical($this->locator->descriptor($locale, $logicalDomain, (string) $key)),
                        null,
                        TokenizerMode::IDENTIFIER,
                    );
                }
            }
        }
    }
}
