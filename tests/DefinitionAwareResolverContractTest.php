<?php

declare(strict_types=1);

use Componenta\DI\Definition\Definition;
use Componenta\DI\NullContainer;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Entry\InvokableResolver;

final class RuntimeDefinitionContractConfiguredInvokable {}
final class RuntimeDefinitionContractOverrideInvokable {}

test('factory runtime definitions overlay configured bindings and removal restores them', function (): void {
    $resolver = new FactoryResolver(
        ['configured' => static fn() => (object) ['source' => 'configured']],
        new NullContainer(),
        new ProxyFactory(),
    );

    $resolver->removeDefinition('configured');
    expect($resolver->resolve('configured')->source)->toBe('configured');

    $resolver->setDefinition(
        'configured',
        Definition::factory(static fn() => (object) ['source' => 'runtime']),
    );
    expect($resolver->resolve('configured')->source)->toBe('runtime');

    $resolver->removeDefinition('configured');
    expect($resolver->resolve('configured')->source)->toBe('configured');

    $resolver->setDefinition(
        'runtime-only',
        Definition::factory(static fn() => new stdClass()),
    );
    $resolver->removeDefinition('runtime-only');
    expect($resolver->can('runtime-only'))->toBeFalse();
});

test('invokable runtime definitions overlay configured bindings and removal restores them', function (): void {
    $id = RuntimeDefinitionContractConfiguredInvokable::class;
    $resolver = new InvokableResolver([$id]);

    $resolver->removeDefinition($id);
    expect($resolver->resolve($id))->toBeInstanceOf(RuntimeDefinitionContractConfiguredInvokable::class);

    $resolver->setDefinition(
        $id,
        Definition::invokable(RuntimeDefinitionContractOverrideInvokable::class),
    );
    expect($resolver->resolve($id))->toBeInstanceOf(RuntimeDefinitionContractOverrideInvokable::class);

    $resolver->removeDefinition($id);
    expect($resolver->resolve($id))->toBeInstanceOf(RuntimeDefinitionContractConfiguredInvokable::class);

    $resolver->setDefinition(
        'runtime-only',
        Definition::invokable(RuntimeDefinitionContractOverrideInvokable::class),
    );
    $resolver->removeDefinition('runtime-only');
    expect($resolver->can('runtime-only'))->toBeFalse();
});

test('removed runtime definition helper types are not part of the package', function (): void {
    expect(class_exists('Componenta\\DI\\RuntimeDefinitionRegistry'))->toBeFalse()
        ->and(interface_exists('Componenta\\DI\\Resolver\\Entry\\DefinitionRemovalInterface'))
        ->toBeFalse();
});
