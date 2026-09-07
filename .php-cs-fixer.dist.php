<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__.'/packages/spellcheck/src', __DIR__.'/packages/spellcheck/tests'])
    ->in([__DIR__.'/packages/spellcheck-bundle/src', __DIR__.'/packages/spellcheck-bundle/tests'])
    ->append([__DIR__.'/packages/spellcheck-bundle/config/services.php'])
    // Deliberately broken fixture.
    ->notPath('Fixtures/php/BrokenSyntax.php.txt')
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHP81Migration' => true,
        'declare_strict_types' => true,
        'yoda_style' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'phpdoc_to_comment' => false,
    ])
    ->setFinder($finder)
;
