<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Architecture\CoreServiceProtectionContract;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\LazyObjectFactoryInterface;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Parameter\ParametersResolverInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\VirtualProxyFactoryInterface;
use Psr\Container\ContainerInterface;

test('rejected mutations preserve protected service identity or unavailability', function (string $id): void {
    $container = (new ContainerBuilder())->build();
    $container->set('replacement', 'application');
    // Protection must preserve existing state, including deliberately hidden IDs.
    // Availability itself is verified by the public bootstrap contract tests.
    $available = $container->has($id);
    $original = $available ? $container->get($id) : null;
    $decorations = 0;
    $decorator = static function (object $entry) use (&$decorations): object {
        ++$decorations;
        return new \stdClass();
    };

    $mutations = [
        'Cannot replace protected DI id' => fn() => $container->set($id, new \stdClass()),
        'Cannot replace protected DI alias' => fn() => $container->alias($id, 'replacement'),
        'Cannot decorate protected DI id' => fn() => $container->delegator($id, $decorator),
    ];

    foreach ($mutations as $message => $mutate) {
        expect($mutate)->toThrow(InvalidConfigurationException::class, $message)
            ->and($container->has($id))->toBe($available)
            ->and($container->get('replacement'))->toBe('application');
        if ($available) {
            expect($container->get($id))->toBe($original);
        } else {
            expect(fn() => $container->get($id))->toThrow(NotFoundException::class);
        }
        expect($decorations)->toBe(0);
    }
})->with([
    ConfigAttribute::KEY,
    Config::class,
    Environment::class,
    ContainerValue::class,
    DependencyDefinitions::class,
    Container::class,
    ContainerInterface::class,
    FactoryInterface::class,
    CallableInvokerInterface::class,
    CallableResolverInterface::class,
    CallableExecutorInterface::class,
    ProxyFactoryInterface::class,
    LazyObjectFactoryInterface::class,
    VirtualProxyFactoryInterface::class,
    AttributeDefinitionRegistry::class,
    AttributePlanBuilder::class,
    AttributeProcessor::class,
    RequestAttributeHandler::class,
    ParametersResolver::class,
    ParametersResolverInterface::class,
    ObjectPipeline::class,
]);
