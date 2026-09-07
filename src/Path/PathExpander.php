<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Path;

use Symfony\Component\Finder\Glob;

/**
 * Resolves the glob patterns that may appear in the configured paths.
 *
 * The syntax is the Finder one: a single "*" stops at the directory
 * separator, "**" crosses it, so "%kernel.project_dir%/src/**\/*.yml" matches
 * every YAML file under src, at any depth.
 *
 * Patterns are matched against the file system on every call, so a file added
 * after the container was built is still picked up; the result is memoised per
 * instance because a single run asks for the same paths several times.
 */
final class PathExpander
{
    private const MAGIC = ['*', '?', '[', '{'];

    /** @var array<string, list<string>> */
    private array $cache = [];

    /**
     * @param list<string> $paths
     *
     * @return list<string> the paths without a pattern, unchanged, plus every
     *                      file and directory the patterns match
     */
    public function expand(array $paths): array
    {
        $resolved = [];

        foreach ($paths as $path) {
            if ('' === $path) {
                continue;
            }

            if (!self::isPattern($path)) {
                $resolved[] = $path;

                continue;
            }

            foreach ($this->match($path) as $match) {
                $resolved[] = $match;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * Same as expand(), except that a matched file is replaced by the
     * directory containing it: consumers that only scan directories would
     * drop it otherwise.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    public function expandToDirectories(array $paths): array
    {
        $directories = [];

        foreach ($this->expand($paths) as $path) {
            $directories[] = is_file($path) ? \dirname($path) : $path;
        }

        return array_values(array_unique($directories));
    }

    public static function isPattern(string $path): bool
    {
        foreach (self::MAGIC as $character) {
            if (str_contains($path, $character)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function match(string $pattern): array
    {
        if (isset($this->cache[$pattern])) {
            return $this->cache[$pattern];
        }

        $normalized = str_replace('\\', '/', $pattern);
        $base = self::staticPrefix($normalized);

        if (!is_dir($base)) {
            return $this->cache[$pattern] = [];
        }

        // Hidden files are matched as well: a translations directory may live
        // under a dot directory, and the pattern already says what is wanted.
        $regex = Glob::toRegex($normalized, false, true);
        $matches = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $base,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS,
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        /** @var \SplFileInfo $candidate */
        foreach ($iterator as $candidate) {
            if (1 === preg_match($regex, str_replace('\\', '/', $candidate->getPathname()))) {
                $matches[] = $candidate->getPathname();
            }
        }

        sort($matches);

        return $this->cache[$pattern] = $matches;
    }

    /**
     * The longest leading part of the pattern without a magic character: the
     * directory the file system walk starts from.
     */
    private static function staticPrefix(string $pattern): string
    {
        $prefix = [];

        foreach (explode('/', $pattern) as $segment) {
            if (self::isPattern($segment)) {
                break;
            }

            $prefix[] = $segment;
        }

        $base = implode('/', $prefix);

        if ('' === $base) {
            // Either a relative pattern such as "*/translations", or an
            // absolute one whose first segment is already magic.
            return str_starts_with($pattern, '/') ? '/' : '.';
        }

        return rtrim($base, '/');
    }
}
