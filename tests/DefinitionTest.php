<?php

declare(strict_types=1);

use Componenta\DI\Definition\Definition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Tests\Fixture\ServiceWithParam;
use Componenta\DI\Tests\Fixture\SimpleService;
use Psr\Container\ContainerInterface;

describe('Definition', function () {
    it('returns a new class definition when constructor params are configured', function () {
        $definition = Definition::autowire(ServiceWithParam::class);

        $configured = $definition->constructor(['value' => 'configured']);

        expect($configured)->not->toBe($definition)
            ->and($definition->constructorParams)->toBe([])
            ->and($configured->constructorParams)->toBe(['value' => 'configured']);
    });

    it('returns a new class definition when a method call is configured', function () {
        $definition = Definition::autowire(SimpleService::class);

        $configured = $definition->method('boot', ['warmup']);

        expect($configured)->not->toBe($definition)
            ->and($definition->methodCalls)->toBe([])
            ->and($configured->methodCalls)->toBe(['boot' => ['warmup']]);
    });

    it('keeps lazy factory objects intact inside factory definitions', function () {
        $factory = new class () implements LazyServiceFactoryInterface {
            public function __invoke(ContainerInterface $container): object
            {
                return new SimpleService();
            }

            public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory): object
            {
                return new SimpleService();
            }
        };

        $definition = new FactoryDefinition($factory);

        expect($definition->value)->toBe($factory);
    });
});
