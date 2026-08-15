<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Init;
use Componenta\DI\CallableInvoker;
use Componenta\DI\Resolver\Attribute\Handler\InitHandler;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;

final class DirectPromotedInitTarget
{
    public function __construct(public string $value = 'constructor') {}
}

test('InitHandler can claim a mutable promoted property explicitly', function () {
    $reflection = new ReflectionClass(DirectPromotedInitTarget::class);
    $property = $reflection->getProperty('value');
    $entry = new DirectPromotedInitTarget();
    $context = new ObjectCreationContext($reflection);
    $context->initialize($entry);

    (new InitHandler(new CallableInvoker()))->handle(
        new Init('strtoupper', ['initialized']),
        $property,
        $context,
    );

    expect($entry->value)->toBe('INITIALIZED');
});
