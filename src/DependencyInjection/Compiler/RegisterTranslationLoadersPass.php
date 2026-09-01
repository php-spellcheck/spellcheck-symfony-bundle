<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Builds a service locator of the translation loaders, indexed by their format
 * alias, and injects it into the file based translation source.
 *
 * #[AsTaggedItem] is 6.1+, so the tag attributes are read by hand.
 */
final class RegisterTranslationLoadersPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('acme_spellcheck.source.translation_files')) {
            return;
        }

        $references = [];

        foreach ($container->findTaggedServiceIds('translation.loader', true) as $id => $tags) {
            foreach ($tags as $tag) {
                if (!isset($tag['alias'])) {
                    continue;
                }

                $references[(string) $tag['alias']] = new Reference($id);

                if (isset($tag['legacy-alias'])) {
                    $references[(string) $tag['legacy-alias']] = new Reference($id);
                }
            }
        }

        $container->getDefinition('acme_spellcheck.source.translation_files')
            ->replaceArgument(0, ServiceLocatorTagPass::register($container, $references));
    }
}
