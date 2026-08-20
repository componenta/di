<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\CurrentUser;
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

final class InvalidPropertyCurrentUserTarget
{
    #[CurrentUser]
    private ?\stdClass $user = null;
}

test('Inject is declared for properties only', function (): void {
    $metadata = (new ReflectionClass(Inject::class))->getAttributes(Attribute::class)[0]->newInstance();

    expect($metadata->flags)->toBe(Attribute::TARGET_PROPERTY);
});

test('Inject on a constructor parameter is rejected by attribute composition', function (): void {
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->make(InvalidParameterInjectTarget::class))
        ->toThrow(AttributeCompositionException::class, 'cannot target parameter');
});

test('CurrentUser is declared for parameters only', function (): void {
    $metadata = (new ReflectionClass(CurrentUser::class))->getAttributes(Attribute::class)[0]->newInstance();

    expect($metadata->flags)->toBe(Attribute::TARGET_PARAMETER);
});

test('CurrentUser cannot be instantiated for a property target', function (): void {
    $attribute = (new ReflectionClass(InvalidPropertyCurrentUserTarget::class))
        ->getProperty('user')
        ->getAttributes(CurrentUser::class)[0];

    expect(fn() => $attribute->newInstance())
        ->toThrow(\Error::class, 'cannot target property');
});
