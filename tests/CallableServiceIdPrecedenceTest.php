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
}

final class CallablePrecedenceServiceTarget
{
    public function run(string $value): string
    {
        return 'service:' . $value;
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

    it('prefers a service owner over a native static method in array form', function () {
        $resolver = new CallableResolver(callablePrecedenceContainer([
            CallablePrecedenceNativeTarget::class => new CallablePrecedenceServiceTarget(),
        ]));

        $callable = $resolver->resolve([CallablePrecedenceNativeTarget::class, 'run']);

        expect($callable('x'))->toBe('service:x');
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
});
