<?php

declare(strict_types=1);

use Componenta\DI\Definition\Definition;
use Componenta\DI\Definition\InvokableDefinition;
use Componenta\DI\NullContainer;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Entry\InvokableResolver;

final class DefinitionContractConfiguredInvokable {}
final class DefinitionContractOverrideInvokable {}

test('factory definitions configure the same state as constructor configuration', function (): void {
    $resolver = new FactoryResolver(
        [
            'configured' => static fn() => (object) ['source' => 'configured'],
            'definition' => Definition::factory(
                static fn() => (object) ['source' => 'declarative-definition'],
            ),
        ],
        new NullContainer(),
        new ProxyFactory(),
    );

    expect($resolver->resolve('configured')->source)->toBe('configured')
        ->and($resolver->resolve('definition')->source)->toBe('declarative-definition');

    $resolver->setDefinition(
        'configured',
        Definition::factory(static fn() => (object) ['source' => 'reconfigured']),
    );

    expect($resolver->resolve('configured')->source)->toBe('reconfigured');
});

test('invokable definitions configure the same state as invokable class shorthand', function (): void {
    $configured = DefinitionContractConfiguredInvokable::class;
    $resolver = new InvokableResolver([
        $configured,
        new InvokableDefinition(DefinitionContractOverrideInvokable::class),
    ]);

    expect($resolver->resolve($configured))->toBeInstanceOf(DefinitionContractConfiguredInvokable::class)
        ->and($resolver->resolve(DefinitionContractOverrideInvokable::class))
        ->toBeInstanceOf(DefinitionContractOverrideInvokable::class);

    $resolver->setDefinition(
        $configured,
        Definition::invokable(DefinitionContractOverrideInvokable::class),
    );

    expect($resolver->resolve($configured))->toBeInstanceOf(DefinitionContractOverrideInvokable::class);
});
