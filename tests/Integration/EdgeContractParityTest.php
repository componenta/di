<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Integration\EdgeContractParity;

use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\CallableResolver;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
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

/** @param array<string,mixed> $entries */
function callableParityContainer(array $entries): ContainerInterface
{
    return new class ($entries) implements ContainerInterface {
        /** @param array<string,mixed> $entries */
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
    $cause = new \RuntimeException('transient validation lookup');
    $external = new class ($validation, $cause) implements ContainerInterface {
        public bool $fail = true;
        public int $lookups = 0;

        public function __construct(
            private readonly ValidationProviderInterface $validation,
            private readonly \RuntimeException $failure,
        ) {}

        public function get(string $id): mixed
        {
            if ($id !== ValidationProviderInterface::class) {
                throw new \RuntimeException('missing ' . $id);
            }
            ++$this->lookups;
            if ($this->fail) {
                throw $this->failure;
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

    try {
        $container->call($callable, $params);
        throw new \LogicException('Expected the validation provider lookup to fail.');
    } catch (ResolutionException $exception) {
        expect($exception->getMessage())->toContain('transient validation lookup')
            ->and($exception->getPrevious())->toBe($cause);
    }
    expect($external->lookups)->toBe(1);

    $external->fail = false;

    expect($container->call($callable, $params))->toBe('ok')
        ->and($external->lookups)->toBe(2);
});
