<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\DependencyInjection;

use Acme\Spellcheck\Dictionary\BuiltinDictionaries;
use Acme\Spellcheck\Php\IdentifierKind;
use Acme\Spellcheck\Processor\IcuMessageProcessor;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public const BACKENDS = ['auto', 'hunspell', 'aspell', 'pspell', 'wordlist', 'null'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('acme_spellcheck');

        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('enabled')
                    ->info('Disables the bundle entirely: no service and no command is registered.')
                    ->defaultTrue()
                ->end()
                ->enumNode('backend')
                    ->info('"auto" picks the first available backend: hunspell, aspell, pspell, wordlist.')
                    ->values(self::BACKENDS)
                    ->defaultValue('auto')
                ->end()
                ->arrayNode('backend_options')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('hunspell_binary')->defaultValue('hunspell')->cannotBeEmpty()->end()
                        ->scalarNode('aspell_binary')->defaultValue('aspell')->cannotBeEmpty()->end()
                        ->floatNode('read_timeout')
                            ->info('Seconds to wait for a response from the speller process.')
                            ->defaultValue(10.0)
                            ->min(0.1)
                        ->end()
                        ->booleanNode('terse_mode')
                            ->info('Ispell terse mode: cuts the I/O by ~90%. Set to false if a run times out.')
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('extra_dictionaries')
                            ->info('Additional system dictionaries loaded together with the locale one (hunspell only).')
                            ->useAttributeAsKey('locale')
                            ->arrayPrototype()
                                ->scalarPrototype()->end()
                            ->end()
                            ->example(['it_IT' => ['en_US']])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('locale_map')
                    ->info('Overrides the locale to system dictionary resolution.')
                    ->useAttributeAsKey('locale')
                    ->scalarPrototype()->end()
                    ->example(['en' => 'en_GB', 'pt' => 'pt_BR'])
                ->end()
                ->arrayNode('dictionaries')
                    ->info('Word list files. A file named <name>.<locale>.txt only applies to that locale.')
                    ->scalarPrototype()->end()
                    ->validate()
                        ->ifTrue(static function (array $paths): bool {
                            foreach ($paths as $path) {
                                // Parameters are resolved later, so they are skipped here.
                                if (!\is_string($path) || str_contains($path, '%')) {
                                    continue;
                                }

                                if (!is_file($path)) {
                                    return true;
                                }
                            }

                            return false;
                        })
                        ->thenInvalid('One of the acme_spellcheck.dictionaries files does not exist: %s')
                    ->end()
                ->end()
                ->arrayNode('builtin_dictionaries')
                    ->info('Dictionaries shipped with the package.')
                    ->enumPrototype()->values(BuiltinDictionaries::NAMES)->end()
                    ->defaultValue(BuiltinDictionaries::NAMES)
                ->end()
                ->booleanNode('case_sensitive')->defaultFalse()->end()
                ->booleanNode('check_case')
                    ->info('Reports "english" instead of "English". Unreliable with the pipe backends.')
                    ->defaultFalse()
                ->end()
                ->integerNode('max_suggestions')->defaultValue(3)->min(0)->end()
                ->integerNode('min_word_length')->defaultValue(4)->min(2)->end()
                ->arrayNode('ignore_patterns')
                    ->info('Full regular expressions, delimiters included, matched against single tokens.')
                    ->scalarPrototype()->end()
                    ->validate()
                        ->ifTrue(static function (array $patterns): bool {
                            foreach ($patterns as $pattern) {
                                if (!\is_string($pattern) || false === @preg_match($pattern, '')) {
                                    return true;
                                }
                            }

                            return false;
                        })
                        ->thenInvalid('One of the acme_spellcheck.ignore_patterns is not a valid regular expression: %s')
                    ->end()
                    ->example(['/^[A-Z]{2,5}$/', '/^v\d+$/'])
                ->end()
                ->scalarNode('suppression_prefix')->defaultValue('@spellcheck')->cannotBeEmpty()->end()
                ->append($this->baselineNode())
                ->append($this->cacheNode())
                ->append($this->translationsNode())
                ->append($this->codeNode())
                ->append($this->profilesNode())
            ->end()
        ;

        return $treeBuilder;
    }

    private function baselineNode(): NodeDefinition
    {
        $node = (new TreeBuilder('baseline'))->getRootNode();

        \assert($node instanceof ArrayNodeDefinition);

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('path')
                    ->defaultValue('%kernel.project_dir%/.spellcheck/baseline.json')
                    ->cannotBeEmpty()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function cacheNode(): NodeDefinition
    {
        $node = (new TreeBuilder('cache'))->getRootNode();

        \assert($node instanceof ArrayNodeDefinition);

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('pool')
                    ->info('PSR-6 pool service id. Declare it under framework.cache.pools.')
                    ->defaultValue('cache.app')
                ->end()
                ->integerNode('ttl')->defaultValue(0)->min(0)->end()
            ->end()
        ;

        return $node;
    }

    private function translationsNode(): NodeDefinition
    {
        $node = (new TreeBuilder('translations'))->getRootNode();

        \assert($node instanceof ArrayNodeDefinition);

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->enumNode('source')
                    ->info('"files" knows the file and line of every message; "translator" also sees vendor catalogues.')
                    ->values(['files', 'translator'])
                    ->defaultValue('files')
                ->end()
                ->arrayNode('paths')
                    ->scalarPrototype()->end()
                    ->defaultValue(['%kernel.project_dir%/translations'])
                ->end()
                ->arrayNode('locales')
                    ->info('Empty means %kernel.enabled_locales%.')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('domains')
                    ->info('Empty means every domain.')
                    ->scalarPrototype()->end()
                ->end()
                ->arrayNode('exclude_domains')
                    ->info('Globs are supported. Defaults to validators and security in "translator" mode.')
                    ->scalarPrototype()->end()
                ->end()
                ->booleanNode('include_fallbacks')->defaultFalse()->end()
                ->booleanNode('check_keys')->defaultFalse()->end()
                ->booleanNode('check_notes')->defaultFalse()->end()
                ->enumNode('icu_mode')
                    ->values([
                        IcuMessageProcessor::MODE_AUTO,
                        IcuMessageProcessor::MODE_ALWAYS,
                        IcuMessageProcessor::MODE_DOMAIN_SUFFIX,
                    ])
                    ->defaultValue(IcuMessageProcessor::MODE_AUTO)
                ->end()
                ->arrayNode('excluded_languages')
                    ->info('Languages without word separation: tokenization is not supported.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['ja', 'zh', 'ko', 'th'])
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $config): bool => 'translator' === $config['source'] && [] === $config['exclude_domains'])
                ->then(static function (array $config): array {
                    // In translator mode the vendor catalogues are included:
                    // a defensive default avoids drowning the first run.
                    $config['exclude_domains'] = ['validators', 'security'];

                    return $config;
                })
            ->end()
        ;

        return $node;
    }

    private function codeNode(): NodeDefinition
    {
        $node = (new TreeBuilder('code'))->getRootNode();

        \assert($node instanceof ArrayNodeDefinition);

        $defaultKinds = array_map(
            static fn (IdentifierKind $kind): string => $kind->value,
            IdentifierKind::DEFAULTS,
        );

        $allKinds = array_map(
            static fn (IdentifierKind $kind): string => $kind->value,
            IdentifierKind::cases(),
        );

        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->scalarNode('language')->defaultValue('en_US')->cannotBeEmpty()->end()
                ->arrayNode('paths')
                    ->scalarPrototype()->end()
                    ->defaultValue(['%kernel.project_dir%/src'])
                ->end()
                ->arrayNode('exclude')
                    ->scalarPrototype()->end()
                    ->defaultValue(['vendor', 'var', 'tests/Fixtures'])
                ->end()
                ->arrayNode('check')
                    ->info('variable and string_literal are excluded by default: too noisy.')
                    ->enumPrototype()->values($allKinds)->end()
                    ->defaultValue($defaultKinds)
                ->end()
                ->scalarNode('max_file_size')->defaultValue('2M')->end()
                ->booleanNode('strict_docblock_tags')
                    ->info('Strips types and variable names from @param, @return, @var and friends.')
                    ->defaultTrue()
                ->end()
            ->end()
        ;

        return $node;
    }

    private function profilesNode(): NodeDefinition
    {
        $node = (new TreeBuilder('profiles'))->getRootNode();

        \assert($node instanceof ArrayNodeDefinition);

        $node
            ->info('Reusable option sets, applied with --profile=<name>.')
            ->useAttributeAsKey('name')
            ->variablePrototype()->end()
        ;

        return $node;
    }
}
