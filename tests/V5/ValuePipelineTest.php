<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Attribute\Init;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ValueProviderConflictException;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\CurrentUserProvider;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Componenta\DI\Tests\Support\TestCounter;

final class RepeatedCastDto
{
    public function __construct(
        #[Cast('trim'), Cast('int')]
        public int $value,
    ) {}
}

final class PromotedInitDto
{
    public function __construct(
        #[Init([TestCounter::class, 'next'])]
        public int $value,
    ) {}
}

final class TransformerOnlyPropertyDto
{
    #[Cast('trim')]
    public string $value = '  value  ';
}

final class ConfigProtectedDto
{
    public function __construct(
        #[ConfigAttribute('trusted')]
        public string $trusted,
    ) {}
}

final class CurrentUserDto
{
    public function __construct(#[CurrentUser] public object $user) {}
}

function valueContainer(): \Componenta\DI\Container
{
    return (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();
}

test('repeatable transformers run in declaration order before final type validation', function (): void {
    $dto = valueContainer()->make(
        RepeatedCastDto::class,
        ResolutionContext::explicit(['value' => ' 42 ']),
    );

    expect($dto->value)->toBe(42);
});

test('promoted value attributes are constructor owned and execute once', function (): void {
    TestCounter::reset();

    $dto = valueContainer()->make(PromotedInitDto::class);

    expect($dto->value)->toBe(1)
        ->and(TestCounter::$value)->toBe(1);
});

test('transformer-only property uses its initialized value as input', function (): void {
    $dto = valueContainer()->make(TransformerOnlyPropertyDto::class);

    expect($dto->value)->toBe('value');
});

test('mapped input can never shadow a declared value provider', function (): void {
    $container = ContainerBuilder::configure(new Config(['trusted' => 'server']))->build();

    expect(fn() => $container->make(
        ConfigProtectedDto::class,
        ResolutionContext::mapped(['trusted' => 'attacker']),
    ))->toThrow(ValueProviderConflictException::class);
});

test('authoritative providers can reject trusted caller explicit overrides', function (): void {
    $user = new \stdClass();
    $container = (new ContainerBuilder())
        ->addService(CurrentUserProviderInterface::class, new CurrentUserProvider($user))
        ->build();

    expect(fn() => $container->make(
        CurrentUserDto::class,
        ResolutionContext::explicit(['user' => new \stdClass()]),
    ))->toThrow(ValueProviderConflictException::class);
});
