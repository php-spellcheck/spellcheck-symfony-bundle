<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Tests\Unit;

use PHPSpellcheck\SpellcheckBundle\Path\PathExpander;
use PHPUnit\Framework\TestCase;

final class PathExpanderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/php-spellcheck-expander-'.bin2hex(random_bytes(6));

        foreach (['src/Blog/translations', 'src/Shop/translations', 'src/Shop/Sub/deep'] as $directory) {
            mkdir($this->root.'/'.$directory, 0o777, true);
        }

        foreach ([
            'src/Blog/translations/blog.it.yml',
            'src/Shop/translations/shop.it.yml',
            'src/Shop/Sub/deep/deep.it.yml',
            'src/Shop/notes.txt',
        ] as $file) {
            file_put_contents($this->root.'/'.$file, '');
        }
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->root);
    }

    public function testAPathWithoutAPatternIsReturnedUnchanged(): void
    {
        $expander = new PathExpander();

        self::assertSame(
            [$this->root.'/src', '/does/not/exist'],
            $expander->expand([$this->root.'/src', '/does/not/exist']),
        );
    }

    public function testSingleStarStopsAtTheSeparator(): void
    {
        $expander = new PathExpander();

        self::assertSame(
            [$this->root.'/src/Blog/translations', $this->root.'/src/Shop/translations'],
            $expander->expand([$this->root.'/src/*/translations']),
        );
    }

    public function testDoubleStarCrossesDirectories(): void
    {
        $expander = new PathExpander();

        self::assertSame(
            [
                $this->root.'/src/Blog/translations/blog.it.yml',
                $this->root.'/src/Shop/Sub/deep/deep.it.yml',
                $this->root.'/src/Shop/translations/shop.it.yml',
            ],
            $expander->expand([$this->root.'/src/**/*.yml']),
        );
    }

    public function testDoubleStarAlsoMatchesTheShallowestLevel(): void
    {
        file_put_contents($this->root.'/src/root.it.yml', '');

        $expander = new PathExpander();

        self::assertContains($this->root.'/src/root.it.yml', $expander->expand([$this->root.'/src/**/*.yml']));
    }

    public function testAPatternThatMatchesNothingIsDropped(): void
    {
        $expander = new PathExpander();

        self::assertSame([], $expander->expand([$this->root.'/src/**/*.xliff']));
        self::assertSame([], $expander->expand(['/does/not/exist/**/*.yml']));
    }

    public function testBracesAndCharacterClassesAreSupported(): void
    {
        $expander = new PathExpander();

        self::assertSame(
            [$this->root.'/src/Shop/notes.txt'],
            $expander->expand([$this->root.'/src/Shop/*.{txt,md}']),
        );
    }

    public function testMatchedFilesAreReducedToTheirDirectory(): void
    {
        $expander = new PathExpander();

        self::assertSame(
            [
                $this->root.'/src/Blog/translations',
                $this->root.'/src/Shop/Sub/deep',
                $this->root.'/src/Shop/translations',
            ],
            $expander->expandToDirectories([$this->root.'/src/**/*.yml']),
        );
    }

    public function testDuplicatesAreRemoved(): void
    {
        $expander = new PathExpander();

        self::assertSame(
            [$this->root.'/src/Blog/translations', $this->root.'/src/Shop/translations'],
            $expander->expand([$this->root.'/src/*/translations', $this->root.'/src/**/translations']),
        );
    }

    public function testIsPattern(): void
    {
        self::assertTrue(PathExpander::isPattern('src/**/*.yml'));
        self::assertTrue(PathExpander::isPattern('src/[ab]/x'));
        self::assertTrue(PathExpander::isPattern('src/{a,b}/x'));
        self::assertTrue(PathExpander::isPattern('src/?/x'));
        self::assertFalse(PathExpander::isPattern('%kernel.project_dir%/translations'));
    }
}
