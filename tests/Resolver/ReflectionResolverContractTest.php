<?php

declare(strict_types=1);

use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\AttributeHandlerRegistry;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\ReflectionResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolver;

it('throws NotFoundException for ids the reflection resolver does not own', function () {
    $resolver = new ReflectionResolver(
        new InstanceCreator(new ParametersResolver()),
        new AttributeProcessor(new AttributeHandlerRegistry()),
        new ProxyFactory(),
    );

    expect(fn() => $resolver->resolve('componenta.audit.missing'))
        ->toThrow(NotFoundException::class)
        ->and(fn() => $resolver->resolve(DateTimeInterface::class))
        ->toThrow(NotFoundException::class);
});
