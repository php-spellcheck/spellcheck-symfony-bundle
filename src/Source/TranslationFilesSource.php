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
use Psr\Container\ContainerInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\Loader\LoaderInterface;

/**
 * Reads the translation files of the project directly.
 *
 * Slower to set up than the translator based source, but it knows which file
 * every message comes from, which is what makes file and line reporting
 * possible, and it never sees vendor catalogues.
 */
final class TranslationFilesSource implements SourceInterface
{
    /**
     * Domains may contain dots, locales may contain "_", "-" and "@". The
     * domain match is greedy and backtracks until the remainder is a plausible
     * locale.
     */
    private const FILENAME = '/^(?P<domain>.+)\.(?P<locale>[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*(?:@[A-Za-z0-9]+)?)\.(?P<format>[a-z0-9]+)$/';

    /** @var list<string> */
    private array $onlyLocales = [];

    private DomainFilter $domainFilter;

    /**
     * @param list<string> $paths
     */
    public function __construct(
        private readonly ContainerInterface $loaders,
        private readonly array $paths,
        DomainFilter $domainFilter,
        private readonly LocaleResolver $localeResolver,
        private readonly TranslationFileLocator $locator,
        private readonly DiagnosticCollector $diagnostics,
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
        $directories = array_values(array_filter($this->paths, 'is_dir'));

        if ([] === $directories) {
            $this->diagnostics->add(
                DiagnosticCode::SKIPPED_FILE,
                sprintf('None of the configured translation paths exists: %s.', implode(', ', $this->paths)),
            );

            return;
        }

        $allowedLocales = $this->localeResolver->resolve($this->onlyLocales);

        foreach ((new Finder())->files()->in($directories)->sortByName() as $file) {
            $basename = $file->getFilename();

            if (1 !== preg_match(self::FILENAME, $basename, $matches)) {
                $this->diagnostics->add(
                    DiagnosticCode::UNPARSABLE_FILENAME,
                    sprintf('Skipping "%s": the name does not follow <domain>.<locale>.<format>.', $basename),
                );

                continue;
            }

            $locale = str_replace('-', '_', $matches['locale']);

            if (!\in_array($locale, $allowedLocales, true)) {
                continue;
            }

            $rawDomain = $matches['domain'];
            $isIcuDomain = str_ends_with($rawDomain, MessageCatalogueInterface::INTL_DOMAIN_SUFFIX);

            $domain = $isIcuDomain
                ? substr($rawDomain, 0, -\strlen(MessageCatalogueInterface::INTL_DOMAIN_SUFFIX))
                : $rawDomain;

            if (!$this->domainFilter->accepts($domain)) {
                continue;
            }

            $catalogue = $this->load($file->getPathname(), $locale, $rawDomain, $matches['format']);

            if (null === $catalogue) {
                continue;
            }

            yield from $this->walk($catalogue, $file->getPathname(), $locale, $domain, $rawDomain, $isIcuDomain);
        }
    }

    private function load(string $path, string $locale, string $domain, string $format): ?MessageCatalogueInterface
    {
        if (!$this->loaders->has($format)) {
            $this->diagnostics->add(
                DiagnosticCode::SKIPPED_FILE,
                sprintf('No translation loader is registered for the "%s" format.', $format),
                Location::file($path),
            );

            return null;
        }

        /** @var LoaderInterface $loader */
        $loader = $this->loaders->get($format);

        try {
            return $loader->load($path, $locale, $domain);
        } catch (\Throwable $e) {
            $this->diagnostics->add(
                DiagnosticCode::SKIPPED_FILE,
                sprintf('Unable to load "%s": %s', $path, $e->getMessage()),
                Location::file($path),
            );

            return null;
        }
    }

    /**
     * @return iterable<TextFragment>
     */
    private function walk(
        MessageCatalogueInterface $catalogue,
        string $path,
        string $locale,
        string $domain,
        string $rawDomain,
        bool $isIcuDomain,
    ): iterable {
        foreach ($catalogue->all($rawDomain) as $key => $message) {
            if (!\is_string($message) || '' === trim($message)) {
                continue;
            }

            $context = FragmentContext::translation($locale, $domain, (string) $key)
                ->with('icu', $isIcuDomain ? '1' : '0');

            yield new TextFragment(
                $message,
                $locale,
                $context,
                $this->locator->locateInFile($path, $domain, $locale, (string) $key, $message),
            );

            if ($this->checkKeys) {
                yield new TextFragment(
                    (string) $key,
                    $this->keyLanguage,
                    $context->with('checked', 'key'),
                    $this->locator->locateInFile($path, $domain, $locale, (string) $key, $message),
                    null,
                    TokenizerMode::IDENTIFIER,
                );
            }
        }
    }
}
