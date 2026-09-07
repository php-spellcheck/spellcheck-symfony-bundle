<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\DependencyInjection;

use PHPSpellcheck\Core\Dictionary\BuiltinDictionaries;
use PHPSpellcheck\Core\Filter\MisspellingFilterInterface;
use PHPSpellcheck\Core\Processor\TextProcessorInterface;
use PHPSpellcheck\Core\Report\ReporterInterface;
use PHPSpellcheck\Core\Source\SourceInterface;
use PHPSpellcheck\Core\Speller\SpellerInterface;
use PHPSpellcheck\Core\Tokenizer\TokenizerInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class AcmeSpellcheckExtension extends Extension
{
    /** @var array<class-string, string> */
    private const AUTOCONFIGURATION = [
        SourceInterface::class => 'acme_spellcheck.source',
        TextProcessorInterface::class => 'acme_spellcheck.processor',
        TokenizerInterface::class => 'acme_spellcheck.tokenizer',
        SpellerInterface::class => 'acme_spellcheck.speller',
        ReporterInterface::class => 'acme_spellcheck.reporter',
        MisspellingFilterInterface::class => 'acme_spellcheck.filter',
    ];

    /**
     * @param array<array-key, mixed> $config
     */
    public function getConfiguration(array $config, ContainerBuilder $container): Configuration
    {
        return new Configuration();
    }

    /**
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array<string, mixed> $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        if (false === $config['enabled']) {
            return;
        }

        $loader = new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');

        $this->registerParameters($container, $config);
        $this->configureDictionaries($container, $config);
        $this->configureBackend($container, $config);
        $this->configureCache($container, $config);
        $this->configureSources($container, $config);
        $this->registerAutoconfiguration($container);
    }

    /**
     * Every leaf becomes a parameter, so that the effective configuration is
     * inspectable with "debug:container --parameters".
     *
     * @param array<string, mixed> $config
     */
    private function registerParameters(ContainerBuilder $container, array $config, string $prefix = 'acme_spellcheck'): void
    {
        foreach ($config as $key => $value) {
            $name = $prefix.'.'.$key;

            if (\is_array($value) && [] !== $value && !array_is_list($value) && 'profiles' !== $key && 'locale_map' !== $key && 'extra_dictionaries' !== $key) {
                $this->registerParameters($container, $value, $name);

                continue;
            }

            $container->setParameter($name, $value);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureDictionaries(ContainerBuilder $container, array $config): void
    {
        /** @var list<string> $builtin */
        $builtin = $config['builtin_dictionaries'];
        /** @var list<string> $custom */
        $custom = $config['dictionaries'];

        $paths = array_merge(BuiltinDictionaries::paths($builtin), $custom);

        $container->setParameter('acme_spellcheck.dictionary_paths', $paths);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureBackend(ContainerBuilder $container, array $config): void
    {
        /** @var string $backend */
        $backend = $config['backend'];

        if ('auto' === $backend) {
            // ChainSpeller resolves the actual backend at runtime, per language.
            $container->setAlias('acme_spellcheck.speller.selected', 'acme_spellcheck.speller.chain');

            return;
        }

        $id = 'acme_spellcheck.speller.'.$backend;
        $container->setAlias('acme_spellcheck.speller.selected', $id);

        // The chain is only useful in auto mode.
        $container->removeDefinition('acme_spellcheck.speller.chain');

        foreach (Configuration::BACKENDS as $candidate) {
            if ('auto' === $candidate || $candidate === $backend) {
                continue;
            }

            $candidateId = 'acme_spellcheck.speller.'.$candidate;

            if ($container->hasDefinition($candidateId)) {
                $container->removeDefinition($candidateId);
            }
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureCache(ContainerBuilder $container, array $config): void
    {
        /** @var array{enabled: bool, pool: string, ttl: int} $cache */
        $cache = $config['cache'];

        if (!$cache['enabled']) {
            $container->removeDefinition('acme_spellcheck.speller.caching');
            $container->setAlias('acme_spellcheck.speller', 'acme_spellcheck.speller.selected');

            return;
        }

        $container->getDefinition('acme_spellcheck.speller.caching')
            ->replaceArgument(1, new Reference($cache['pool']));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function configureSources(ContainerBuilder $container, array $config): void
    {
        /** @var array<string, mixed> $translations */
        $translations = $config['translations'];
        /** @var array<string, mixed> $code */
        $code = $config['code'];

        if (!$translations['enabled'] || !class_exists(\Symfony\Component\Translation\Translator::class)) {
            $container->removeDefinition('acme_spellcheck.source.translator_catalogue');
            $container->removeDefinition('acme_spellcheck.source.translation_files');
            $container->removeDefinition('acme_spellcheck.command.translations');
            $container->setParameter('acme_spellcheck.translations.available', false);

            return;
        }

        $container->setParameter('acme_spellcheck.translations.available', true);

        // Only one of the two translation sources is kept.
        $unused = 'files' === $translations['source']
            ? 'acme_spellcheck.source.translator_catalogue'
            : 'acme_spellcheck.source.translation_files';

        $container->removeDefinition($unused);

        $used = 'files' === $translations['source']
            ? 'acme_spellcheck.source.translation_files'
            : 'acme_spellcheck.source.translator_catalogue';

        $container->setAlias('acme_spellcheck.source.translations', $used);

        if (!$code['enabled']) {
            $container->removeDefinition('acme_spellcheck.source.php');
            $container->removeDefinition('acme_spellcheck.command.code');
        }
    }

    private function registerAutoconfiguration(ContainerBuilder $container): void
    {
        // #[AutoconfigureTag] does not exist in Symfony 5.4.
        foreach (self::AUTOCONFIGURATION as $interface => $tag) {
            $container->registerForAutoconfiguration($interface)->addTag($tag);
        }
    }
}
