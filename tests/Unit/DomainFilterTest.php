<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Tests\Unit;

use PHPSpellcheck\SpellcheckBundle\Source\DomainFilter;
use PHPUnit\Framework\TestCase;

final class DomainFilterTest extends TestCase
{
    public function testEmptyFilterAcceptsEverything(): void
    {
        $filter = new DomainFilter();

        self::assertTrue($filter->accepts('messages'));
        self::assertTrue($filter->accepts('validators'));
    }

    public function testIncludeList(): void
    {
        $filter = new DomainFilter(['messages', 'admin']);

        self::assertTrue($filter->accepts('messages'));
        self::assertFalse($filter->accepts('validators'));
    }

    public function testExcludeListWins(): void
    {
        $filter = new DomainFilter(['messages'], ['messages']);

        self::assertFalse($filter->accepts('messages'));
    }

    public function testGlobsAreSupported(): void
    {
        $filter = new DomainFilter([], ['admin_*']);

        self::assertFalse($filter->accepts('admin_users'));
        self::assertTrue($filter->accepts('admin'));
    }

    public function testCliRestrictionIntersectsWithConfiguration(): void
    {
        $filter = (new DomainFilter(['messages', 'admin']))->restrictTo(['admin', 'security']);

        self::assertTrue($filter->accepts('admin'));
        self::assertFalse($filter->accepts('messages'));
        self::assertFalse($filter->accepts('security'), 'the CLI cannot widen the configured include list');
    }

    public function testAnExcludedDomainNeedsForceToBeCheckedAgain(): void
    {
        $configured = new DomainFilter([], ['validators']);

        self::assertFalse($configured->restrictTo(['validators'])->accepts('validators'));
        self::assertTrue($configured->restrictTo(['validators'], true)->accepts('validators'));
    }
}
