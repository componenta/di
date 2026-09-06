<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Tests\Support\ContainerBuilder;

#[Attribute(Attribute::TARGET_ALL)]
final class SingleMetadataMarker
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

#[Attribute(Attribute::TARGET_ALL | Attribute::IS_REPEATABLE)]
final class RepeatableMetadataMarker
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

#[RepeatableMetadataMarker, RepeatableMetadataMarker]
final class ValidRepeatedMetadata {}

final class ValidPromotedMetadata
{
    public function __construct(#[SingleMetadataMarker] public string $value = 'default') {}
}

it('rejects repeated non-repeatable metadata without constructing attribute instances', function (string $kind): void {
    $name = 'RepeatedMetadata_' . bin2hex(random_bytes(5));
    $declaration = match ($kind) {
        'class' => sprintf('#[SingleMetadataMarker, SingleMetadataMarker] final class %s {}', $name),
        'property' => sprintf('final class %s { #[SingleMetadataMarker, SingleMetadataMarker] public string $value = "default"; }', $name),
        'method' => sprintf('final class %s { #[SingleMetadataMarker, SingleMetadataMarker] public function run(): void {} }', $name),
        'parameter' => sprintf('final class %s { public function __construct(#[SingleMetadataMarker, SingleMetadataMarker] string $value = "default") {} }', $name),
        default => throw new \LogicException('Unknown metadata target kind.'),
    };
    eval(sprintf('namespace %s; %s', __NAMESPACE__, $declaration));
    $target = __NAMESPACE__ . '\\' . $name;
    if (!class_exists($target)) {
        throw new \LogicException('Expected the invalid attribute fixture class.');
    }
    SingleMetadataMarker::$constructions = 0;
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(SingleMetadataMarker::class))
        ->build();

    expect(fn() => $container->make($target))
        ->toThrow(AttributeCompositionException::class, 'must not be repeated');
    expect(SingleMetadataMarker::$constructions)->toBe(0);
})->with([
    'class',
    'property',
    'method',
    'parameter',
]);

it('allows repeatable metadata without evaluating runtime attribute constructors', function (): void {
    RepeatableMetadataMarker::$constructions = 0;
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(RepeatableMetadataMarker::class))
        ->build();

    expect($container->make(ValidRepeatedMetadata::class))->toBeInstanceOf(ValidRepeatedMetadata::class)
        ->and(RepeatableMetadataMarker::$constructions)->toBe(0);
});

it('does not treat promotion copies as repeated declarations on one target', function (): void {
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(SingleMetadataMarker::class))
        ->build();

    expect($container->make(ValidPromotedMetadata::class)->value)->toBe('default');
});
