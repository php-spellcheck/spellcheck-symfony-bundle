<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Tests\Fixtures;

use PHPSpellcheck\SpellcheckBundle\AcmeSpellcheckBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $spellcheckConfig
     */
    public function __construct(
        string $environment = 'test',
        bool $debug = true,
        private readonly array $spellcheckConfig = [],
        private readonly bool $withTranslator = true,
    ) {
        parent::__construct($environment, $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new AcmeSpellcheckBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return __DIR__.'/var/cache/'.$this->environment.'/'.substr(md5(serialize([$this->spellcheckConfig, $this->withTranslator])), 0, 8);
    }

    public function getLogDir(): string
    {
        return __DIR__.'/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'test' => true,
            'secret' => 'test',
            // Must be set explicitly: omitting it triggers a deprecation on
            // Symfony 5.4/6.x, and the test suite runs with max[total]=0.
            'http_method_override' => false,
            'php_errors' => ['log' => true],
            'cache' => [
                'pools' => [
                    'cache.acme_spellcheck' => ['adapter' => 'cache.adapter.array'],
                ],
            ],
        ];

        if ($this->withTranslator) {
            $framework['default_locale'] = 'it';
            $framework['enabled_locales'] = ['it', 'en'];
            $framework['translator'] = [
                'default_path' => __DIR__.'/translations',
                'fallbacks' => ['en'],
            ];
        } else {
            $framework['translator'] = ['enabled' => false];
        }

        $container->extension('framework', $framework);

        $container->extension('acme_spellcheck', array_replace_recursive([
            'backend' => 'wordlist',
            'builtin_dictionaries' => [],
            'dictionaries' => [__DIR__.'/dictionaries/test.txt'],
            'min_word_length' => 3,
            'cache' => ['pool' => 'cache.acme_spellcheck'],
            'baseline' => ['path' => __DIR__.'/var/baseline.json'],
            'translations' => ['paths' => [__DIR__.'/translations']],
            'code' => ['paths' => [__DIR__.'/php'], 'language' => 'en'],
        ], $this->spellcheckConfig));

        $container->services()
            ->alias('test.acme_spellcheck.runner', 'acme_spellcheck.runner')->public()
        ;

        if ($this->withTranslator) {
            $container->services()
                ->alias('test.acme_spellcheck.source.translations', 'acme_spellcheck.source.translations')->public()
                ->alias('test.acme_spellcheck.locale_resolver', 'acme_spellcheck.locale_resolver')->public()
            ;
        }
    }
}
