<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\Tests\Unit;

use Acme\Spellcheck\Diagnostics\DiagnosticCollector;
use Acme\SpellcheckBundle\Locale\LocaleResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;

final class LocaleResolverTest extends TestCase
{
    public function testConfiguredLocalesWin(): void
    {
        $resolver = new LocaleResolver($this->bag(), ['de', 'fr'], ['it', 'en'], new DiagnosticCollector());

        self::assertSame(['de', 'fr'], $resolver->resolve());
    }

    public function testEnabledLocalesAreUsedWhenNothingIsConfigured(): void
    {
        $resolver = new LocaleResolver($this->bag(), [], ['it', 'en'], new DiagnosticCollector());

        self::assertSame(['it', 'en'], $resolver->resolve());
    }

    public function testHyphensAreNormalised(): void
    {
        $resolver = new LocaleResolver($this->bag(), ['pt-BR'], [], new DiagnosticCollector());

        self::assertSame(['pt_BR'], $resolver->resolve());
    }

    public function testDuplicatesAreRemoved(): void
    {
        $resolver = new LocaleResolver($this->bag(), ['it', 'it', 'en'], [], new DiagnosticCollector());

        self::assertSame(['it', 'en'], $resolver->resolve());
    }

    public function testFallsBackToTheTranslatorAndWarns(): void
    {
        $diagnostics = new DiagnosticCollector();
        $resolver = new LocaleResolver($this->bagWithFallbacks('it', ['en']), [], [], $diagnostics);

        self::assertSame(['it', 'en'], $resolver->resolve());
        self::assertCount(1, $diagnostics->all());
        self::assertSame('missing_locales', $diagnostics->all()[0]->code->value);
    }

    public function testDecoratorWithoutGetFallbackLocalesStillWorks(): void
    {
        $diagnostics = new DiagnosticCollector();
        $resolver = new LocaleResolver($this->bagLocaleAware('it'), [], [], $diagnostics);

        self::assertSame(['it'], $resolver->resolve());
    }

    public function testCliRestrictionIntersects(): void
    {
        $resolver = new LocaleResolver($this->bag(), ['it', 'en', 'de'], [], new DiagnosticCollector());

        self::assertSame(['it', 'de'], $resolver->resolve(['it', 'de', 'fr']));
    }

    private function bag(): TranslatorBagInterface
    {
        return new class() implements TranslatorBagInterface {
            public function getCatalogue(?string $locale = null): MessageCatalogueInterface
            {
                return new MessageCatalogue($locale ?? 'en');
            }

            public function getCatalogues(): array
            {
                return [];
            }
        };
    }

    /**
     * @param list<string> $fallbacks
     */
    private function bagWithFallbacks(string $locale, array $fallbacks): TranslatorBagInterface
    {
        return new class($locale, $fallbacks) implements TranslatorBagInterface, LocaleAwareInterface {
            /**
             * @param list<string> $fallbacks
             */
            public function __construct(private string $locale, private readonly array $fallbacks)
            {
            }

            public function getCatalogue(?string $locale = null): MessageCatalogueInterface
            {
                return new MessageCatalogue($locale ?? $this->locale);
            }

            public function getCatalogues(): array
            {
                return [];
            }

            public function setLocale(string $locale): void
            {
                $this->locale = $locale;
            }

            public function getLocale(): string
            {
                return $this->locale;
            }

            /**
             * @return list<string>
             */
            public function getFallbackLocales(): array
            {
                return $this->fallbacks;
            }
        };
    }

    private function bagLocaleAware(string $locale): TranslatorBagInterface
    {
        return new class($locale) implements TranslatorBagInterface, LocaleAwareInterface {
            public function __construct(private string $locale)
            {
            }

            public function getCatalogue(?string $locale = null): MessageCatalogueInterface
            {
                return new MessageCatalogue($locale ?? $this->locale);
            }

            public function getCatalogues(): array
            {
                return [];
            }

            public function setLocale(string $locale): void
            {
                $this->locale = $locale;
            }

            public function getLocale(): string
            {
                return $this->locale;
            }
        };
    }
}
