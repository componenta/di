<?php

declare(strict_types=1);

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Tests\Fixture\ServiceWithParam;
use Componenta\DI\Tests\Fixture\SimpleService;
use Psr\Container\ContainerInterface;

describe('Definition', function () {
    it('returns a new class definition when constructor params are configured', function () {
        $definition = ClassDefinition::create(ServiceWithParam::class);

        $configured = $definition->constructor(['value' => 'configured']);

        expect($configured)->not->toBe($definition)
            ->and($definition->constructorParams)->toBe([])
            ->and($configured->constructorParams)->toBe(['value' => 'configured']);
    });

    it('returns a new class definition when a method call is configured', function () {
        $definition = ClassDefinition::create(SimpleService::class);

        $configured = $definition->method('boot', ['warmup']);

        expect($configured)->not->toBe($definition)
            ->and($definition->methodCalls)->toBe([])
            ->and($configured->methodCalls)->toBe([
                ['method' => 'boot', 'params' => ['warmup']],
            ]);
    });

    it('preserves repeated method calls instead of overwriting them', function () {
        $configured = ClassDefinition::create(SimpleService::class)
            ->method('boot', ['first'])
            ->method('boot', ['second']);

        expect($configured->methodCalls)->toBe([
            ['method' => 'boot', 'params' => ['first']],
            ['method' => 'boot', 'params' => ['second']],
        ]);
    });

    it('rejects empty class definition method names', function () {
        expect(fn() => ClassDefinition::create(SimpleService::class)->method(''))
            ->toThrow(InvalidConfigurationException::class);
    });

    it('keeps lazy factory objects intact inside factory definitions', function () {
        $factory = new class () implements LazyServiceFactoryInterface {
            public function __invoke(ContainerInterface $container): object
            {
                return new SimpleService();
            }

            public function lazy(ContainerInterface $container, ProxyFactoryInterface $proxyFactory, array $context = []): object
            {
                return new SimpleService();
            }
        };

        $definition = new FactoryDefinition($factory);

        expect($definition->value)->toBe($factory);
    });

    it('does not expose the misleading autowire definition helper', function () {
        expect(method_exists(\Componenta\DI\Definition\Definition::class, 'autowire'))->toBeFalse();
    });
});
