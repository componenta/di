<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuditMappedFactoryDto
{
    public function __construct(public string $value) {}
}

final readonly class AuditMappedFactoryEnvelope
{
    public function __construct(
        #[MapRequestPayload]
        public AuditMappedFactoryDto $dto,
    ) {}
}

final class AuditCurrentUserProvider implements CurrentUserProviderInterface
{
    private ?object $user = null;

    public function getUser(): ?object
    {
        return $this->user;
    }

    public function setUser(?object $user): void
    {
        $this->user = $user;
    }
}

final class AuditLazyFactory implements LazyServiceFactoryInterface
{
    public ?ContainerInterface $seenContainer = null;

    public function lazy(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        array $context = [],
    ): object {
        $this->seenContainer = $container;
        return new \stdClass();
    }

    public function __invoke(ContainerInterface $container): object
    {
        throw new \RuntimeException('Lazy factory must use lazy().');
    }
}

test('mapped request provenance stays internal when a DTO is created by a user factory', function (): void {
    /** @var array<string|int,mixed>|null $captured */
    $captured = null;

    $container = (new ContainerBuilder())
        ->addFactory(
            AuditMappedFactoryDto::class,
            static function (ContainerValue $_container, array $params) use (&$captured): AuditMappedFactoryDto {
                $captured = $params;
                return new AuditMappedFactoryDto((string) ($params['value'] ?? ''));
            },
        )
        ->build();

    $request = (new ServerRequest('POST', '/'))
        ->withParsedBody(['value' => 'payload']);

    $envelope = $container->make(AuditMappedFactoryEnvelope::class, [
        ServerRequestInterface::class => $request,
    ]);

    expect($envelope->dto->value)->toBe('payload')
        ->and($captured)->not->toBeNull();

    $internalKeys = array_values(array_filter(
        array_keys($captured ?? []),
        static fn(int|string $key): bool => is_string($key)
            && str_starts_with($key, "\0componenta.di."),
    ));

    expect($internalKeys)->toBe([]);
});

test('an aliased current user provider service is not replaced by the default provider', function (): void {
    $provider = new AuditCurrentUserProvider();
    $container = (new ContainerBuilder())
        ->addAlias('audit.current-user-provider', CurrentUserProviderInterface::class)
        ->addService('audit.current-user-provider', $provider)
        ->build();

    expect($container->get(CurrentUserProviderInterface::class))->toBe($provider);
});

test('lazy service factories use the current ContainerValue runtime ABI', function (): void {
    $factory = new AuditLazyFactory();
    $container = (new ContainerBuilder())
        ->addFactory('audit.lazy', $factory)
        ->build();

    expect($container->make('audit.lazy'))->toBeInstanceOf(\stdClass::class)
        ->and($factory->seenContainer)->toBeInstanceOf(ContainerValue::class)
        ->and($factory->seenContainer?->value)->toBe($container);
});
