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
    $runtimeMethods = [
        AttributePlanBuilder::class => ['refreshAvailability', 'refreshLoaded', 'hasSameLoadedDeclarations'],
        AttributeProcessor::class => ['process', 'processTracked', 'metadataState', 'recordBootstrapUse'],
        CompositeResolver::class => ['attach', 'tryResolveShared', 'knows'],
        ObjectCreationContext::class => ['__construct', 'startAttempt', 'runPhase', 'assertParametersReadable', 'resolveProperty'],
        ParametersResolver::class => ['prepare', 'prepareTargets', 'resolvePrepared', 'resolveTracked', 'isCurrentPlan'],
    ];

    foreach ($runtimeMethods as $class => $methods) {
        expect(array_intersect($methods, get_class_methods($class)))->toBe([], $class);
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
