<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\DefinitionInterface;

final class StoredDefinitionService implements DefinitionInterface
{
    public mixed $value {
        get => 'stored';
    }
}

test('addService stores DefinitionInterface objects as values', function () {
    $service = new StoredDefinitionService();
    $container = (new ContainerBuilder())
        ->addService('stored.definition', $service)
        ->build();

    expect($container->get('stored.definition'))->toBe($service);
});
