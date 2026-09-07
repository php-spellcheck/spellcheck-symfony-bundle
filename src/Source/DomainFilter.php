<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Source;

/**
 * Include and exclude lists for translation domains, with glob support.
 *
 * CLI and configuration combine by intersection: a domain excluded in the
 * configuration cannot be re-enabled from the command line without --force-domain.
 */
final class DomainFilter
{
    /**
     * @param list<string> $include
     * @param list<string> $exclude
     */
    public function __construct(
        private readonly array $include = [],
        private readonly array $exclude = [],
    ) {
    }

    /**
     * @param list<string> $only
     */
    public function restrictTo(array $only, bool $force = false): self
    {
        if ([] === $only) {
            return $this;
        }

        $include = [] === $this->include ? $only : array_values(array_intersect($this->include, $only));

        return new self($include, $force ? [] : $this->exclude);
    }

    public function accepts(string $domain): bool
    {
        foreach ($this->exclude as $pattern) {
            if ($this->matches($pattern, $domain)) {
                return false;
            }
        }

        if ([] === $this->include) {
            return true;
        }

        foreach ($this->include as $pattern) {
            if ($this->matches($pattern, $domain)) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $pattern, string $domain): bool
    {
        if (!str_contains($pattern, '*') && !str_contains($pattern, '?')) {
            return $pattern === $domain;
        }

        return fnmatch($pattern, $domain);
    }
}
