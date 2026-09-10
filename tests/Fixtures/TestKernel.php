<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Tests\Fixtures;

use PHPSpellcheck\SpellcheckBundle\PHPSpellcheckBundle;
use Psr\Log\NullLogger;
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
        yield new PHPSpellcheckBundle();
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
                    'cache.php_spellcheck' => ['adapter' => 'cache.adapter.array'],
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

        $container->extension('php_spellcheck', array_replace_recursive([
            'backend' => 'wordlist',
            'builtin_dictionaries' => [],
            'dictionaries' => [__DIR__.'/dictionaries/test.txt'],
            'min_word_length' => 3,
            'cache' => ['pool' => 'cache.php_spellcheck'],
            'baseline' => ['path' => __DIR__.'/var/baseline.json'],
            'translations' => ['paths' => [__DIR__.'/translations']],
            'code' => ['paths' => [__DIR__.'/php'], 'language' => 'en'],
        ], $this->spellcheckConfig));

        $container->services()
            // Without Monolog, FrameworkBundle falls back to the HttpKernel
            // logger. A debug kernel makes Kernel::boot() set SHELL_VERBOSITY=3,
            // which lowers that logger to the debug level and prints every
            // record on stderr, polluting the test output.
            ->set('logger', NullLogger::class)
            ->alias('test.php_spellcheck.runner', 'php_spellcheck.runner')->public()
        ;

        if ($this->withTranslator) {
            $container->services()
                ->alias('test.php_spellcheck.source.translations', 'php_spellcheck.source.translations')->public()
                ->alias('test.php_spellcheck.locale_resolver', 'php_spellcheck.locale_resolver')->public()
            ;
        }
    }
}
