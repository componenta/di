<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
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
