<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Tests\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use PHPSpellcheck\SpellcheckBundle\DependencyInjection\PHPSpellcheckExtension;
use Symfony\Component\DependencyInjection\Reference;

final class PHPSpellcheckExtensionTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        return [new PHPSpellcheckExtension()];
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

        $this->assertContainerBuilderHasService('php_spellcheck.runner');
        $this->assertContainerBuilderHasService('php_spellcheck.processor_chain');
        $this->assertContainerBuilderHasService('php_spellcheck.tokenizer_registry');
        $this->assertContainerBuilderHasService('php_spellcheck.reporter_registry');
        $this->assertContainerBuilderHasService('php_spellcheck.filter_chain');
    }

    public function testDisabledBundleRegistersNothing(): void
    {
        $this->load(['enabled' => false]);

        self::assertFalse($this->container->has('php_spellcheck.runner'));
    }

    public function testAutoBackendUsesTheChain(): void
    {
        $this->load(['backend' => 'auto']);

        $this->assertContainerBuilderHasAlias('php_spellcheck.speller.selected', 'php_spellcheck.speller.chain');
        $this->assertContainerBuilderHasService('php_spellcheck.speller.hunspell');
    }

    public function testExplicitBackendRemovesTheOthers(): void
    {
        $this->load(['backend' => 'wordlist']);

        $this->assertContainerBuilderHasAlias('php_spellcheck.speller.selected', 'php_spellcheck.speller.wordlist');

        self::assertFalse($this->container->hasDefinition('php_spellcheck.speller.hunspell'));
        self::assertFalse($this->container->hasDefinition('php_spellcheck.speller.aspell'));
        self::assertFalse($this->container->hasDefinition('php_spellcheck.speller.chain'));
    }

    public function testCacheCanBeDisabled(): void
    {
        $this->load(['cache' => ['enabled' => false]]);

        self::assertFalse($this->container->hasDefinition('php_spellcheck.speller.caching'));
        $this->assertContainerBuilderHasAlias('php_spellcheck.speller', 'php_spellcheck.speller.selected');
    }

    public function testCustomCachePoolIsInjected(): void
    {
        $this->load(['cache' => ['pool' => 'cache.php_spellcheck']]);

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(
            'php_spellcheck.speller.caching',
            1,
            new Reference('cache.php_spellcheck'),
        );
    }

    public function testFilesSourceIsTheDefaultAndTheOtherIsRemoved(): void
    {
        $this->load();

        $this->assertContainerBuilderHasAlias('php_spellcheck.source.translations', 'php_spellcheck.source.translation_files');

        self::assertFalse($this->container->hasDefinition('php_spellcheck.source.translator_catalogue'));
    }

    public function testTranslatorSourceCanBeSelected(): void
    {
        $this->load(['translations' => ['source' => 'translator']]);

        $this->assertContainerBuilderHasAlias('php_spellcheck.source.translations', 'php_spellcheck.source.translator_catalogue');

        self::assertFalse($this->container->hasDefinition('php_spellcheck.source.translation_files'));
    }

    public function testDisablingCodeRemovesItsSourceAndCommand(): void
    {
        $this->load(['code' => ['enabled' => false]]);

        self::assertFalse($this->container->hasDefinition('php_spellcheck.source.php'));
        self::assertFalse($this->container->hasDefinition('php_spellcheck.command.code'));
    }

    public function testBuiltinDictionaryPathsAreResolved(): void
    {
        $this->load(['builtin_dictionaries' => ['php']]);

        /** @var list<string> $paths */
        $paths = $this->container->getParameter('php_spellcheck.dictionary_paths');

        self::assertCount(1, $paths);
        self::assertFileExists($paths[0]);
    }

    public function testLeafConfigurationBecomesParameters(): void
    {
        $this->load(['min_word_length' => 6, 'translations' => ['icu_mode' => 'always']]);

        $this->assertContainerBuilderHasParameter('php_spellcheck.min_word_length', 6);
        $this->assertContainerBuilderHasParameter('php_spellcheck.translations.icu_mode', 'always');
    }
}
