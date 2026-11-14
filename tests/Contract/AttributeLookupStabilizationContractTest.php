<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AttributeLookupStabilization;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Reflector;

interface GenericMarker {}

interface OtherGenericMarker {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SpecificMarker implements GenericMarker, OtherGenericMarker {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class UnrelatedMarker {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class CacheControl {}

final readonly class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

abstract class Owner
{
    /** @var list<string> */
    public array $events = [];
}

final readonly class RecordHandler implements AttributeHandlerInterface
{
    public function __construct(private string $label) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$context->entry instanceof Owner) {
            throw new LogicException('Expected the attribute lookup fixture.');
        }
        $context->entry->events[] = $this->label;
    }
}

/** @return array{class:class-string<Owner>,attribute:string} */
function fixture(): array
{
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'DelayedMarker_' . $suffix;
    $shortClass = 'Target_' . $suffix;
    eval(sprintf('namespace %s; #[%s] final class %s extends Owner {}', __NAMESPACE__, $attribute, $shortClass));
    $class = __NAMESPACE__ . '\\' . $shortClass;
    if (!is_a($class, Owner::class, true)) {
        throw new LogicException('Expected the generated attribute lookup fixture.');
    }
    return ['class' => $class, 'attribute' => __NAMESPACE__ . '\\' . $attribute];
}

/** @param list<AttributeDefinition> $definitions */
function container(array $definitions, bool $uncached): Container
{
    $builder = (new ContainerBuilder())->replaceAttributeDefinitions();
    foreach ($definitions as $definition) {
        $builder->addAttributeDefinition($definition);
    }
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(CacheControl::class, rules: [new NoOpRule()]));
    }
    return $builder->build();
}

final class DelayedLoader
{
    public int $attempts = 0;

    public function __construct(private readonly string $attribute, private readonly bool $available = true) {}

    public function __invoke(string $requested): void
    {
        if ($requested === $this->attribute && ++$this->attempts === 2 && $this->available) {
            class_alias(SpecificMarker::class, $requested);
        }
    }
}

test('attribute lookup revisits earlier definitions when a later probe makes the class available', function (
    string $registration,
    bool $uncached,
    bool $warm,
): void {
    $target = fixture();
    $specific = new AttributeDefinition(SpecificMarker::class, new RecordHandler('specific'));
    $generic = new AttributeDefinition(GenericMarker::class, new RecordHandler('generic'));
    $definitions = match ($registration) {
        'unrelated after' => [$specific, new AttributeDefinition(UnrelatedMarker::class)],
        'generic after' => [$specific, $generic],
        'generic before' => [$generic, $specific],
        default => throw new LogicException('Unexpected registration fixture.'),
    };
    $container = container($definitions, $uncached);
    $loader = new DelayedLoader($target['attribute']);
    spl_autoload_register($loader);

    try {
        if ($warm) {
            expect($container->has($target['class']))->toBeTrue();
        }
        foreach ([1, 2] as $_attempt) {
            $entry = $container->make($target['class']);
            if (!$entry instanceof Owner) {
                throw new LogicException('Expected the attribute lookup owner.');
            }
            expect($entry->events)->toBe(['specific']);
        }
        expect($loader->attempts)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['unrelated after', 'generic after', 'generic before'])
    ->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['cold metadata' => false, 'warm metadata' => true]);

test('attribute lookup retains ambiguity when a later probe reveals two equally specific definitions', function (
    bool $uncached,
    bool $warm,
): void {
    $target = fixture();
    $container = container([
        new AttributeDefinition(GenericMarker::class, new RecordHandler('first')),
        new AttributeDefinition(OtherGenericMarker::class, new RecordHandler('second')),
    ], $uncached);
    $loader = new DelayedLoader($target['attribute']);
    spl_autoload_register($loader);

    try {
        if ($warm) {
            expect($container->has($target['class']))->toBeFalse();
        }
        expect(fn(): object => $container->make($target['class']))
            ->toThrow(InvalidConfigurationException::class, 'matches multiple equally specific semantic definitions')
            ->and($loader->attempts)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['cold metadata' => false, 'warm metadata' => true]);

test('attribute lookup terminates while an unregistered class remains unavailable', function (bool $uncached): void {
    $target = fixture();
    $container = container([
        new AttributeDefinition(SpecificMarker::class, new RecordHandler('specific')),
        new AttributeDefinition(UnrelatedMarker::class),
    ], $uncached);
    $loader = new DelayedLoader($target['attribute'], available: false);
    spl_autoload_register($loader);

    try {
        foreach ([1, 2] as $_attempt) {
            $entry = $container->make($target['class']);
            if (!$entry instanceof Owner) {
                throw new LogicException('Expected the attribute lookup owner.');
            }
            expect($entry->events)->toBe([]);
        }
        expect(class_exists($target['attribute'], false))->toBeFalse()
            ->and($loader->attempts)->toBeGreaterThan(0);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['cached plans' => false, 'uncached plans' => true]);
