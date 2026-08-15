<?php

declare(strict_types=1);

require_once __DIR__ . '/Fixture/container_helpers.php';

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;

final readonly class NestedReferenceDependency {}

final readonly class NestedReferenceConsumer
{
    /** @param array<string, array<string, object>> $dependencies */
    public function __construct(public array $dependencies) {}
}

it('resolves reference definitions recursively inside constructor arrays', function (): void {
    $dependency = new NestedReferenceDependency();
    $container = minimalBuilder()
        ->addService(NestedReferenceDependency::class, $dependency)
        ->build();

    $container->set(
        NestedReferenceConsumer::class,
        ClassDefinition::create(NestedReferenceConsumer::class)
            ->constructor([
                'dependencies' => [
                    'primary' => [
                        'service' => Definition::reference(NestedReferenceDependency::class),
                    ],
                ],
            ]),
    );

    $consumer = $container->get(NestedReferenceConsumer::class);

    expect($consumer->dependencies['primary']['service'])->toBe($dependency);
});
