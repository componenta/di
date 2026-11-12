<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AttributeDiscoveryRevision;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Reflector;
use WeakReference;

final class DiscoveryPayload
{
    public static int $current = 0;
    /** @var list<WeakReference<self>> */
    public static array $instances = [];
    public readonly int $value;

    public function __construct()
    {
        $this->value = self::$current;
        self::$instances[] = WeakReference::create($this);
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DiscoveryRequired
{
    public function __construct(public DiscoveryPayload $payload) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DiscoveryDependent
{
    public function __construct(public DiscoveryPayload $payload) {}
}

#[Attribute(Attribute::TARGET_CLASS)]
final class DiscoveryUncachedMarker {}

final class DiscoveryNoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

abstract class DiscoveryTarget
{
    public static int $constructions = 0;
    /** @var list<array{string,int}> */
    public array $events = [];

    public function __construct()
    {
        ++self::$constructions;
    }
}

final class DiscoveryHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ((!$attribute instanceof DiscoveryRequired && !$attribute instanceof DiscoveryDependent)
            || !$context->entry instanceof DiscoveryTarget
        ) {
            throw new LogicException('Expected the discovery attribute and target.');
        }
        $context->entry->events[] = [
            $attribute instanceof DiscoveryRequired ? 'required' : 'dependent',
            $attribute->payload->value,
        ];
    }
}

test('attribute discovery validates one revision when a later autoload reveals an earlier dependency', function (bool $uncached, bool $warm): void {
    $suffix = bin2hex(random_bytes(6));
    $required = 'LateRequired_' . $suffix;
    $dependent = 'LateDependent_' . $suffix;
    $target = 'Target_' . $suffix;
    eval(sprintf(
        'namespace %s; #[%s(new DiscoveryPayload()), %s(new DiscoveryPayload())] final class %s extends DiscoveryTarget {}',
        __NAMESPACE__,
        $required,
        $dependent,
        $target,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, DiscoveryTarget::class, true)) {
        throw new LogicException('Expected the discovery target class.');
    }
    $handler = new DiscoveryHandler();
    $builder = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(DiscoveryRequired::class, $handler))
        ->addAttributeDefinition(new AttributeDefinition(DiscoveryDependent::class, $handler, requires: [DiscoveryRequired::class]));
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(DiscoveryUncachedMarker::class, rules: [new DiscoveryNoOpRule()]));
    }
    $container = $builder->build();
    DiscoveryTarget::$constructions = 0;
    DiscoveryPayload::$instances = [];
    if ($warm) {
        expect($container->has($class))->toBeTrue();
    }
    expect(DiscoveryTarget::$constructions)->toBe(0)
        ->and(DiscoveryPayload::$instances)->toBe([]);

    $loads = 0;
    $loader = static function (string $requested) use ($required, $dependent, &$loads): void {
        if ($requested === __NAMESPACE__ . '\\' . $dependent) {
            ++$loads;
            class_alias(DiscoveryRequired::class, __NAMESPACE__ . '\\' . $required);
            class_alias(DiscoveryDependent::class, __NAMESPACE__ . '\\' . $dependent);
        }
    };
    spl_autoload_register($loader);
    try {
        foreach ([1, 2] as $value) {
            DiscoveryPayload::$current = $value;
            $entry = $container->make($class);
            expect($entry)->toBeInstanceOf(DiscoveryTarget::class);
            if (!$entry instanceof DiscoveryTarget) {
                throw new LogicException('Expected a created discovery target.');
            }
            expect($entry->events)->toBe([['required', $value], ['dependent', $value]])
                ->and(DiscoveryTarget::$constructions)->toBe($value)
                ->and(DiscoveryPayload::$instances)->toHaveCount(2 * $value)
                ->and(array_filter(DiscoveryPayload::$instances, static fn(WeakReference $instance): bool => $instance->get() !== null))
                ->toBe([]);
        }
        expect($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
        DiscoveryPayload::$instances = [];
        DiscoveryPayload::$current = 0;
    }
})->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['cold metadata' => false, 'warm metadata' => true]);
