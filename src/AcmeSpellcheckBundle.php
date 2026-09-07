<?php

declare(strict_types=1);

namespace PHPSpellcheck\SpellcheckBundle;

use PHPSpellcheck\SpellcheckBundle\DependencyInjection\Compiler\RegisterTranslationLoadersPass;
use PHPSpellcheck\SpellcheckBundle\DependencyInjection\Compiler\TranslatorOptionalPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Symfony 5.4 has no AbstractBundle (6.1+), so the classic triplet
 * Bundle + Extension + Configuration is used.
 */
final class AcmeSpellcheckBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterTranslationLoadersPass());

        // FrameworkBundle decides whether "translator" exists at extension-load
        // time, so this can run before optimization. It must run there: once the
        // OPTIMIZE group's ResolveReferencesToAliasesPass collapses alias chains,
        // any alias pointing through "acme_spellcheck.source.translations" gets
        // rewritten to point directly at the backing source service, and removing
        // that service afterwards (TYPE_BEFORE_REMOVING) would leave it dangling.
        $container->addCompilerPass(new TranslatorOptionalPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }

    /**
     * The default convention already resolves
     * PHPSpellcheck\SpellcheckBundle\DependencyInjection\AcmeSpellcheckExtension from
     * the bundle name, so getContainerExtension() is not overridden.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
