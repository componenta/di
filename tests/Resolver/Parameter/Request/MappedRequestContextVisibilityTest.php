<?php

declare(strict_types=1);

use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ConfigProvider;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class MappedVisibilityPlainDto
{
    public function __construct(public string $value) {}
}

interface MappedVisibilityFactoryContract {}

final readonly class MappedVisibilityFactoryResult implements MappedVisibilityFactoryContract
{
    /** @param array<string|int, mixed> $context */
    public function __construct(public array $context) {}
}

final readonly class MappedVisibilityFactoryEntry
{
    public function __construct(
        #[MapRequestPayload]
        public MappedVisibilityFactoryContract $result,
    ) {}
}

final readonly class MappedVisibilityResolverDto
{
    /** @param list<string|int> $visibleKeys */
    public function __construct(
        public string $value,
        public array $visibleKeys,
    ) {}
}

final readonly class MappedVisibilityResolverEntry
{
    public function __construct(
        #[MapRequestPayload]
        public MappedVisibilityResolverDto $dto,
    ) {}
}

final readonly class MappedVisibilityResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'visibleKeys';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, array_keys($context->provided)];
    }
}

function mappedVisibilityBuilder(): ContainerBuilder
{
    return ContainerBuilder::configure(
        new Config((new ConfigProvider())()),
    )->addService(
        CasterProviderInterface::class,
        new NullCasterProvider(),
    );
}

it('extracts mapped provenance before parameter resolvers receive provided values', function (): void {
    $mapped = ['value' => 'payload-value'];
    $transport = MappedRequestContext::with($mapped, $mapped);
    $context = new ParameterResolutionContext($transport);

    expect($context->provided)->toBe($mapped)
        ->and($context->mappedRequest)->not->toBeNull();
});

it('keeps mapped provenance out of object creation parameters exposed to handlers', function (): void {
    $mapped = ['value' => 'payload-value'];
    $transport = MappedRequestContext::with($mapped, $mapped);
    $context = new ObjectCreationContext(
        new ReflectionClass(MappedVisibilityPlainDto::class),
        $transport,
    );

    expect($context->parameters)->toBe($mapped)
        ->and(count($context->resolutionParameters()))->toBe(count($mapped) + 1);
});

it('does not expose mapped provenance to a custom parameter resolver', function (): void {
    $request = new FakeServerRequest(
        method: 'POST',
        parsedBody: ['value' => 'payload-value'],
    );
    $container = mappedVisibilityBuilder()
        ->addParameterResolver(new MappedVisibilityResolver(), 5000)
        ->build();
    $entry = $container->make(MappedVisibilityResolverEntry::class, [
        ServerRequestInterface::class => $request,
    ]);

    expect($entry->dto->visibleKeys)->toBe([
        'value',
        ServerRequestInterface::class,
    ]);
});

it('forwards only caller-visible mapped values to an ordinary user factory', function (): void {
    $request = new FakeServerRequest(
        method: 'POST',
        parsedBody: ['value' => 'payload-value'],
    );
    $container = mappedVisibilityBuilder()
        ->addFactory(
            MappedVisibilityFactoryContract::class,
            static fn(ContainerValue $container, array $context): MappedVisibilityFactoryContract =>
                new MappedVisibilityFactoryResult($context),
        )
        ->build();
    $entry = $container->make(MappedVisibilityFactoryEntry::class, [
        ServerRequestInterface::class => $request,
    ]);

    expect($entry->result)->toBeInstanceOf(MappedVisibilityFactoryResult::class)
        ->and($entry->result->context)->toBe([
            'value' => 'payload-value',
            ServerRequestInterface::class => $request,
        ]);
});
