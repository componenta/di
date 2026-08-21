<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;

final class ProxyCompositionValue {}

final class ProxyCompositionIdentityCaster implements CasterInterface
{
    public string $name { get => 'identity-object'; }

    public function cast(mixed $value): mixed
    {
        return $value;
    }
}

final readonly class ProxyCompositionCasterProvider implements CasterProviderInterface
{
    public function provide(string $name): ?CasterInterface
    {
        return $name === 'identity-object'
            ? new ProxyCompositionIdentityCaster()
            : null;
    }
}

final readonly class ProxyCastParameterTarget
{
    public function __construct(
        #[Cast('identity-object'), Proxy]
        public ProxyCompositionValue $value,
    ) {}
}

final readonly class ProxyConflictingSourceTarget
{
    public function __construct(
        #[Proxy, ConfigAttribute('value')]
        public ProxyCompositionValue $value,
    ) {}
}

final class ProxyReadonlyTransformerTarget
{
    #[Proxy, Cast('identity-object')]
    public readonly ProxyCompositionValue $value;
}

/** @return array{0:\Componenta\DI\Container,1:\Componenta\DI\Container,2:string} */
function proxyCompositionParityContainers(): array
{
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-proxy-composition-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\ProxyComposition' . $suffix;
    $builder = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new ProxyCompositionCasterProvider());
    $development = $builder->build();
    $factories = $builder->compileFactories(
        [ProxyCastParameterTarget::class],
        $directory,
        namespace: $namespace,
    );
    $dependencies = $builder->toArray()[ConfigKey::DEPENDENCIES] ?? [];
    $dependencies[ConfigKey::FACTORIES] = array_replace(
        $dependencies[ConfigKey::FACTORIES] ?? [],
        $factories,
    );
    $production = ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => $dependencies,
        ],
        $directory,
    )->build();

    return [$development, $production, $directory];
}

test('Proxy supplies its value before transformers regardless of declaration order in development and AOT', function (): void {
    [$development, $production, $directory] = proxyCompositionParityContainers();

    try {
        expect($development->make(ProxyCastParameterTarget::class)->value)
            ->toBeInstanceOf(ProxyCompositionValue::class)
            ->and($production->make(ProxyCastParameterTarget::class)->value)
            ->toBeInstanceOf(ProxyCompositionValue::class);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

test('Proxy cannot compete with another value source on one injection point', function (): void {
    $container = ContainerBuilder::configure(new Config(['value' => new ProxyCompositionValue()]))->build();

    expect(fn() => $container->make(ProxyConflictingSourceTarget::class))
        ->toThrow(AttributeCompositionException::class, 'cannot be combined with value provider');
});

test('Proxy plus a transformer is rejected on readonly properties before object creation', function (): void {
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new ProxyCompositionCasterProvider())
        ->build();

    expect(fn() => $container->make(ProxyReadonlyTransformerTarget::class))
        ->toThrow(AttributeCompositionException::class, 'cannot be combined with a value transformer');
});
