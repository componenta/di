<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigValue;
use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\CurrentUserProvider;
use Componenta\DI\Resolver\CurrentUserProviderInterface;

final readonly class AttributeContractEntry
{
    public function __construct(public string $value) {}
}

final class AttributePropertyTarget
{
    #[EntryId('attribute.entry')]
    public AttributeContractEntry $entry;

    #[ConfigValue('attribute.config')]
    public string $config;

    #[Env('ATTRIBUTE_ENV')]
    public string $env;
}

final class AttributeCurrentUser {}

final class AttributeDifferentUser {}

final class OptionalAttributePropertyTarget
{
    #[Cast('int')]
    public int $count;

    #[CurrentUser]
    public AttributeCurrentUser $user;
}

function optionalAttributeContainer(): Container
{
    $caster = new class () implements CasterInterface {
        public string $name {
            get => 'int';
        }

        public function cast(mixed $value): mixed
        {
            return (int) $value;
        }
    };
    $casters = new class ($caster) implements CasterProviderInterface {
        public function __construct(private readonly CasterInterface $caster) {}

        public function provide(string $name): ?CasterInterface
        {
            return $name === $this->caster->name ? $this->caster : null;
        }
    };

    return ContainerBuilder::configure(new Config((new ConfigProvider())()))
        ->addService(CasterProviderInterface::class, $casters)
        ->build();
}

it('resolves EntryId, Config and Env through public call and make APIs', function (): void {
    $entry = new AttributeContractEntry('service');
    $config = new Config(
        ['attribute.config' => 'configured'],
        new Environment(['ATTRIBUTE_ENV' => 'environment']),
    );
    $container = ContainerBuilder::configure($config)
        ->addService('attribute.entry', $entry)
        ->build();

    $arguments = $container->call(static fn(
        #[EntryId('attribute.entry')] AttributeContractEntry $resolvedEntry,
        #[ConfigValue('attribute.config')] string $configured,
        #[Env('ATTRIBUTE_ENV')] string $environment,
    ): array => [$resolvedEntry, $configured, $environment]);
    $target = $container->make(AttributePropertyTarget::class);

    expect($arguments)->toBe([$entry, 'configured', 'environment'])
        ->and($target->entry)->toBe($entry)
        ->and($target->config)->toBe('configured')
        ->and($target->env)->toBe('environment');
});

it('honours Config and Env defaults through the public parameter pipeline', function (): void {
    $container = ContainerBuilder::configure(new Config([], new Environment([])))->build();

    $resolved = $container->call(static fn(
        #[ConfigValue('missing.config', default: 'config-default')] string $config,
        #[Env('MISSING_ENV', default: 'env-default')] string $env,
    ): array => [$config, $env]);

    expect($resolved)->toBe(['config-default', 'env-default']);
});

it('casts parameter and property values and injects the current user through configured extensions', function (): void {
    $container = optionalAttributeContainer();
    $user = new AttributeCurrentUser();
    $provider = $container->get(CurrentUserProviderInterface::class);

    expect($provider)->toBeInstanceOf(CurrentUserProvider::class);
    $provider->setUser($user);

    $arguments = $container->call(static fn(
        #[Cast('int')] int $count,
        #[CurrentUser] AttributeCurrentUser $currentUser,
    ): array => [$count, $currentUser], ['count' => '42']);
    $target = $container->make(OptionalAttributePropertyTarget::class, ['count' => '17']);

    expect($arguments[0])->toBe(42)
        ->and($arguments[1])->toBe($user)
        ->and($target->count)->toBe(17)
        ->and($target->user)->toBe($user);
});

it('distinguishes optional and required CurrentUser targets', function (): void {
    $container = optionalAttributeContainer();

    $optional = $container->call(
        static fn(#[CurrentUser] ?AttributeCurrentUser $user): ?AttributeCurrentUser => $user,
    );

    expect($optional)->toBeNull()
        ->and(fn() => $container->call(
            static fn(#[CurrentUser] AttributeCurrentUser $user): AttributeCurrentUser => $user,
        ))->toThrow(
            ResolutionException::class,
            'current user is required but not authenticated',
        );
});

it('rejects CurrentUser values that violate the declared or attribute type contract', function (): void {
    $container = optionalAttributeContainer();
    $provider = $container->get(CurrentUserProviderInterface::class);

    expect($provider)->toBeInstanceOf(CurrentUserProvider::class);
    $provider->setUser(new AttributeDifferentUser());

    expect(fn() => $container->call(
        static fn(#[CurrentUser(AttributeCurrentUser::class)] object $user): object => $user,
    ))->toThrow(
        ResolutionException::class,
        'current user must be instance of',
    )->and(fn() => $container->call(
        static fn(#[CurrentUser] AttributeCurrentUser $user): AttributeCurrentUser => $user,
    ))->toThrow(
        ResolutionException::class,
        'current user must be instance of',
    );
});

it('reports an unknown caster through the public call API', function (): void {
    $container = optionalAttributeContainer();

    expect(fn() => $container->call(
        static fn(#[Cast('missing')] int $value): int => $value,
        ['value' => '1'],
    ))->toThrow(ResolutionException::class, 'caster "missing" is not registered');
});
