<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionClass;

test('Inject and Init are declared for properties only', function (): void {
    $inject = (new ReflectionClass(Inject::class))->getAttributes(Attribute::class)[0]->newInstance();
    $init = (new ReflectionClass(Init::class))->getAttributes(Attribute::class)[0]->newInstance();

    expect($inject->flags)->toBe(Attribute::TARGET_PROPERTY)
        ->and($init->flags)->toBe(Attribute::TARGET_PROPERTY);
});

test('property-only value attributes are rejected on constructor parameters', function (): void {
    $container = (new ContainerBuilder())->build();
    $injectTarget = __NAMESPACE__ . '\\InvalidParameterInjectTarget';
    $initTarget = __NAMESPACE__ . '\\InvalidParameterInitTarget';

    if (!class_exists($injectTarget, false)) {
        eval('namespace ' . __NAMESPACE__ . '; final class InvalidParameterInjectTarget { public function __construct(#[\\Componenta\\DI\\Attribute\\Inject] \\stdClass $value) {} }');
        eval('namespace ' . __NAMESPACE__ . '; final class InvalidParameterInitTarget { public function __construct(#[\\Componenta\\DI\\Attribute\\Init("time")] int $value) {} }');
    }

    expect(fn() => $container->make($injectTarget))
        ->toThrow(AttributeCompositionException::class, 'cannot target parameter')
        ->and(fn() => $container->make($initTarget))
        ->toThrow(AttributeCompositionException::class, 'cannot target parameter');
});
