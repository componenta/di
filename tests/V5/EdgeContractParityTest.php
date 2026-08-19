<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\CallableResolver;
use Componenta\DI\Resolver\Parameter\Request\LazyValidationProvider;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;

final class NativeCallableParityTarget
{
    public static function run(string $value): string
    {
        return 'native:' . $value;
    }
}

final class ServiceCallableParityTarget
{
    public function run(string $value): string
    {
        return 'service:' . $value;
    }
}

function callableParityContainer(array $entries): ContainerInterface
{
    return new class ($entries) implements ContainerInterface {
        public function __construct(private readonly array $entries) {}

        public function get(string $id): mixed
        {
            if (!array_key_exists($id, $this->entries)) {
                throw new \RuntimeException('missing ' . $id);
            }

            return $this->entries[$id];
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };
}

test('opaque callable service ids win over same-named native functions', function (): void {
    $resolver = new CallableResolver(callableParityContainer([
        'strlen' => static fn(string $value): string => 'service:' . $value,
    ]));

    expect(($resolver->resolve('strlen'))('x'))->toBe('service:x');
});

test('native static array callables keep PHP precedence over a same-named container owner id', function (): void {
    $resolver = new CallableResolver(callableParityContainer([
        NativeCallableParityTarget::class => new ServiceCallableParityTarget(),
    ]));

    expect(($resolver->resolve([NativeCallableParityTarget::class, 'run']))('x'))
        ->toBe('native:x');
});

test('LazyValidationProvider retries after a transient lookup failure', function (): void {
    $validation = new class () implements ValidationProviderInterface {
        public function provide(string $entryId): ?ValidatorInterface
        {
            return null;
        }
    };
    $container = new class ($validation) implements ContainerInterface {
        public bool $fail = true;

        public function __construct(private readonly ValidationProviderInterface $validation) {}

        public function get(string $id): mixed
        {
            if ($this->fail) {
                throw new \RuntimeException('transient');
            }

            return $this->validation;
        }

        public function has(string $id): bool
        {
            return $id === ValidationProviderInterface::class;
        }
    };
    $provider = new LazyValidationProvider($container);

    expect(fn() => $provider->provide('FirstDto'))
        ->toThrow(\RuntimeException::class, 'transient');

    $container->fail = false;

    expect($provider->provide('SecondDto'))->toBeNull()
        ->and($provider->provide('ThirdDto'))->toBeNull();
});

test('ServerParam preserves an explicitly present null instead of using its missing default', function (): void {
    $request = new ServerRequest(
        method: 'GET',
        uri: '/',
        serverParams: ['nullable' => null],
    );

    expect((new ServerParam('nullable', default: 'fallback'))->extract($request))->toBeNull();
});
