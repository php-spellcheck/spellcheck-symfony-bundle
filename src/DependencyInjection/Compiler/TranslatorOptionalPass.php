<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\DependencyInjection\Compiler;

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
        'acme_spellcheck.source.translator_catalogue',
        'acme_spellcheck.locale_resolver',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('acme_spellcheck.runner') && !$container->hasAlias('acme_spellcheck.runner')) {
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

        foreach (['acme_spellcheck.command.translations', 'acme_spellcheck.source.translation_files'] as $id) {
            if ($container->hasDefinition($id)) {
                $container->removeDefinition($id);
            }
        }

        foreach (['acme_spellcheck.source.translations', 'acme_spellcheck.translator_bag'] as $alias) {
            if ($container->hasAlias($alias)) {
                $container->removeAlias($alias);
            }
        }

        $container->setParameter('acme_spellcheck.translations.available', false);
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

        $container->setAlias('acme_spellcheck.translator_bag', $id);
    }
}
