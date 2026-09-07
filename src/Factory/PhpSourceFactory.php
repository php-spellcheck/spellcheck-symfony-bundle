<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Factory;

use PHPSpellcheck\Core\Checker\RunStatisticsCollector;
use PHPSpellcheck\Core\Diagnostics\DiagnosticCollector;
use PHPSpellcheck\Core\Php\IdentifierKind;
use PHPSpellcheck\Core\Source\PhpFileSource;

/**
 * Builds PhpFileSource from the flat configuration parameters, converting the
 * "check" strings into IdentifierKind cases.
 */
final class PhpSourceFactory
{
    /**
     * @param list<string> $paths
     * @param list<string> $exclude
     * @param list<string> $kinds
     */
    public function __construct(
        private readonly array $paths,
        private readonly array $exclude,
        private readonly array $kinds,
        private readonly string $language,
        private readonly string $maxFileSize,
        private readonly string $suppressionPrefix,
        private readonly string $projectDir,
        private readonly DiagnosticCollector $diagnostics,
        private readonly RunStatisticsCollector $statistics,
    ) {
    }

    /**
     * @param list<string>|null $paths overrides the configured paths
     * @param list<string>      $kinds overrides the configured categories
     */
    public function create(?array $paths = null, array $kinds = []): PhpFileSource
    {
        return new PhpFileSource(
            $this->absolute($paths ?? $this->paths),
            $this->exclude,
            IdentifierKind::fromNames([] !== $kinds ? $kinds : $this->kinds),
            $this->language,
            $this->maxFileSize,
            $this->suppressionPrefix,
            $this->projectDir,
            $this->diagnostics,
            $this->statistics,
        );
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function absolute(array $paths): array
    {
        $resolved = [];

        foreach ($paths as $path) {
            if ('' === $path) {
                continue;
            }

            $resolved[] = str_starts_with($path, \DIRECTORY_SEPARATOR)
                ? $path
                : rtrim($this->projectDir, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.ltrim($path, \DIRECTORY_SEPARATOR);
        }

        return $resolved;
    }
}
