<?php

declare(strict_types=1);

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Definition\DefinitionInterface;

final readonly class StoredDefinitionService implements DefinitionInterface {}

test('addService stores DefinitionInterface objects as values', function () {
    $service = new StoredDefinitionService();
    $container = (new ContainerBuilder())
        ->addService('stored.definition', $service)
        ->build();

    expect($container->get('stored.definition'))->toBe($service);
});
