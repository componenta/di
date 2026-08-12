<?php

declare(strict_types=1);

use Componenta\DI\CallableResolver;
use Componenta\DI\DelegatorRegistry;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Entry\FactoryResolver;
use Psr\Container\ContainerInterface;

final class CallablePrecedenceNativeTarget
{
    public static function run(string $value): string
    {
        return 'native:' . $value;
    }

    public static function factory(
        ContainerInterface $_container,
        array $_context = [],
    ): string {
        return 'native-factory';
    }
}

final class CallablePrecedenceServiceTarget
{
    public function run(string $value): string
    {
        return 'service:' . $value;
    }

    public function factory(
        ContainerInterface $_container,
        array $_context = [],
    ): string {
        return 'service-factory';
    }
}

function callablePrecedenceContainer(array $entries): ContainerInterface
{
    return new class ($entries) implements ContainerInterface {
        public function __construct(private readonly array $entries) {}

        public function get(string $id): mixed
        {
            if (!array_key_exists($id, $this->entries)) {
                throw new RuntimeException('missing ' . $id);
            }

            return $this->entries[$id];
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };
}

describe('opaque callable service-id precedence', function () {
    it('prefers a service id over a same-named native function', function () {
        $resolver = new CallableResolver(callablePrecedenceContainer([
            'strlen' => static fn(string $value): string => 'service:' . $value,
        ]));

        expect(($resolver->resolve('strlen'))('x'))->toBe('service:x');
    });

    it('prefers an opaque service id over a real Class::method string', function () {
        $id = CallablePrecedenceNativeTarget::class . '::run';
        $resolver = new CallableResolver(callablePrecedenceContainer([
            $id => static fn(string $value): string => 'opaque:' . $value,
        ]));

        expect(($resolver->resolve($id))('x'))->toBe('opaque:x');
    });

    it('preserves a native static array callable even when its owner is a container id', function () {
        $resolver = new CallableResolver(callablePrecedenceContainer([
            CallablePrecedenceNativeTarget::class => new CallablePrecedenceServiceTarget(),
        ]));

        $callable = $resolver->resolve([CallablePrecedenceNativeTarget::class, 'run']);

        expect($callable('x'))->toBe('native:x');
    });

    it('routes callable string delegators through the callable resolver', function () {
        $resolver = new CallableResolver(callablePrecedenceContainer([
            'strlen' => static fn(string $entry, ContainerInterface $_container): string => $entry . '-service',
        ]));
        $registry = new DelegatorRegistry($resolver);
        $container = callablePrecedenceContainer([]);
        $registry->register('entry', 'strlen');

        expect($registry->apply('entry', 'base', $container))->toBe('base-service');
    });

    it('does not track native static array delegators as deferred service references', function () {
        $registry = new DelegatorRegistry(new CallableResolver(callablePrecedenceContainer([
            CallablePrecedenceNativeTarget::class => new CallablePrecedenceServiceTarget(),
        ])));
        $registry->register('entry', [CallablePrecedenceNativeTarget::class, 'run']);

        expect($registry->invalidateDeferred())->toBe([]);
    });

    it('prefers a factory service id over a same-named native function', function () {
        $factory = static fn(ContainerInterface $_container): string => 'service-factory';
        $container = callablePrecedenceContainer(['strlen' => $factory]);
        $resolver = new FactoryResolver(
            ['entry' => 'strlen'],
            $container,
            new ProxyFactory(),
        );

        expect($resolver->resolve('entry'))->toBe('service-factory');
    });

    it('preserves a native static array factory even when its owner is a container id', function () {
        $container = callablePrecedenceContainer([
            CallablePrecedenceNativeTarget::class => new CallablePrecedenceServiceTarget(),
        ]);
        $resolver = new FactoryResolver(
            ['entry' => [CallablePrecedenceNativeTarget::class, 'factory']],
            $container,
            new ProxyFactory(),
        );

        expect($resolver->resolve('entry'))->toBe('native-factory');
    });
});
