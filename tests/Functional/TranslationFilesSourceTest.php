<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\Tests\Functional;

use Acme\Spellcheck\Model\TextFragment;
use Acme\SpellcheckBundle\Source\TranslationFilesSource;
use Acme\SpellcheckBundle\Tests\Fixtures\TestKernel;
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

    /**
     * @return list<TextFragment>
     */
    private function fragments(): array
    {
        $kernel = new TestKernel();
        $kernel->boot();

        /** @var TranslationFilesSource $source */
        $source = $kernel->getContainer()->get('test.acme_spellcheck.source.translations');

        return array_values(iterator_to_array($source->fragments(), false));
    }
}
