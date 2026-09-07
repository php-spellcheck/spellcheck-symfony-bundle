<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Factory;

use PHPSpellcheck\Core\Locator\KeyLocatorInterface;
use PHPSpellcheck\Core\Locator\TranslationFileLocator;
use PHPSpellcheck\SpellcheckBundle\Path\PathExpander;

/**
 * Builds the core locator with the glob patterns of the configuration already
 * resolved: the locator scans directories, so a pattern would never match.
 */
final class TranslationFileLocatorFactory
{
    public function __construct(private readonly PathExpander $pathExpander)
    {
    }

    /**
     * @param list<string>                  $paths
     * @param iterable<KeyLocatorInterface> $locators
     */
    public function create(array $paths, iterable $locators = [], ?string $projectDir = null): TranslationFileLocator
    {
        return new TranslationFileLocator(
            $this->pathExpander->expandToDirectories($paths),
            $locators,
            $projectDir,
        );
    }
}
