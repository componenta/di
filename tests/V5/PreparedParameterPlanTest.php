<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;

final class AuditPreparedValueResolver implements ParameterResolverInterface
{
    public int $supportsCalls = 0;
    public int $resolveCalls = 0;

    public function supports(ParameterTarget $target): bool
    {
        ++$this->supportsCalls;
        return $target->name === 'value';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        ++$this->resolveCalls;
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

test('has does not classify constructor parameter resolvers', function (): void {
    $resolver = new AuditPreparedValueResolver();
    $container = (new ContainerBuilder())
        ->addParameterResolver($resolver, 2000)
        ->build();

    expect($container->has(AuditPreparedTarget::class))->toBeTrue()
        ->and($resolver->supportsCalls)->toBe(0);

    expect($container->make(AuditPreparedTarget::class)->value)->toBe('prepared')
        ->and($resolver->supportsCalls)->toBe(1);
});

test('warmed constructor plans do not repeat supports classification', function (): void {
    $resolver = new AuditPreparedValueResolver();
    $container = (new ContainerBuilder())
        ->addParameterResolver($resolver, 2000)
        ->build();

    for ($index = 0; $index < 20; ++$index) {
        expect($container->make(AuditPreparedTarget::class)->value)->toBe('prepared');
    }

    expect($resolver->supportsCalls)->toBe(1)
        ->and($resolver->resolveCalls)->toBe(20);
});

test('prepared plans cannot be executed by another resolver pipeline', function (): void {
    $first = (new ContainerBuilder())->build();
    $second = (new ContainerBuilder())->build();
    $firstParameters = $first->get(ParametersResolver::class);
    $secondParameters = $second->get(ParametersResolver::class);

    expect($firstParameters)->toBeInstanceOf(ParametersResolver::class)
        ->and($secondParameters)->toBeInstanceOf(ParametersResolver::class);

    /** @var ParametersResolver $firstParameters */
    /** @var ParametersResolver $secondParameters */
    $constructor = (new ReflectionClass(AuditPreparedTarget::class))->getConstructor();
    expect($constructor)->not->toBeNull();
    $plan = $firstParameters->prepare($constructor->getParameters());

    expect(fn() => $secondParameters->resolvePrepared($plan))
        ->toThrow(InvalidConfigurationException::class, 'another resolver pipeline');
});

test('prepared default resolver does not cache object default values', function (): void {
    $container = (new ContainerBuilder())->build();

    $first = $container->make(AuditPreparedDefaultObjectTarget::class);
    $second = $container->make(AuditPreparedDefaultObjectTarget::class);

    expect($first->value)->toBeInstanceOf(AuditPreparedDefaultObject::class)
        ->and($second->value)->toBeInstanceOf(AuditPreparedDefaultObject::class)
        ->and($first->value)->not->toBe($second->value);
});
