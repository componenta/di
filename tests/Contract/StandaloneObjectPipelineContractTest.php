<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\StandaloneObjectPipeline;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Internal\Resolver\Entry\ObjectResolutionParameterStore;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use LogicException;

final class Product
{
    /** @var list<string> */
    public array $events = [];

    public function __construct()
    {
        $this->events[] = 'constructor';
    }
}

test('standalone object pipelines construct and configure objects without optional bootstrap services', function (): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->seal();
    $plans = new AttributePlanBuilder($registry);
    $parameters = new ParametersResolver($plans);
    $parameters->seal();
    $pipeline = new ObjectPipeline(
        $plans,
        $parameters,
        new ProxyFactory(),
        $registry,
        new ObjectResolutionParameterStore(),
    );

    foreach ([1, 2] as $_attempt) {
        $entry = $pipeline->create(Product::class, configure: static function (object $entry): void {
            if (!$entry instanceof Product) {
                throw new LogicException('Expected the standalone pipeline product.');
            }
            $entry->events[] = 'configure';
        });
        if (!$entry instanceof Product) {
            throw new LogicException('Expected the configured pipeline product.');
        }

        expect($entry)->toBeInstanceOf(Product::class)
            ->and($entry->events)->toBe(['constructor', 'configure']);
    }
});
