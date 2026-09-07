<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Tests\DependencyInjection;

use PHPSpellcheck\SpellcheckBundle\DependencyInjection\AcmeSpellcheckExtension;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Symfony\Component\DependencyInjection\Reference;

final class AcmeSpellcheckExtensionTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        return [new AcmeSpellcheckExtension()];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setParameter('kernel.project_dir', sys_get_temp_dir());
        $this->setParameter('kernel.enabled_locales', ['it', 'en']);
        $this->setParameter('kernel.default_locale', 'en');
    }

    public function testCoreServicesAreRegistered(): void
    {
        $this->load();

        $this->assertContainerBuilderHasService('acme_spellcheck.runner');
        $this->assertContainerBuilderHasService('acme_spellcheck.processor_chain');
        $this->assertContainerBuilderHasService('acme_spellcheck.tokenizer_registry');
        $this->assertContainerBuilderHasService('acme_spellcheck.reporter_registry');
        $this->assertContainerBuilderHasService('acme_spellcheck.filter_chain');
    }

    public function testDisabledBundleRegistersNothing(): void
    {
        $this->load(['enabled' => false]);

        self::assertFalse($this->container->has('acme_spellcheck.runner'));
    }

    public function testAutoBackendUsesTheChain(): void
    {
        $this->load(['backend' => 'auto']);

        $this->assertContainerBuilderHasAlias('acme_spellcheck.speller.selected', 'acme_spellcheck.speller.chain');
        $this->assertContainerBuilderHasService('acme_spellcheck.speller.hunspell');
    }

    public function testExplicitBackendRemovesTheOthers(): void
    {
        $this->load(['backend' => 'wordlist']);

        $this->assertContainerBuilderHasAlias('acme_spellcheck.speller.selected', 'acme_spellcheck.speller.wordlist');

        self::assertFalse($this->container->hasDefinition('acme_spellcheck.speller.hunspell'));
        self::assertFalse($this->container->hasDefinition('acme_spellcheck.speller.aspell'));
        self::assertFalse($this->container->hasDefinition('acme_spellcheck.speller.chain'));
    }

    public function testCacheCanBeDisabled(): void
    {
        $this->load(['cache' => ['enabled' => false]]);

        self::assertFalse($this->container->hasDefinition('acme_spellcheck.speller.caching'));
        $this->assertContainerBuilderHasAlias('acme_spellcheck.speller', 'acme_spellcheck.speller.selected');
    }

    public function testCustomCachePoolIsInjected(): void
    {
        $this->load(['cache' => ['pool' => 'cache.acme_spellcheck']]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            'acme_spellcheck.speller.caching',
            1,
            new Reference('cache.acme_spellcheck'),
        );
    }

    public function testFilesSourceIsTheDefaultAndTheOtherIsRemoved(): void
    {
        $this->load();

        $this->assertContainerBuilderHasAlias('acme_spellcheck.source.translations', 'acme_spellcheck.source.translation_files');

        self::assertFalse($this->container->hasDefinition('acme_spellcheck.source.translator_catalogue'));
    }

    public function testTranslatorSourceCanBeSelected(): void
    {
        $this->load(['translations' => ['source' => 'translator']]);

        $this->assertContainerBuilderHasAlias('acme_spellcheck.source.translations', 'acme_spellcheck.source.translator_catalogue');

        self::assertFalse($this->container->hasDefinition('acme_spellcheck.source.translation_files'));
    }

    public function testDisablingCodeRemovesItsSourceAndCommand(): void
    {
        $this->load(['code' => ['enabled' => false]]);

        self::assertFalse($this->container->hasDefinition('acme_spellcheck.source.php'));
        self::assertFalse($this->container->hasDefinition('acme_spellcheck.command.code'));
    }

    public function testBuiltinDictionaryPathsAreResolved(): void
    {
        $this->load(['builtin_dictionaries' => ['php']]);

        /** @var list<string> $paths */
        $paths = $this->container->getParameter('acme_spellcheck.dictionary_paths');

        self::assertCount(1, $paths);
        self::assertFileExists($paths[0]);
    }

    public function testLeafConfigurationBecomesParameters(): void
    {
        $this->load(['min_word_length' => 6, 'translations' => ['icu_mode' => 'always']]);

        $this->assertContainerBuilderHasParameter('acme_spellcheck.min_word_length', 6);
        $this->assertContainerBuilderHasParameter('acme_spellcheck.translations.icu_mode', 'always');
    }
}
