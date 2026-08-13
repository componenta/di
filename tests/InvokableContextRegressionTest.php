<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;

final class InvokableContextNoConstructor {}

final class InvokableContextOptionalArguments
{
    public function __construct(
        public string $first = 'first-default',
        public string $second = 'second-default',
    ) {}
}

final class InvokableContextDependency {}

final class InvokableContextTypedArgument
{
    public function __construct(
        public ?InvokableContextDependency $dependency = null,
    ) {}
}

it('ignores unrelated make context for invokables without constructors', function (): void {
    $container = (new ContainerBuilder())
        ->addInvokable(InvokableContextNoConstructor::class)
        ->build();

    expect($container->make(
        InvokableContextNoConstructor::class,
        ['unrelated' => 'value'],
    ))->toBeInstanceOf(InvokableContextNoConstructor::class);
});

it('resolves invokable constructor overrides by name and position while preserving defaults', function (): void {
    $container = (new ContainerBuilder())
        ->addInvokable(InvokableContextOptionalArguments::class)
        ->build();

    $named = $container->make(
        InvokableContextOptionalArguments::class,
        ['second' => 'named'],
    );
    $positional = $container->make(
        InvokableContextOptionalArguments::class,
        [0 => 'positional'],
    );

    expect($named->first)->toBe('first-default')
        ->and($named->second)->toBe('named')
        ->and($positional->first)->toBe('positional')
        ->and($positional->second)->toBe('second-default');
});

it('accepts explicit invokable object overrides keyed by declared type', function (): void {
    $dependency = new InvokableContextDependency();
    $container = (new ContainerBuilder())
        ->addInvokable(InvokableContextTypedArgument::class)
        ->build();

    expect($container->make(
        InvokableContextTypedArgument::class,
        [InvokableContextDependency::class => $dependency],
    )->dependency)->toBe($dependency)
        ->and($container->get(InvokableContextTypedArgument::class)->dependency)->toBeNull();
});
