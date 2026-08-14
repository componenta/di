<?php

declare(strict_types=1);

use Componenta\DI\Definition\Definition;
use Componenta\DI\NullContainer;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Componenta\DI\Resolver\Entry\InvokableResolver;

final class RuntimeDefinitionContractInvokable {}

test('factory removal ignores configured bindings and removes runtime definitions', function (): void {
    $resolver = new FactoryResolver(
        ['configured' => static fn() => new stdClass()],
        new NullContainer(),
        new ProxyFactory(),
    );

    $resolver->removeDefinition('configured');
    expect($resolver->can('configured'))->toBeTrue();

    $resolver->setDefinition(
        'runtime',
        Definition::factory(static fn() => new stdClass()),
    );
    expect($resolver->can('runtime'))->toBeTrue();

    $resolver->removeDefinition('runtime');
    expect($resolver->can('runtime'))->toBeFalse();
});

test('invokable removal ignores configured bindings and removes runtime definitions', function (): void {
    $resolver = new InvokableResolver([RuntimeDefinitionContractInvokable::class]);

    $resolver->removeDefinition(RuntimeDefinitionContractInvokable::class);
    expect($resolver->can(RuntimeDefinitionContractInvokable::class))->toBeTrue();

    $resolver->setDefinition(
        'runtime',
        Definition::invokable(RuntimeDefinitionContractInvokable::class),
    );
    expect($resolver->can('runtime'))->toBeTrue();

    $resolver->removeDefinition('runtime');
    expect($resolver->can('runtime'))->toBeFalse();
});

test('removed runtime definition helper types are not part of the package', function (): void {
    expect(class_exists('Componenta\\DI\\RuntimeDefinitionRegistry'))->toBeFalse()
        ->and(interface_exists('Componenta\\DI\\Resolver\\Entry\\DefinitionRemovalInterface'))
        ->toBeFalse();
});
