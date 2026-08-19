<?php

declare(strict_types=1);

use Componenta\DI\FactoryInterface;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;

it('keeps the final v5 extension boundaries narrow', function (): void {
    expect(interface_exists(ParameterResolverInterface::class))->toBeTrue()
        ->and(interface_exists(AttributeHandlerInterface::class))->toBeTrue()
        ->and((new ReflectionMethod(FactoryInterface::class, 'make'))->getParameters()[1]->getType()?->getName())
        ->toBe('array')
        ->and((new ReflectionClass(CallableExecutorInterface::class))->hasMethod('execute'))
        ->toBeFalse();
});
