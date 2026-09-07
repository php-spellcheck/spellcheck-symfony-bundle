<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Tests\DependencyInjection;

use Matthias\SymfonyConfigTest\PhpUnit\ConfigurationTestCaseTrait;
use PHPSpellcheck\SpellcheckBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class ConfigurationTest extends TestCase
{
    use ConfigurationTestCaseTrait;

    protected function getConfiguration(): ConfigurationInterface
    {
        return new Configuration();
    }

    public function testDefaults(): void
    {
        $this->assertProcessedConfigurationEquals([[]], [
            'enabled' => true,
            'backend' => 'auto',
            'backend_options' => [
                'hunspell_binary' => 'hunspell',
                'aspell_binary' => 'aspell',
                'read_timeout' => 10.0,
                'terse_mode' => true,
                'extra_dictionaries' => [],
            ],
            'locale_map' => [],
            'dictionaries' => [],
            'builtin_dictionaries' => ['technical', 'php', 'symfony'],
            'case_sensitive' => false,
            'check_case' => false,
            'max_suggestions' => 3,
            'min_word_length' => 4,
            'ignore_patterns' => [],
            'suppression_prefix' => '@spellcheck',
            'baseline' => [
                'enabled' => true,
                'path' => '%kernel.project_dir%/.spellcheck/baseline.json',
            ],
            'cache' => [
                'enabled' => true,
                'pool' => 'cache.app',
                'ttl' => 0,
            ],
            'translations' => [
                'enabled' => true,
                'source' => 'files',
                'paths' => ['%kernel.project_dir%/translations'],
                'locales' => [],
                'domains' => [],
                'exclude_domains' => [],
                'include_fallbacks' => false,
                'check_keys' => false,
                'check_notes' => false,
                'icu_mode' => 'auto',
                'excluded_languages' => ['ja', 'zh', 'ko', 'th'],
            ],
            'code' => [
                'enabled' => true,
                'language' => 'en_US',
                'paths' => ['%kernel.project_dir%/src'],
                'exclude' => ['vendor', 'var', 'tests/Fixtures'],
                'check' => ['class_like', 'method', 'function', 'property', 'parameter', 'constant', 'docblock', 'comment'],
                'max_file_size' => '2M',
                'strict_docblock_tags' => true,
            ],
            'profiles' => [],
        ]);
    }

    public function testUnknownBackendIsRejected(): void
    {
        $this->assertConfigurationIsInvalid([['backend' => 'languagetool']], 'backend');
    }

    public function testUnknownNodeTypeIsRejected(): void
    {
        $this->assertConfigurationIsInvalid([['code' => ['check' => ['attribute']]]]);
    }

    public function testInvalidIgnorePatternIsRejected(): void
    {
        $this->assertConfigurationIsInvalid(
            [['ignore_patterns' => ['not a regex']]],
            'not a valid regular expression',
        );
    }

    public function testMissingDictionaryFileIsRejected(): void
    {
        $this->assertConfigurationIsInvalid(
            [['dictionaries' => ['/definitely/missing/words.txt']]],
            'does not exist',
        );
    }

    public function testDictionaryPathWithParameterIsAccepted(): void
    {
        $this->assertConfigurationIsValid([['dictionaries' => ['%kernel.project_dir%/.spellcheck/project.txt']]]);
    }

    public function testMinWordLengthLowerBound(): void
    {
        $this->assertConfigurationIsInvalid([['min_word_length' => 1]]);
    }

    public function testTranslatorModeGetsDefensiveDomainExclusions(): void
    {
        $this->assertProcessedConfigurationEquals(
            [['translations' => ['source' => 'translator']]],
            ['translations' => $this->translationsDefaults(['source' => 'translator', 'exclude_domains' => ['validators', 'security']])],
            'translations',
        );
    }

    public function testExplicitDomainExclusionsAreNotOverridden(): void
    {
        $this->assertProcessedConfigurationEquals(
            [['translations' => ['source' => 'translator', 'exclude_domains' => ['admin_*']]]],
            ['translations' => $this->translationsDefaults(['source' => 'translator', 'exclude_domains' => ['admin_*']])],
            'translations',
        );
    }

    public function testFilesModeKeepsEmptyExclusions(): void
    {
        $this->assertProcessedConfigurationEquals(
            [['translations' => ['source' => 'files']]],
            ['translations' => $this->translationsDefaults()],
            'translations',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function translationsDefaults(array $overrides = []): array
    {
        return array_replace([
            'enabled' => true,
            'source' => 'files',
            'paths' => ['%kernel.project_dir%/translations'],
            'locales' => [],
            'domains' => [],
            'exclude_domains' => [],
            'include_fallbacks' => false,
            'check_keys' => false,
            'check_notes' => false,
            'icu_mode' => 'auto',
            'excluded_languages' => ['ja', 'zh', 'ko', 'th'],
        ], $overrides);
    }

    public function testProfilesAreArbitrary(): void
    {
        $this->assertProcessedConfigurationEquals(
            [['profiles' => ['ci' => ['max_suggestions' => 0]]]],
            ['profiles' => ['ci' => ['max_suggestions' => 0]]],
            'profiles',
        );
    }
}
