<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Architecture\RuntimeFacadeContract;

use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecutionAccess;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParametersResolver;

test('standalone facades expose application operations without publishing attached runtime controls', function (): void {
    $publicMethods = [
        AttributePlanBuilder::class => ['__construct', 'build'],
        AttributeProcessor::class => ['__construct', 'hasHandlers', 'prepare'],
        CompositeResolver::class => ['__construct', 'can', 'resolve', 'setDefinition'],
        ObjectCreationContext::class => [
            'claimProperty', 'disableConstructor', 'propertyClaimed', 'readProperty', 'selectStrategy', 'writeProperty',
        ],
        ParametersResolver::class => [
            '__construct', 'add', 'resolve', 'resolveParameter', 'resolveTargets', 'seal', 'target', 'targets',
        ],
    ];

    foreach ($publicMethods as $class => $expected) {
        $actual = get_class_methods($class);
        sort($actual, SORT_STRING);

        expect($actual)->toBe($expected, $class);
    }
});

test('cooperating argument executors cannot register or seal the resolver chain', function (): void {
    $registry = new \Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry();
    $parameters = new ParametersResolver(new AttributePlanBuilder($registry));
    $execution = ParameterExecutionAccess::for($parameters);

    expect(array_intersect(['add', 'seal'], get_class_methods($execution)))->toBe([]);
    $parameters->seal();
    expect($execution->isSealed)->toBeTrue();
});
