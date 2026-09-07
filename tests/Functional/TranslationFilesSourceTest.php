<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Tests\Functional;

use PHPSpellcheck\Core\Model\TextFragment;
use PHPSpellcheck\SpellcheckBundle\Source\TranslationFilesSource;
use PHPSpellcheck\SpellcheckBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;

final class TranslationFilesSourceTest extends TestCase
{
    public function testEveryMessageIsEmittedWithItsFileAndLine(): void
    {
        $fragments = $this->fragments();

        self::assertNotEmpty($fragments);

        $byKey = [];
        foreach ($fragments as $fragment) {
            $byKey[$fragment->context->get('key') ?? ''] = $fragment;
        }

        $address = $byKey['checkout.shipping.address'] ?? null;

        self::assertNotNull($address);
        self::assertSame('it', $address->context->get('locale'));
        self::assertSame('messages', $address->context->get('domain'));
        self::assertStringEndsWith('messages.it.yaml', (string) $address->location?->path);
        self::assertNotNull($address->location?->line, 'the YAML locator must resolve a nested key to a line');
    }

    public function testDottedDomainIsParsedCorrectly(): void
    {
        $domains = [];

        foreach ($this->fragments() as $fragment) {
            $domains[$fragment->context->get('domain') ?? ''] = true;
        }

        self::assertArrayHasKey('admin.forms', $domains, 'a domain containing a dot must not be mistaken for a locale');
    }

    public function testOnlyTheEnabledLocalesAreEmitted(): void
    {
        $locales = [];

        foreach ($this->fragments() as $fragment) {
            $locales[$fragment->language] = true;
        }

        self::assertSame(['it', 'en'], array_keys($locales));
    }

    public function testDirectoryGlobPatternIsExpanded(): void
    {
        $domains = [];

        foreach ($this->fragments(['paths' => ['%kernel.project_dir%/modules/*/translations']]) as $fragment) {
            $domains[$fragment->context->get('domain') ?? ''] = true;
        }

        self::assertSame(['blog', 'shop'], array_keys($domains));
    }

    public function testRecursiveGlobPatternMatchesFilesAtAnyDepth(): void
    {
        $paths = [];

        foreach ($this->fragments(['paths' => ['%kernel.project_dir%/modules/**/*.yaml']]) as $fragment) {
            $paths[basename((string) $fragment->location?->path)] = true;
        }

        self::assertSame(['blog.it.yaml', 'shop.it.yaml'], array_keys($paths));
    }

    public function testAFileAlreadyCoveredByADirectoryIsNotReadTwice(): void
    {
        $fragments = $this->fragments([
            'paths' => [
                '%kernel.project_dir%/modules/blog/translations',
                '%kernel.project_dir%/modules/**/*.yaml',
            ],
        ]);

        $titles = array_filter(
            $fragments,
            static fn ($fragment): bool => 'post.title' === $fragment->context->get('key'),
        );

        self::assertCount(1, $titles);
    }

    /**
     * @param array<string, mixed> $translations
     *
     * @return list<TextFragment>
     */
    private function fragments(array $translations = []): array
    {
        $kernel = new TestKernel('test', true, [] === $translations ? [] : ['translations' => $translations]);
        $kernel->boot();

        /** @var TranslationFilesSource $source */
        $source = $kernel->getContainer()->get('test.php_spellcheck.source.translations');

        return array_values(iterator_to_array($source->fragments(), false));
    }
}
