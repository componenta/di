<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AttributeSequenceBoundary;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Container;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use Reflector;

final class Events
{
    /** @var list<string> */
    public static array $values = [];
}

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RevealProxy
{
    public function __construct(public string $alias) {}
}

#[Attribute(Attribute::TARGET_METHOD)]
final class NativeTail
{
    public function __construct()
    {
        Events::$values[] = 'tail:construct';
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final class BootstrapMarker {}

#[BootstrapMarker]
final class BootstrapTarget {}

abstract class Owner
{
    public function __construct()
    {
        Events::$values[] = 'owner:construct';
    }
}

final class BeforeHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($attribute instanceof RevealProxy) {
            Events::$values[] = 'loader';
            class_alias(Proxy::class, $attribute->alias);
        } elseif ($attribute instanceof NativeTail) {
            Events::$values[] = 'tail:handle';
        } elseif ($attribute instanceof BootstrapMarker) {
            Events::$values[] = 'marker:handle';
        } else {
            throw new LogicException('Unexpected before-phase attribute.');
        }
    }
}

/**
 * @param class-string $class
 * @return ReflectionClass<object>
 */
function reflection(string $class): ReflectionClass
{
    return new ReflectionClass($class);
}

test('a late property Proxy argument rejects a changed prefix before native arguments of the same method suffix', function (): void {
    Events::$values = [];
    $suffix = bin2hex(random_bytes(6));
    $target = 'Target_' . $suffix;
    $proxy = 'LateProxy_' . $suffix;
    $policy = 'LatePolicy_' . $suffix;
    $holder = 'ArgumentHolder_' . $suffix;
    $proxyAlias = __NAMESPACE__ . '\\' . $proxy;
    $policyAlias = __NAMESPACE__ . '\\' . $policy;
    $holderClass = __NAMESPACE__ . '\\' . $holder;
    eval(sprintf(
        'namespace %s; #[%s] final class %s extends Owner { #[%s(%s::ENTRY)] public \\stdClass $dependency; #[RevealProxy(%s), NativeTail] public function load(): void {} }',
        __NAMESPACE__,
        $policy,
        $target,
        $proxy,
        $holder,
        var_export($proxyAlias, true),
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    $loader = static function (string $requested) use ($holderClass, $holder, $policyAlias): void {
        if ($requested === $holderClass) {
            Events::$values[] = 'proxy:argument';
            class_alias(NoConstructor::class, $policyAlias);
            eval(sprintf('namespace %s; final class %s { public const ENTRY = \\stdClass::class; }', __NAMESPACE__, $holder));
        }
    };
    spl_autoload_register($loader);
    try {
        $container = (new ContainerBuilder())
            ->addAttributeDefinition(new AttributeDefinition(RevealProxy::class, new BeforeHandler(), phase: AttributePhase::BeforeInstantiation))
            ->addAttributeDefinition(new AttributeDefinition(NativeTail::class, new BeforeHandler(), phase: AttributePhase::BeforeInstantiation))
            ->build();

        expect(fn(): object => $container->make($class))
            ->toThrow(AttributeCompositionException::class, 'was already executed')
            ->and(Events::$values)->toBe(['loader', 'proxy:argument'], 'Native effects: ' . implode(', ', Events::$values));
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('bootstrap rejects an object phase consumed before the first replacement attribute definition', function (): void {
    Events::$values = [];
    $builder = (new ContainerBuilder())
        ->replaceAttributeDefinitions()
        ->addAttributeDefinition(static function (Container $container): AttributeDefinition {
            $container->make(BootstrapTarget::class);
            return new AttributeDefinition(BootstrapMarker::class, new BeforeHandler(), phase: AttributePhase::BeforeInstantiation);
        });

    expect($builder->build(...))->toThrow(InvalidConfigurationException::class, BootstrapTarget::class)
        ->and(Events::$values)->toBe([]);
});
