<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Nyholm\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Reflector;

final readonly class AuditParameterContextDto
{
    public function __construct(public string $value) {}
}

final class AuditParameterContextResolver implements ParameterResolverInterface
{
    /** @var list<string|int> */
    public array $keys = [];

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $this->keys = array_keys($context->provided);
        return null;
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AuditObjectContextAttribute {}

#[AuditObjectContextAttribute]
final readonly class AuditObjectContextDto
{
    public function __construct(public string $value) {}
}

final class AuditObjectContextHandler implements AttributeHandlerInterface
{
    /** @var list<string|int> */
    public array $keys = [];

    public function handle(
        object $attribute,
        Reflector $target,
        ObjectCreationContext $context,
    ): void {
        $this->keys = array_keys($context->parameters);
    }
}

final readonly class AuditEntryContextDto
{
    public function __construct(public string $value) {}
}

final class AuditEntryContextResolver implements EntryResolverInterface
{
    /** @var array<string|int,mixed> */
    public array $received = [];

    public function __construct(private readonly string $target) {}

    public function can(string $id): bool
    {
        return $id === $this->target;
    }

    public function resolve(string $id, array $params = []): mixed
    {
        $this->received = $params;
        return new AuditEntryContextDto((string) ($params['value'] ?? ''));
    }
}

final class AuditRootResolverBuilder extends ContainerBuilder
{
    public function __construct(private readonly EntryResolverInterface $root)
    {
        parent::__construct();
    }

    protected function createEntryResolver(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        ObjectPipeline $objects,
        CallableExecutorInterface $executor,
    ): EntryResolverInterface {
        return $this->root;
    }
}

final class AuditNestedResolverBuilder extends ContainerBuilder
{
    public function __construct(private readonly EntryResolverInterface $probe)
    {
        parent::__construct();
    }

    protected function createEntryResolver(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        ObjectPipeline $objects,
        CallableExecutorInterface $executor,
    ): EntryResolverInterface {
        return new CompositeResolver(
            $this->probe,
            parent::createEntryResolver(
                $container,
                $proxyFactory,
                $objects,
                $executor,
            ),
        );
    }
}

/** @param iterable<string|int> $keys */
function expectNoInternalResolutionKeys(iterable $keys): void
{
    foreach ($keys as $key) {
        if (is_string($key)) {
            expect(str_starts_with($key, "\0componenta.di."))->toBeFalse();
        }
    }
}

test('mapped request provenance is hidden from custom parameter resolvers', function (): void {
    $probe = new AuditParameterContextResolver();
    $container = (new ContainerBuilder())
        ->addParameterResolver($probe, 2000)
        ->build();
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'ok']);

    $result = $container->call(
        static fn(#[MapRequestPayload] AuditParameterContextDto $dto): string => $dto->value,
        [ServerRequestInterface::class => $request],
    );

    expect($result)->toBe('ok');
    expectNoInternalResolutionKeys($probe->keys);
});

test('object handlers receive only caller-visible creation parameters', function (): void {
    $probe = new AuditObjectContextHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            AuditObjectContextAttribute::class,
            $probe,
        ))
        ->build();
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'ok']);

    $result = $container->call(
        static fn(#[MapRequestPayload] AuditObjectContextDto $dto): string => $dto->value,
        [ServerRequestInterface::class => $request],
    );

    expect($result)->toBe('ok');
    expectNoInternalResolutionKeys($probe->keys);
});

test('custom root entry resolvers do not receive mapped request provenance', function (): void {
    $probe = new AuditEntryContextResolver(AuditEntryContextDto::class);
    $container = (new AuditRootResolverBuilder($probe))->build();
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'root']);

    $result = $container->call(
        static fn(#[MapRequestPayload] AuditEntryContextDto $dto): string => $dto->value,
        [ServerRequestInterface::class => $request],
    );

    expect($result)->toBe('root');
    expectNoInternalResolutionKeys(array_keys($probe->received));
});

test('custom nested entry resolvers do not receive mapped request provenance', function (): void {
    $probe = new AuditEntryContextResolver(AuditEntryContextDto::class);
    $container = (new AuditNestedResolverBuilder($probe))->build();
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'nested']);

    $result = $container->call(
        static fn(#[MapRequestPayload] AuditEntryContextDto $dto): string => $dto->value,
        [ServerRequestInterface::class => $request],
    );

    expect($result)->toBe('nested');
    expectNoInternalResolutionKeys(array_keys($probe->received));
});
