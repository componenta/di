<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\NotFoundException;
use Psr\Container\ContainerInterface;

interface LiveAliasParityContract
{
    public function id(): string;
}

final class LiveAliasParityFirst implements LiveAliasParityContract
{
    public function id(): string
    {
        return 'first';
    }
}

final class LiveAliasParitySecond implements LiveAliasParityContract
{
    public function id(): string
    {
        return 'second';
    }
}

final readonly class LiveAliasParityConsumer
{
    public function __construct(public LiveAliasParityContract $dependency) {}
}

interface LiveExternalParityContract
{
    public function id(): string;
}

final class LiveExternalParityService implements LiveExternalParityContract
{
    public function id(): string
    {
        return 'external';
    }
}

final readonly class LiveExternalParityConsumer
{
    public function __construct(public LiveExternalParityContract $dependency) {}
}

function liveExternalParityContainer(LiveExternalParityContract $service): ContainerInterface
{
    return new class ($service) implements ContainerInterface {
        public function __construct(
            private readonly LiveExternalParityContract $service,
        ) {}

        public function get(string $id): mixed
        {
            if ($id !== LiveExternalParityContract::class) {
                throw NotFoundException::forService($id);
            }

            return $this->service;
        }

        public function has(string $id): bool
        {
            return $id === LiveExternalParityContract::class;
        }
    };
}

/** @param array<string, mixed> $factories */
function liveParityProductionContainer(
    array $factories,
    string $directory,
    array $aliases = [],
): Container {
    return ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => [
                ConfigKey::FACTORIES => $factories,
                ConfigKey::ALIASES => $aliases,
            ],
        ],
        $directory,
    )->build();
}

it('keeps compiled autowiring bound to the live alias resolver', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-live-alias-parity-' . bin2hex(random_bytes(5));

    try {
        $development = (new ContainerBuilder())
            ->addAlias(LiveAliasParityContract::class, LiveAliasParityFirst::class)
            ->build();
        $factories = (new ContainerBuilder())
            ->addAlias(LiveAliasParityContract::class, LiveAliasParityFirst::class)
            ->compileFactories(
                [LiveAliasParityConsumer::class],
                $directory,
            );
        $production = liveParityProductionContainer(
            $factories,
            $directory,
            [LiveAliasParityContract::class => LiveAliasParityFirst::class],
        );

        $development->alias(LiveAliasParityContract::class, LiveAliasParitySecond::class);
        $production->alias(LiveAliasParityContract::class, LiveAliasParitySecond::class);

        $expected = $development->make(LiveAliasParityConsumer::class);
        $actual = $production->make(LiveAliasParityConsumer::class);

        expect($actual->dependency->id())
            ->toBe($expected->dependency->id())
            ->toBe('second');
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }
});

it('keeps compiled autowiring bound to external containers registered after build', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-live-external-parity-' . bin2hex(random_bytes(5));

    try {
        $development = (new ContainerBuilder())->build();
        $factories = (new ContainerBuilder())->compileFactories(
            [LiveExternalParityConsumer::class],
            $directory,
        );
        $production = liveParityProductionContainer(
            $factories,
            $directory,
        );

        $development->addContainer(liveExternalParityContainer(new LiveExternalParityService()));
        $production->addContainer(liveExternalParityContainer(new LiveExternalParityService()));

        $expected = $development->make(LiveExternalParityConsumer::class);
        $actual = $production->make(LiveExternalParityConsumer::class);

        expect($actual->dependency->id())
            ->toBe($expected->dependency->id())
            ->toBe('external');
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        if (is_dir($directory)) {
            @rmdir($directory);
        }
    }
});
