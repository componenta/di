<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

final class AuditPreparedValueResolver implements ParameterResolverInterface
{
    public int $supportsCalls = 0;

    public function supports(ParameterTarget $target): bool
    {
        ++$this->supportsCalls;
        return $target->name === 'value';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $target->name === 'value'
            ? [$target->position, 'prepared']
            : null;
    }
}

final readonly class AuditPreparedTarget
{
    public function __construct(public string $value) {}
}

interface AuditPreparedDefaultObjectInterface {}

final class AuditPreparedDefaultObject implements AuditPreparedDefaultObjectInterface {}

final readonly class AuditPreparedDefaultObjectTarget
{
    public function __construct(
        public AuditPreparedDefaultObjectInterface $value = new AuditPreparedDefaultObject(),
    ) {}
}

test('has does not execute custom parameter classification', function (): void {
    $resolver = new AuditPreparedValueResolver();
    $container = (new ContainerBuilder())
        ->addParameterResolver($resolver, 2000)
        ->build();

    expect($container->has(AuditPreparedTarget::class))->toBeTrue()
        ->and($resolver->supportsCalls)->toBe(0)
        ->and($container->make(AuditPreparedTarget::class)->value)->toBe('prepared');
});

test('repeated resolutions do not reuse object default values', function (): void {
    $container = (new ContainerBuilder())->build();

    $first = $container->make(AuditPreparedDefaultObjectTarget::class);
    $second = $container->make(AuditPreparedDefaultObjectTarget::class);

    expect($first->value)->toBeInstanceOf(AuditPreparedDefaultObject::class)
        ->and($second->value)->toBeInstanceOf(AuditPreparedDefaultObject::class)
        ->and($first->value)->not->toBe($second->value);
});
