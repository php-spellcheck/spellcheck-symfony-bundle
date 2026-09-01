<?php

declare(strict_types=1);

namespace Acme\SpellcheckBundle\Tests\Fixtures\Php;

/**
 * Fixture with a deliberate typo in the class name.
 */
final class OrderSuscriber
{
    public function onEvent(): void
    {
    }
}
