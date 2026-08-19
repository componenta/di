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
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\CurrentUserProvider;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Componenta\DI\Tests\Support\TestCounter;

final class CastParameterParityDto
{
    public function __construct(
        #[Cast('int')]
        public int $value,
    ) {}
}

final class CastDefaultParityDto
{
    public function __construct(
        #[Cast('int', default: '7')]
        public int $value,
    ) {}
}

final class CastPropertyParityDto
{
    #[Cast('int')]
    public int $value;
}

final class InitPropertyParityDto
{
    #[Init([TestCounter::class, 'next'])]
    public int $value;
}

final class ConfigOverrideParityDto
{
    public function __construct(
        #[ConfigAttribute('value')]
        public string $value,
    ) {}
}

final class CurrentUserOverrideParityDto
{
    public function __construct(
        #[CurrentUser]
        public object $user,
    ) {}
}

function parityValueContainer(): \Componenta\DI\Container
{
    return (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();
}

test('Cast parameter resolution stays in the parameter resolver chain', function (): void {
    $dto = parityValueContainer()->make(CastParameterParityDto::class, ['value' => '42']);

    expect($dto->value)->toBe(42);
});

test('Cast keeps the v4 attribute-owned default contract', function (): void {
    $dto = parityValueContainer()->make(CastDefaultParityDto::class);

    expect($dto->value)->toBe(7);
});

test('Cast property reads object creation parameters rather than initialized property state', function (): void {
    $container = parityValueContainer();

    expect($container->make(CastPropertyParityDto::class, ['value' => '9'])->value)->toBe(9)
        ->and(fn() => $container->make(CastPropertyParityDto::class))
        ->toThrow(ResolutionException::class);
});

test('Init remains a property attribute handler and executes once after instantiation', function (): void {
    TestCounter::reset();

    $dto = parityValueContainer()->make(InitPropertyParityDto::class);

    expect($dto->value)->toBe(1)
        ->and(TestCounter::$value)->toBe(1);
});

test('explicit named parameters keep v4 precedence over Config parameter resolution', function (): void {
    $container = ContainerBuilder::configure(new Config(['value' => 'configured']))->build();

    expect($container->make(ConfigOverrideParityDto::class, ['value' => 'explicit'])->value)
        ->toBe('explicit');
});

test('CurrentUser remains authoritative over caller-provided parameter values', function (): void {
    $authenticated = new \stdClass();
    $untrusted = new \stdClass();
    $container = (new ContainerBuilder())
        ->addService(CurrentUserProviderInterface::class, new CurrentUserProvider($authenticated))
        ->build();

    expect($container->make(CurrentUserOverrideParityDto::class, ['user' => $untrusted])->user)
        ->toBe($authenticated);
});
