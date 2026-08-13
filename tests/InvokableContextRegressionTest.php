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
    public function __construct(public ?InvokableContextDependency $dependency = null) {}
}

it('ignores make context for an explicit invokable', function (): void {
    $container = (new ContainerBuilder())
        ->addInvokable(InvokableContextNoConstructor::class)
        ->build();

    expect($container->make(
        InvokableContextNoConstructor::class,
        ['unrelated' => 'value'],
    ))->toBeInstanceOf(InvokableContextNoConstructor::class);
});

it('uses native defaults instead of make context', function (): void {
    $container = (new ContainerBuilder())
        ->addInvokable(InvokableContextOptionalArguments::class)
        ->build();

    $named = $container->make(InvokableContextOptionalArguments::class, ['second' => 'named']);
    $positional = $container->make(InvokableContextOptionalArguments::class, [0 => 'positional']);

    expect($named->first)->toBe('first-default')
        ->and($named->second)->toBe('second-default')
        ->and($positional->first)->toBe('first-default')
        ->and($positional->second)->toBe('second-default');
});

it('does not use type-key make context for an explicit invokable', function (): void {
    $dependency = new InvokableContextDependency();
    $container = (new ContainerBuilder())
        ->addInvokable(InvokableContextTypedArgument::class)
        ->build();

    $entry = $container->make(
        InvokableContextTypedArgument::class,
        [InvokableContextDependency::class => $dependency],
    );

    expect($entry->dependency)->toBeNull()
        ->and($container->get(InvokableContextTypedArgument::class)->dependency)->toBeNull();
});
