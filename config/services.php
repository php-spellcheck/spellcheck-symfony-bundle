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

    $services->set('acme_spellcheck.diagnostics', DiagnosticCollector::class);
    $services->set('acme_spellcheck.statistics', RunStatisticsCollector::class);

    // --------------------------------------------------------- dictionaries

    $services->set('acme_spellcheck.dictionary_loader', DictionaryLoader::class)
        ->args([param('acme_spellcheck.case_sensitive')]);

    $services->set('acme_spellcheck.dictionary', AggregateDictionary::class)
        ->factory([service('acme_spellcheck.dictionary_loader'), 'loadAll'])
        ->args([param('acme_spellcheck.dictionary_paths')]);

    $services->set('acme_spellcheck.locale_dictionary_map', LocaleDictionaryMap::class)
        ->args([param('acme_spellcheck.locale_map'), []]);

    // ------------------------------------------------------------- tokenizer

    $services->set('acme_spellcheck.tokenizer.identifier', IdentifierSplitter::class)
        ->args([param('acme_spellcheck.min_word_length'), param('acme_spellcheck.ignore_patterns')])
        ->tag('acme_spellcheck.tokenizer');

    $services->set('acme_spellcheck.tokenizer.prose', ProseTokenizer::class)
        ->args([param('acme_spellcheck.min_word_length')])
        ->tag('acme_spellcheck.tokenizer');

    $services->set('acme_spellcheck.tokenizer_registry', TokenizerRegistry::class)
        ->args([tagged_iterator('acme_spellcheck.tokenizer')]);

    // ------------------------------------------------------------ processors

    $services->set('acme_spellcheck.icu_parser', IcuMessageParser::class);

    $services->set('acme_spellcheck.processor.normalize_apostrophe', NormalizeApostropheProcessor::class)
        ->tag('acme_spellcheck.processor');

    $services->set('acme_spellcheck.processor.legacy_plural', LegacyPluralProcessor::class)
        ->tag('acme_spellcheck.processor');

    $services->set('acme_spellcheck.processor.icu', IcuMessageProcessor::class)
        ->args([
            service('acme_spellcheck.icu_parser'),
            param('acme_spellcheck.translations.icu_mode'),
            service('acme_spellcheck.diagnostics'),
        ])
        ->tag('acme_spellcheck.processor');

    $services->set('acme_spellcheck.processor.placeholder', PlaceholderProcessor::class)
        ->tag('acme_spellcheck.processor');

    $services->set('acme_spellcheck.processor.sprintf', SprintfProcessor::class)
        ->tag('acme_spellcheck.processor');

    $services->set('acme_spellcheck.processor.html', HtmlProcessor::class)
        ->tag('acme_spellcheck.processor');

    $services->set('acme_spellcheck.processor.markdown', MarkdownProcessor::class)
        ->tag('acme_spellcheck.processor');

    $services->set('acme_spellcheck.processor.url', UrlProcessor::class)
        ->tag('acme_spellcheck.processor');

    $services->set('acme_spellcheck.processor.docblock', DocBlockProcessor::class)
        ->args([param('acme_spellcheck.code.strict_docblock_tags')])
        ->tag('acme_spellcheck.processor');

    $services->set('acme_spellcheck.processor_chain', ProcessorChain::class)
        ->args([tagged_iterator('acme_spellcheck.processor')]);

    // --------------------------------------------------------------- backends

    $services->set('acme_spellcheck.speller.hunspell', HunspellSpeller::class)
        ->args([
            param('acme_spellcheck.backend_options.hunspell_binary'),
            param('acme_spellcheck.backend_options.extra_dictionaries'),
            param('acme_spellcheck.backend_options.read_timeout'),
            param('acme_spellcheck.backend_options.terse_mode'),
            null,
        ]);

    $services->set('acme_spellcheck.speller.aspell', AspellSpeller::class)
        ->args([
            param('acme_spellcheck.backend_options.aspell_binary'),
            param('acme_spellcheck.backend_options.read_timeout'),
            param('acme_spellcheck.backend_options.terse_mode'),
            null,
        ]);

    $services->set('acme_spellcheck.speller.pspell', PspellSpeller::class)
        ->args([0, param('acme_spellcheck.max_suggestions')]);

    $services->set('acme_spellcheck.speller.wordlist', WordListSpeller::class)
        ->args([service('acme_spellcheck.dictionary'), param('acme_spellcheck.max_suggestions')]);

    $services->set('acme_spellcheck.speller.null', NullSpeller::class);

    // Only used when backend = auto; the extension removes it otherwise.
    $services->set('acme_spellcheck.speller.chain', ChainSpeller::class)
        ->args([
            [
                service('acme_spellcheck.speller.hunspell'),
                service('acme_spellcheck.speller.aspell'),
                service('acme_spellcheck.speller.pspell'),
                service('acme_spellcheck.speller.wordlist'),
            ],
            service('acme_spellcheck.diagnostics'),
        ]);

    // The extension aliases this to the selected backend.
    $services->alias('acme_spellcheck.speller.selected', 'acme_spellcheck.speller.chain');

    $services->set('acme_spellcheck.speller.caching', \PHPSpellcheck\Core\Speller\CachingSpeller::class)
        ->factory([CachingSpellerFactory::class, 'create'])
        ->args([
            service('acme_spellcheck.speller.selected'),
            service('cache.app'), // replaced by the extension with the configured pool
            service('acme_spellcheck.dictionary'),
            param('acme_spellcheck.case_sensitive'),
            param('acme_spellcheck.check_case'),
            param('acme_spellcheck.max_suggestions'),
            param('acme_spellcheck.cache.ttl'),
            service('acme_spellcheck.statistics'),
        ]);

    $services->alias('acme_spellcheck.speller', 'acme_spellcheck.speller.caching');

    // ---------------------------------------------------------------- filters

    $services->set('acme_spellcheck.filter.dictionary', DictionaryFilter::class)
        ->args([service('acme_spellcheck.dictionary')])
        ->tag('acme_spellcheck.filter');

    $services->set('acme_spellcheck.filter.pattern', PatternFilter::class)
        ->args([param('acme_spellcheck.ignore_patterns')])
        ->tag('acme_spellcheck.filter');

    $services->set('acme_spellcheck.filter.deduplication', DeduplicationFilter::class)
        ->tag('acme_spellcheck.filter');

    $services->set('acme_spellcheck.filter.baseline', BaselineFilter::class)
        ->args([service('acme_spellcheck.statistics')])
        ->tag('acme_spellcheck.filter');

    $services->set('acme_spellcheck.filter_chain', FilterChain::class)
        ->args([tagged_iterator('acme_spellcheck.filter')]);

    // --------------------------------------------------------------- baseline

    $services->set('acme_spellcheck.baseline_storage', BaselineStorage::class);

    // --------------------------------------------------------------- locators

    $services->set('acme_spellcheck.locator.yaml', YamlKeyLocator::class)
        ->tag('acme_spellcheck.key_locator');
    $services->set('acme_spellcheck.locator.xliff', XliffKeyLocator::class)
        ->tag('acme_spellcheck.key_locator');
    $services->set('acme_spellcheck.locator.php_array', PhpArrayKeyLocator::class)
        ->tag('acme_spellcheck.key_locator');

    $services->set('acme_spellcheck.translation_file_locator', TranslationFileLocator::class)
        ->args([
            param('acme_spellcheck.translations.paths'),
            tagged_iterator('acme_spellcheck.key_locator'),
            param('kernel.project_dir'),
        ]);

    // ---------------------------------------------------------------- runner

    $services->set('acme_spellcheck.suggestion_formatter', SuggestionFormatter::class);

    $services->set('acme_spellcheck.misspelling_factory', MisspellingFactory::class)
        ->args([
            service('acme_spellcheck.suggestion_formatter'),
            param('acme_spellcheck.max_suggestions'),
        ]);

    $services->set('acme_spellcheck.runner', SpellcheckRunner::class)
        ->args([
            service('acme_spellcheck.processor_chain'),
            service('acme_spellcheck.tokenizer_registry'),
            service('acme_spellcheck.speller'),
            service('acme_spellcheck.filter_chain'),
            service('acme_spellcheck.misspelling_factory'),
            service('acme_spellcheck.diagnostics'),
            service('acme_spellcheck.statistics'),
            service('acme_spellcheck.filter.baseline'),
            service('logger')->nullOnInvalid(),
        ]);

    $services->set('acme_spellcheck.run_configuration_factory', RunConfigurationFactory::class)
        ->args([
            service('acme_spellcheck.locale_dictionary_map'),
            service('acme_spellcheck.speller'),
            service('acme_spellcheck.diagnostics'),
            param('acme_spellcheck.max_suggestions'),
            param('acme_spellcheck.baseline.enabled'),
            param('acme_spellcheck.cache.enabled'),
            param('acme_spellcheck.translations.excluded_languages'),
            param('acme_spellcheck.profiles'),
        ]);

    // --------------------------------------------------------------- sources

    $services->set('acme_spellcheck.domain_filter', DomainFilter::class)
        ->args([
            param('acme_spellcheck.translations.domains'),
            param('acme_spellcheck.translations.exclude_domains'),
        ]);

    // Placeholder reference: TranslatorOptionalPass swaps it for the real
    // translator service id, which differs depending on whether the autowiring
    // alias exists.
    $services->alias('acme_spellcheck.translator_bag', 'translator')->private();

    $services->set('acme_spellcheck.locale_resolver', \PHPSpellcheck\SpellcheckBundle\Locale\LocaleResolver::class)
        ->args([
            service('acme_spellcheck.translator_bag'),
            param('acme_spellcheck.translations.locales'),
            param('kernel.enabled_locales'),
            service('acme_spellcheck.diagnostics'),
            param('kernel.default_locale'),
        ]);

    $services->set('acme_spellcheck.source.translator_catalogue', TranslatorCatalogueSource::class)
        ->args([
            service('acme_spellcheck.translator_bag'),
            service('acme_spellcheck.locale_resolver'),
            service('acme_spellcheck.domain_filter'),
            service('acme_spellcheck.translation_file_locator'),
            service('acme_spellcheck.diagnostics'),
            param('acme_spellcheck.translations.include_fallbacks'),
            param('acme_spellcheck.translations.check_keys'),
            param('acme_spellcheck.code.language'),
        ]);

    $services->set('acme_spellcheck.source.translation_files', TranslationFilesSource::class)
        ->args([
            abstract_arg('translation loaders locator, set by RegisterTranslationLoadersPass'),
            param('acme_spellcheck.translations.paths'),
            service('acme_spellcheck.domain_filter'),
            service('acme_spellcheck.locale_resolver'),
            service('acme_spellcheck.translation_file_locator'),
            service('acme_spellcheck.diagnostics'),
            param('acme_spellcheck.translations.check_keys'),
            param('acme_spellcheck.code.language'),
        ]);

    $services->set('acme_spellcheck.source.php_factory', PhpSourceFactory::class)
        ->args([
            param('acme_spellcheck.code.paths'),
            param('acme_spellcheck.code.exclude'),
            param('acme_spellcheck.code.check'),
            param('acme_spellcheck.code.language'),
            param('acme_spellcheck.code.max_file_size'),
            param('acme_spellcheck.suppression_prefix'),
            param('kernel.project_dir'),
            service('acme_spellcheck.diagnostics'),
            service('acme_spellcheck.statistics'),
        ]);

    $services->set('acme_spellcheck.source.php', \PHPSpellcheck\Core\Source\PhpFileSource::class)
        ->factory([service('acme_spellcheck.source.php_factory'), 'create'])
        ->args([null, []]);

    // -------------------------------------------------------------- reporters

    $services->set('acme_spellcheck.reporter.table', TableReporter::class)
        ->tag('acme_spellcheck.reporter');
    $services->set('acme_spellcheck.reporter.json', JsonReporter::class)
        ->tag('acme_spellcheck.reporter');
    $services->set('acme_spellcheck.reporter.github', GithubReporter::class)
        ->tag('acme_spellcheck.reporter');
    $services->set('acme_spellcheck.reporter.checkstyle', CheckstyleReporter::class)
        ->tag('acme_spellcheck.reporter');
    $services->set('acme_spellcheck.reporter.junit', JUnitReporter::class)
        ->tag('acme_spellcheck.reporter');
    $services->set('acme_spellcheck.reporter.gitlab', GitlabReporter::class)
        ->tag('acme_spellcheck.reporter');
    $services->set('acme_spellcheck.reporter.csv', CsvReporter::class)
        ->tag('acme_spellcheck.reporter');

    $services->set('acme_spellcheck.reporter_registry', ReporterRegistry::class)
        ->args([tagged_iterator('acme_spellcheck.reporter')]);

    // --------------------------------------------------------------- commands

    $commandArguments = [
        service('acme_spellcheck.runner'),
        service('acme_spellcheck.reporter_registry'),
        service('acme_spellcheck.baseline_storage'),
        service('acme_spellcheck.run_configuration_factory'),
        service('acme_spellcheck.diagnostics'),
        param('acme_spellcheck.baseline.path'),
    ];

    $services->set('acme_spellcheck.command.all', SpellcheckCommand::class)
        ->args(array_merge($commandArguments, [
            service('acme_spellcheck.source.translations')->nullOnInvalid(),
            service('acme_spellcheck.source.php')->nullOnInvalid(),
        ]))
        ->tag('console.command');

    $services->set('acme_spellcheck.command.translations', SpellcheckTranslationsCommand::class)
        ->args(array_merge($commandArguments, [service('acme_spellcheck.source.translations')]))
        ->tag('console.command');

    $services->set('acme_spellcheck.command.code', SpellcheckCodeCommand::class)
        ->args(array_merge($commandArguments, [service('acme_spellcheck.source.php_factory')]))
        ->tag('console.command');

    $services->set('acme_spellcheck.command.baseline', SpellcheckBaselineCommand::class)
        ->args(array_merge($commandArguments, [
            service('acme_spellcheck.source.translations')->nullOnInvalid(),
            service('acme_spellcheck.source.php')->nullOnInvalid(),
        ]))
        ->tag('console.command');

    $services->set('acme_spellcheck.command.doctor', SpellcheckDoctorCommand::class)
        ->args([
            service('acme_spellcheck.speller'),
            service('acme_spellcheck.dictionary'),
            service('acme_spellcheck.locale_dictionary_map'),
            service('acme_spellcheck.locale_resolver')->nullOnInvalid(),
            service('acme_spellcheck.baseline_storage'),
            service('acme_spellcheck.reporter_registry'),
            param('acme_spellcheck.backend'),
            param('acme_spellcheck.baseline.path'),
            param('acme_spellcheck.dictionary_paths'),
        ])
        ->tag('console.command');

    $services->set('acme_spellcheck.command.dictionary_add', SpellcheckDictionaryAddCommand::class)
        ->args([
            service('acme_spellcheck.dictionary_loader'),
            param('acme_spellcheck.dictionaries'),
        ])
        ->tag('console.command');

    $services->set('acme_spellcheck.command.debug_fragments', SpellcheckDebugFragmentsCommand::class)
        ->args([
            service('acme_spellcheck.processor_chain'),
            service('acme_spellcheck.tokenizer_registry'),
            service('acme_spellcheck.source.translations')->nullOnInvalid(),
            service('acme_spellcheck.source.php')->nullOnInvalid(),
        ])
        ->tag('console.command');
};
