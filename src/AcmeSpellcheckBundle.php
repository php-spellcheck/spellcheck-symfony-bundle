<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle;

use Acme\SpellcheckBundle\DependencyInjection\Compiler\RegisterTranslationLoadersPass;
use Acme\SpellcheckBundle\DependencyInjection\Compiler\TranslatorOptionalPass;
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

        // Must run after FrameworkBundle has registered (or not) the
        // translator, and before unused services are removed.
        $container->addCompilerPass(new TranslatorOptionalPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    /**
     * The default convention already resolves
     * Acme\SpellcheckBundle\DependencyInjection\AcmeSpellcheckExtension from
     * the bundle name, so getContainerExtension() is not overridden.
     */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
