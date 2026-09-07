<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use PHPSpellcheck\Core\Baseline\BaselineStorage;
use PHPSpellcheck\Core\Checker\MisspellingFactory;
use PHPSpellcheck\Core\Checker\RunStatisticsCollector;
use PHPSpellcheck\Core\Checker\SpellcheckRunner;
use PHPSpellcheck\Core\Diagnostics\DiagnosticCollector;
use PHPSpellcheck\Core\Dictionary\AggregateDictionary;
use PHPSpellcheck\Core\Dictionary\DictionaryLoader;
use PHPSpellcheck\Core\Dictionary\LocaleDictionaryMap;
use PHPSpellcheck\Core\Filter\BaselineFilter;
use PHPSpellcheck\Core\Filter\DeduplicationFilter;
use PHPSpellcheck\Core\Filter\DictionaryFilter;
use PHPSpellcheck\Core\Filter\FilterChain;
use PHPSpellcheck\Core\Filter\PatternFilter;
use PHPSpellcheck\Core\Icu\IcuMessageParser;
use PHPSpellcheck\Core\Locator\PhpArrayKeyLocator;
use PHPSpellcheck\Core\Locator\TranslationFileLocator;
use PHPSpellcheck\Core\Locator\XliffKeyLocator;
use PHPSpellcheck\Core\Locator\YamlKeyLocator;
use PHPSpellcheck\Core\Processor\DocBlockProcessor;
use PHPSpellcheck\Core\Processor\HtmlProcessor;
use PHPSpellcheck\Core\Processor\IcuMessageProcessor;
use PHPSpellcheck\Core\Processor\LegacyPluralProcessor;
use PHPSpellcheck\Core\Processor\MarkdownProcessor;
use PHPSpellcheck\Core\Processor\NormalizeApostropheProcessor;
use PHPSpellcheck\Core\Processor\PlaceholderProcessor;
use PHPSpellcheck\Core\Processor\ProcessorChain;
use PHPSpellcheck\Core\Processor\SprintfProcessor;
use PHPSpellcheck\Core\Processor\UrlProcessor;
use PHPSpellcheck\Core\Report\CheckstyleReporter;
use PHPSpellcheck\Core\Report\CsvReporter;
use PHPSpellcheck\Core\Report\GithubReporter;
use PHPSpellcheck\Core\Report\GitlabReporter;
use PHPSpellcheck\Core\Report\JsonReporter;
use PHPSpellcheck\Core\Report\JUnitReporter;
use PHPSpellcheck\Core\Report\ReporterRegistry;
use PHPSpellcheck\Core\Report\TableReporter;
use PHPSpellcheck\Core\Speller\AspellSpeller;
use PHPSpellcheck\Core\Speller\ChainSpeller;
use PHPSpellcheck\Core\Speller\HunspellSpeller;
use PHPSpellcheck\Core\Speller\NullSpeller;
use PHPSpellcheck\Core\Speller\PspellSpeller;
use PHPSpellcheck\Core\Speller\SuggestionFormatter;
use PHPSpellcheck\Core\Speller\WordListSpeller;
use PHPSpellcheck\Core\Tokenizer\IdentifierSplitter;
use PHPSpellcheck\Core\Tokenizer\ProseTokenizer;
use PHPSpellcheck\Core\Tokenizer\TokenizerRegistry;
use PHPSpellcheck\SpellcheckBundle\Checker\RunConfigurationFactory;
use PHPSpellcheck\SpellcheckBundle\Command\SpellcheckBaselineCommand;
use PHPSpellcheck\SpellcheckBundle\Command\SpellcheckCodeCommand;
use PHPSpellcheck\SpellcheckBundle\Command\SpellcheckCommand;
use PHPSpellcheck\SpellcheckBundle\Command\SpellcheckDebugFragmentsCommand;
use PHPSpellcheck\SpellcheckBundle\Command\SpellcheckDictionaryAddCommand;
use PHPSpellcheck\SpellcheckBundle\Command\SpellcheckDoctorCommand;
use PHPSpellcheck\SpellcheckBundle\Command\SpellcheckTranslationsCommand;
use PHPSpellcheck\SpellcheckBundle\Factory\CachingSpellerFactory;
use PHPSpellcheck\SpellcheckBundle\Factory\PhpSourceFactory;
use PHPSpellcheck\SpellcheckBundle\Factory\TranslationFileLocatorFactory;
use PHPSpellcheck\SpellcheckBundle\Path\PathExpander;
use PHPSpellcheck\SpellcheckBundle\Source\DomainFilter;
use PHPSpellcheck\SpellcheckBundle\Source\TranslationFilesSource;
use PHPSpellcheck\SpellcheckBundle\Source\TranslatorCatalogueSource;

/**
 * Every dependency is declared explicitly: no autowire, no autoconfigure.
 *
 * A third party bundle that relies on autowiring for its own services breaks in
 * applications configured with `_defaults: autowire: false`.
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire(false)
        ->autoconfigure(false)
        ->private();

    // ---------------------------------------------------------------- shared

    $services->set('php_spellcheck.diagnostics', DiagnosticCollector::class);
    $services->set('php_spellcheck.statistics', RunStatisticsCollector::class);

    $services->set('php_spellcheck.path_expander', PathExpander::class);

    // --------------------------------------------------------- dictionaries

    $services->set('php_spellcheck.dictionary_loader', DictionaryLoader::class)
        ->args([param('php_spellcheck.case_sensitive')]);

    $services->set('php_spellcheck.dictionary', AggregateDictionary::class)
        ->factory([service('php_spellcheck.dictionary_loader'), 'loadAll'])
        ->args([param('php_spellcheck.dictionary_paths')]);

    $services->set('php_spellcheck.locale_dictionary_map', LocaleDictionaryMap::class)
        ->args([param('php_spellcheck.locale_map'), []]);

    // ------------------------------------------------------------- tokenizer

    $services->set('php_spellcheck.tokenizer.identifier', IdentifierSplitter::class)
        ->args([param('php_spellcheck.min_word_length'), param('php_spellcheck.ignore_patterns')])
        ->tag('php_spellcheck.tokenizer');

    $services->set('php_spellcheck.tokenizer.prose', ProseTokenizer::class)
        ->args([param('php_spellcheck.min_word_length')])
        ->tag('php_spellcheck.tokenizer');

    $services->set('php_spellcheck.tokenizer_registry', TokenizerRegistry::class)
        ->args([tagged_iterator('php_spellcheck.tokenizer')]);

    // ------------------------------------------------------------ processors

    $services->set('php_spellcheck.icu_parser', IcuMessageParser::class);

    $services->set('php_spellcheck.processor.normalize_apostrophe', NormalizeApostropheProcessor::class)
        ->tag('php_spellcheck.processor');

    $services->set('php_spellcheck.processor.legacy_plural', LegacyPluralProcessor::class)
        ->tag('php_spellcheck.processor');

    $services->set('php_spellcheck.processor.icu', IcuMessageProcessor::class)
        ->args([
            service('php_spellcheck.icu_parser'),
            param('php_spellcheck.translations.icu_mode'),
            service('php_spellcheck.diagnostics'),
        ])
        ->tag('php_spellcheck.processor');

    $services->set('php_spellcheck.processor.placeholder', PlaceholderProcessor::class)
        ->tag('php_spellcheck.processor');

    $services->set('php_spellcheck.processor.sprintf', SprintfProcessor::class)
        ->tag('php_spellcheck.processor');

    $services->set('php_spellcheck.processor.html', HtmlProcessor::class)
        ->tag('php_spellcheck.processor');

    $services->set('php_spellcheck.processor.markdown', MarkdownProcessor::class)
        ->tag('php_spellcheck.processor');

    $services->set('php_spellcheck.processor.url', UrlProcessor::class)
        ->tag('php_spellcheck.processor');

    $services->set('php_spellcheck.processor.docblock', DocBlockProcessor::class)
        ->args([param('php_spellcheck.code.strict_docblock_tags')])
        ->tag('php_spellcheck.processor');

    $services->set('php_spellcheck.processor_chain', ProcessorChain::class)
        ->args([tagged_iterator('php_spellcheck.processor')]);

    // --------------------------------------------------------------- backends

    $services->set('php_spellcheck.speller.hunspell', HunspellSpeller::class)
        ->args([
            param('php_spellcheck.backend_options.hunspell_binary'),
            param('php_spellcheck.backend_options.extra_dictionaries'),
            param('php_spellcheck.backend_options.read_timeout'),
            param('php_spellcheck.backend_options.terse_mode'),
            null,
        ]);

    $services->set('php_spellcheck.speller.aspell', AspellSpeller::class)
        ->args([
            param('php_spellcheck.backend_options.aspell_binary'),
            param('php_spellcheck.backend_options.read_timeout'),
            param('php_spellcheck.backend_options.terse_mode'),
            null,
        ]);

    $services->set('php_spellcheck.speller.pspell', PspellSpeller::class)
        ->args([0, param('php_spellcheck.max_suggestions')]);

    $services->set('php_spellcheck.speller.wordlist', WordListSpeller::class)
        ->args([service('php_spellcheck.dictionary'), param('php_spellcheck.max_suggestions')]);

    $services->set('php_spellcheck.speller.null', NullSpeller::class);

    // Only used when backend = auto; the extension removes it otherwise.
    $services->set('php_spellcheck.speller.chain', ChainSpeller::class)
        ->args([
            [
                service('php_spellcheck.speller.hunspell'),
                service('php_spellcheck.speller.aspell'),
                service('php_spellcheck.speller.pspell'),
                service('php_spellcheck.speller.wordlist'),
            ],
            service('php_spellcheck.diagnostics'),
        ]);

    // The extension aliases this to the selected backend.
    $services->alias('php_spellcheck.speller.selected', 'php_spellcheck.speller.chain');

    $services->set('php_spellcheck.speller.caching', \PHPSpellcheck\Core\Speller\CachingSpeller::class)
        ->factory([CachingSpellerFactory::class, 'create'])
        ->args([
            service('php_spellcheck.speller.selected'),
            service('cache.app'), // replaced by the extension with the configured pool
            service('php_spellcheck.dictionary'),
            param('php_spellcheck.case_sensitive'),
            param('php_spellcheck.check_case'),
            param('php_spellcheck.max_suggestions'),
            param('php_spellcheck.cache.ttl'),
            service('php_spellcheck.statistics'),
        ]);

    $services->alias('php_spellcheck.speller', 'php_spellcheck.speller.caching');

    // ---------------------------------------------------------------- filters

    $services->set('php_spellcheck.filter.dictionary', DictionaryFilter::class)
        ->args([service('php_spellcheck.dictionary')])
        ->tag('php_spellcheck.filter');

    $services->set('php_spellcheck.filter.pattern', PatternFilter::class)
        ->args([param('php_spellcheck.ignore_patterns')])
        ->tag('php_spellcheck.filter');

    $services->set('php_spellcheck.filter.deduplication', DeduplicationFilter::class)
        ->tag('php_spellcheck.filter');

    $services->set('php_spellcheck.filter.baseline', BaselineFilter::class)
        ->args([service('php_spellcheck.statistics')])
        ->tag('php_spellcheck.filter');

    $services->set('php_spellcheck.filter_chain', FilterChain::class)
        ->args([tagged_iterator('php_spellcheck.filter')]);

    // --------------------------------------------------------------- baseline

    $services->set('php_spellcheck.baseline_storage', BaselineStorage::class);

    // --------------------------------------------------------------- locators

    $services->set('php_spellcheck.locator.yaml', YamlKeyLocator::class)
        ->tag('php_spellcheck.key_locator');
    $services->set('php_spellcheck.locator.xliff', XliffKeyLocator::class)
        ->tag('php_spellcheck.key_locator');
    $services->set('php_spellcheck.locator.php_array', PhpArrayKeyLocator::class)
        ->tag('php_spellcheck.key_locator');

    $services->set('php_spellcheck.translation_file_locator_factory', TranslationFileLocatorFactory::class)
        ->args([service('php_spellcheck.path_expander')]);

    $services->set('php_spellcheck.translation_file_locator', TranslationFileLocator::class)
        ->factory([service('php_spellcheck.translation_file_locator_factory'), 'create'])
        ->args([
            param('php_spellcheck.translations.paths'),
            tagged_iterator('php_spellcheck.key_locator'),
            param('kernel.project_dir'),
        ]);

    // ---------------------------------------------------------------- runner

    $services->set('php_spellcheck.suggestion_formatter', SuggestionFormatter::class);

    $services->set('php_spellcheck.misspelling_factory', MisspellingFactory::class)
        ->args([
            service('php_spellcheck.suggestion_formatter'),
            param('php_spellcheck.max_suggestions'),
        ]);

    $services->set('php_spellcheck.runner', SpellcheckRunner::class)
        ->args([
            service('php_spellcheck.processor_chain'),
            service('php_spellcheck.tokenizer_registry'),
            service('php_spellcheck.speller'),
            service('php_spellcheck.filter_chain'),
            service('php_spellcheck.misspelling_factory'),
            service('php_spellcheck.diagnostics'),
            service('php_spellcheck.statistics'),
            service('php_spellcheck.filter.baseline'),
            service('logger')->nullOnInvalid(),
        ]);

    $services->set('php_spellcheck.run_configuration_factory', RunConfigurationFactory::class)
        ->args([
            service('php_spellcheck.locale_dictionary_map'),
            service('php_spellcheck.speller'),
            service('php_spellcheck.diagnostics'),
            param('php_spellcheck.max_suggestions'),
            param('php_spellcheck.baseline.enabled'),
            param('php_spellcheck.cache.enabled'),
            param('php_spellcheck.translations.excluded_languages'),
            param('php_spellcheck.profiles'),
        ]);

    // --------------------------------------------------------------- sources

    $services->set('php_spellcheck.domain_filter', DomainFilter::class)
        ->args([
            param('php_spellcheck.translations.domains'),
            param('php_spellcheck.translations.exclude_domains'),
        ]);

    // Placeholder reference: TranslatorOptionalPass swaps it for the real
    // translator service id, which differs depending on whether the autowiring
    // alias exists.
    $services->alias('php_spellcheck.translator_bag', 'translator')->private();

    $services->set('php_spellcheck.locale_resolver', \PHPSpellcheck\SpellcheckBundle\Locale\LocaleResolver::class)
        ->args([
            service('php_spellcheck.translator_bag'),
            param('php_spellcheck.translations.locales'),
            param('kernel.enabled_locales'),
            service('php_spellcheck.diagnostics'),
            param('kernel.default_locale'),
        ]);

    $services->set('php_spellcheck.source.translator_catalogue', TranslatorCatalogueSource::class)
        ->args([
            service('php_spellcheck.translator_bag'),
            service('php_spellcheck.locale_resolver'),
            service('php_spellcheck.domain_filter'),
            service('php_spellcheck.translation_file_locator'),
            service('php_spellcheck.diagnostics'),
            param('php_spellcheck.translations.include_fallbacks'),
            param('php_spellcheck.translations.check_keys'),
            param('php_spellcheck.code.language'),
        ]);

    $services->set('php_spellcheck.source.translation_files', TranslationFilesSource::class)
        ->args([
            abstract_arg('translation loaders locator, set by RegisterTranslationLoadersPass'),
            param('php_spellcheck.translations.paths'),
            service('php_spellcheck.path_expander'),
            service('php_spellcheck.domain_filter'),
            service('php_spellcheck.locale_resolver'),
            service('php_spellcheck.translation_file_locator'),
            service('php_spellcheck.diagnostics'),
            param('php_spellcheck.translations.check_keys'),
            param('php_spellcheck.code.language'),
        ]);

    $services->set('php_spellcheck.source.php_factory', PhpSourceFactory::class)
        ->args([
            service('php_spellcheck.path_expander'),
            param('php_spellcheck.code.paths'),
            param('php_spellcheck.code.exclude'),
            param('php_spellcheck.code.check'),
            param('php_spellcheck.code.language'),
            param('php_spellcheck.code.max_file_size'),
            param('php_spellcheck.suppression_prefix'),
            param('kernel.project_dir'),
            service('php_spellcheck.diagnostics'),
            service('php_spellcheck.statistics'),
        ]);

    $services->set('php_spellcheck.source.php', \PHPSpellcheck\Core\Source\PhpFileSource::class)
        ->factory([service('php_spellcheck.source.php_factory'), 'create'])
        ->args([null, []]);

    // -------------------------------------------------------------- reporters

    $services->set('php_spellcheck.reporter.table', TableReporter::class)
        ->tag('php_spellcheck.reporter');
    $services->set('php_spellcheck.reporter.json', JsonReporter::class)
        ->tag('php_spellcheck.reporter');
    $services->set('php_spellcheck.reporter.github', GithubReporter::class)
        ->tag('php_spellcheck.reporter');
    $services->set('php_spellcheck.reporter.checkstyle', CheckstyleReporter::class)
        ->tag('php_spellcheck.reporter');
    $services->set('php_spellcheck.reporter.junit', JUnitReporter::class)
        ->tag('php_spellcheck.reporter');
    $services->set('php_spellcheck.reporter.gitlab', GitlabReporter::class)
        ->tag('php_spellcheck.reporter');
    $services->set('php_spellcheck.reporter.csv', CsvReporter::class)
        ->tag('php_spellcheck.reporter');

    $services->set('php_spellcheck.reporter_registry', ReporterRegistry::class)
        ->args([tagged_iterator('php_spellcheck.reporter')]);

    // --------------------------------------------------------------- commands

    $commandArguments = [
        service('php_spellcheck.runner'),
        service('php_spellcheck.reporter_registry'),
        service('php_spellcheck.baseline_storage'),
        service('php_spellcheck.run_configuration_factory'),
        service('php_spellcheck.diagnostics'),
        param('php_spellcheck.baseline.path'),
    ];

    $services->set('php_spellcheck.command.all', SpellcheckCommand::class)
        ->args(array_merge($commandArguments, [
            service('php_spellcheck.source.translations')->nullOnInvalid(),
            service('php_spellcheck.source.php')->nullOnInvalid(),
        ]))
        ->tag('console.command');

    $services->set('php_spellcheck.command.translations', SpellcheckTranslationsCommand::class)
        ->args(array_merge($commandArguments, [service('php_spellcheck.source.translations')]))
        ->tag('console.command');

    $services->set('php_spellcheck.command.code', SpellcheckCodeCommand::class)
        ->args(array_merge($commandArguments, [service('php_spellcheck.source.php_factory')]))
        ->tag('console.command');

    $services->set('php_spellcheck.command.baseline', SpellcheckBaselineCommand::class)
        ->args(array_merge($commandArguments, [
            service('php_spellcheck.source.translations')->nullOnInvalid(),
            service('php_spellcheck.source.php')->nullOnInvalid(),
        ]))
        ->tag('console.command');

    $services->set('php_spellcheck.command.doctor', SpellcheckDoctorCommand::class)
        ->args([
            service('php_spellcheck.speller'),
            service('php_spellcheck.dictionary'),
            service('php_spellcheck.locale_dictionary_map'),
            service('php_spellcheck.locale_resolver')->nullOnInvalid(),
            service('php_spellcheck.baseline_storage'),
            service('php_spellcheck.reporter_registry'),
            param('php_spellcheck.backend'),
            param('php_spellcheck.baseline.path'),
            param('php_spellcheck.dictionary_paths'),
        ])
        ->tag('console.command');

    $services->set('php_spellcheck.command.dictionary_add', SpellcheckDictionaryAddCommand::class)
        ->args([
            service('php_spellcheck.dictionary_loader'),
            param('php_spellcheck.dictionaries'),
        ])
        ->tag('console.command');

    $services->set('php_spellcheck.command.debug_fragments', SpellcheckDebugFragmentsCommand::class)
        ->args([
            service('php_spellcheck.processor_chain'),
            service('php_spellcheck.tokenizer_registry'),
            service('php_spellcheck.source.translations')->nullOnInvalid(),
            service('php_spellcheck.source.php')->nullOnInvalid(),
        ])
        ->tag('console.command');
};
