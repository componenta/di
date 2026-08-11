<?php

declare(strict_types=1);

use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;

it('round-trips a valid compiled factory definition', function (): void {
    $definition = new CompiledFactoryDefinition(
        'container.factories.php',
        'Componenta\\DI\\Generated\\Shard',
        'createEntry',
    );

    expect(CompiledFactoryDefinition::decode($definition->encode()))
        ->toEqual($definition);
});

it('rejects a malformed class name in an encoded definition', function (): void {
    $definition = new CompiledFactoryDefinition(
        'container.factories.php',
        'Componenta\\DI\\Generated\\Shard',
        'createEntry',
    );
    $encoded = str_replace(
        'Componenta\\DI\\Generated\\Shard',
        'Componenta\\DI\\Generated;exit',
        $definition->encode(),
    );

    expect(CompiledFactoryDefinition::decode($encoded))->toBeNull();
});
