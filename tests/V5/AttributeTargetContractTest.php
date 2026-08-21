<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use ReflectionClass;

final class InvalidParameterInjectTarget
{
    public function __construct(
        #[Inject]
        \stdClass $value,
    ) {
        unset($value);
    }
}

final class InvalidParameterInitTarget
{
    public function __construct(
        #[Init('time')]
        int $value,
    ) {
        unset($value);
    }
}

test('Inject and Init are declared for properties only', function (): void {
    $inject = (new ReflectionClass(Inject::class))->getAttributes(Attribute::class)[0]->newInstance();
    $init = (new ReflectionClass(Init::class))->getAttributes(Attribute::class)[0]->newInstance();

    expect($inject->flags)->toBe(Attribute::TARGET_PROPERTY)
        ->and($init->flags)->toBe(Attribute::TARGET_PROPERTY);
});

test('property-only value attributes are rejected on constructor parameters', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->make(InvalidParameterInjectTarget::class))
        ->toThrow(AttributeCompositionException::class, 'cannot target parameter')
        ->and(fn() => $container->make(InvalidParameterInitTarget::class))
        ->toThrow(AttributeCompositionException::class, 'cannot target parameter');
});
