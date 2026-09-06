<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class ProxyCompositionValue
{
    public function __construct(public string $label = 'source') {}
}

final class ProxyCompositionSuffixCaster implements CasterInterface
{
    public string $name { get => 'suffix-object'; }

    public function cast(mixed $value): mixed
    {
        if (!$value instanceof ProxyCompositionValue) {
            throw new \LogicException('The suffix caster requires the supplied proxy value.');
        }

        return new ProxyCompositionValue($value->label . ':cast');
    }
}

final readonly class ProxyCompositionCasterProvider implements CasterProviderInterface
{
    public function provide(string $name): ?CasterInterface
    {
        return $name === 'suffix-object'
            ? new ProxyCompositionSuffixCaster()
            : null;
    }
}

final readonly class ProxyCastParameterTarget
{
    public function __construct(
        #[Cast('suffix-object'), Proxy]
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

/** @return non-empty-string */
function proxyReadonlyTransformerTarget(): string
{
    $class = __NAMESPACE__ . '\\ProxyReadonlyTransformerTarget';

    if (!class_exists($class, false)) {
        eval(<<<'PHP'
namespace Componenta\DI\Tests\V5;

final class ProxyReadonlyTransformerTarget
{
    #[\Componenta\DI\Attribute\Proxy, \Componenta\DI\Attribute\Cast('suffix-object')]
    public readonly ProxyCompositionValue $value;

    public function __construct(\Closure $onConstruct)
    {
        $onConstruct();
    }
}
PHP);
    }

    if (!class_exists($class, false)) {
        throw new \LogicException('Failed to define the readonly proxy fixture.');
    }

    return $class;
}

test('Proxy supplies its value before Cast transforms it', function (): void {
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new ProxyCompositionCasterProvider())
        ->build();

    $target = $container->make(ProxyCastParameterTarget::class);

    expect($target->value->label)->toBe('source:cast');
});

test('Proxy cannot compete with another value source on one injection point', function (): void {
    $container = ContainerBuilder::configure(new Config(
        ['value' => new ProxyCompositionValue()],
        new Environment([]),
    ))->build();

    expect(fn() => $container->make(ProxyConflictingSourceTarget::class))
        ->toThrow(AttributeCompositionException::class, 'multiple parameter source handlers');
});

test('Proxy values can be transformed before initializing a readonly property', function (): void {
    $constructorCalls = 0;
    $onConstruct = static function () use (&$constructorCalls): void {
        ++$constructorCalls;
    };
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new ProxyCompositionCasterProvider())
        ->build();

    $entry = $container->make(proxyReadonlyTransformerTarget(), ['onConstruct' => $onConstruct]);

    if (!property_exists($entry, 'value') || !$entry->value instanceof ProxyCompositionValue) {
        throw new \LogicException('Expected the transformed proxy value.');
    }
    expect($entry->value->label)->toBe('source:cast')
        ->and($constructorCalls)->toBe(1);
});
