<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\Factory;

use PHPSpellcheck\Core\Checker\RunStatisticsCollector;
use PHPSpellcheck\Core\Dictionary\DictionaryInterface;
use PHPSpellcheck\Core\Speller\CachingSpeller;
use PHPSpellcheck\Core\Speller\SpellerInterface;
use PHPSpellcheck\Core\Version;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The cache key must change whenever anything that could change a verdict
 * changes: backend, dictionary content, casing flags, tool version. Computing
 * it needs the dictionary at runtime, hence a factory rather than a plain
 * service definition.
 */
final class CachingSpellerFactory
{
    public static function create(
        SpellerInterface $inner,
        CacheItemPoolInterface $pool,
        DictionaryInterface $dictionary,
        bool $caseSensitive,
        bool $checkCase,
        int $maxSuggestions,
        int $ttl,
        RunStatisticsCollector $statistics,
    ): CachingSpeller {
        $hash = substr(hash('sha256', implode('|', [
            Version::string(),
            $inner->getName(),
            $dictionary->getVersionHash(),
            $caseSensitive ? '1' : '0',
            $checkCase ? '1' : '0',
            (string) $maxSuggestions,
        ])), 0, 16);

        return new CachingSpeller($inner, $pool, $hash, $ttl, $statistics);
    }
}
