<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Makes the dependency on the translator optional.
 *
 * Registered as TYPE_BEFORE_REMOVING so that FrameworkBundle has already
 * decided whether the translator exists.
 */
final class TranslatorOptionalPass implements CompilerPassInterface
{
    private const TRANSLATOR_DEPENDENT = [
        'php_spellcheck.source.translator_catalogue',
        'php_spellcheck.locale_resolver',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('php_spellcheck.runner') && !$container->hasAlias('php_spellcheck.runner')) {
            return; // the bundle is disabled
        }

        if ($this->hasTranslator($container)) {
            $this->bindTranslator($container);

            return;
        }

        foreach (self::TRANSLATOR_DEPENDENT as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }

        foreach (['php_spellcheck.command.translations', 'php_spellcheck.source.translation_files'] as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }

        foreach (['php_spellcheck.source.translations', 'php_spellcheck.translator_bag'] as $alias) {
            if ($container->hasAlias($alias)) {
                $container->removeAlias($alias);
            }
        }

        $container->setParameter('php_spellcheck.translations.available', false);
    }

    private function hasTranslator(ContainerBuilder $container): bool
    {
        return $container->has('translator') || $container->has(TranslatorBagInterface::class);
    }

    /**
     * Points the internal alias at whichever id actually exists: the
     * autowiring alias is the more precise one, but FrameworkBundle only
     * registers it when the translator is enabled.
     */
    private function bindTranslator(ContainerBuilder $container): void
    {
        $id = $container->has(TranslatorBagInterface::class) ? TranslatorBagInterface::class : 'translator';

        $container->setAlias('php_spellcheck.translator_bag', $id);
    }
}
