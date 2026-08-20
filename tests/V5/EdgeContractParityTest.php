<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\CallableResolver;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

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

final readonly class TransientValidationDto
{
    public function __construct(public string $value) {}
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

test('request validation retries a transient provider lookup failure on the next resolution', function (): void {
    $validation = new class () implements ValidationProviderInterface {
        public function provide(string $entryId): ?ValidatorInterface
        {
            return null;
        }
    };
    $external = new class ($validation) implements ContainerInterface {
        public bool $fail = true;

        public function __construct(private readonly ValidationProviderInterface $validation) {}

        public function get(string $id): mixed
        {
            if ($id !== ValidationProviderInterface::class) {
                throw new \RuntimeException('missing ' . $id);
            }
            if ($this->fail) {
                throw new \RuntimeException('transient validation lookup');
            }

            return $this->validation;
        }

        public function has(string $id): bool
        {
            return $id === ValidationProviderInterface::class;
        }
    };
    $container = (new ContainerBuilder())->build();
    $container->addContainer($external);
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'ok']);
    $callable = static fn(#[MapRequestPayload] TransientValidationDto $dto): string => $dto->value;
    $params = [ServerRequestInterface::class => $request];

    expect(fn() => $container->call($callable, $params))
        ->toThrow(ResolutionException::class, 'transient validation lookup');

    $external->fail = false;

    expect($container->call($callable, $params))->toBe('ok');
});

test('ServerParam preserves an explicitly present null instead of using its missing default', function (): void {
    $request = new ServerRequest(
        method: 'GET',
        uri: '/',
        serverParams: ['nullable' => null],
    );

    expect((new ServerParam('nullable', default: 'fallback'))->extract($request))->toBeNull();
});
