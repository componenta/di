<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
class TargetLabel
{
    public function __construct(public readonly string $label) {}
}

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final class SpecializedTargetLabel extends TargetLabel {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class OtherTargetLabel {}

final class TargetMetadataExample
{
    public function read(
        int $count,
        #[OtherTargetLabel, SpecializedTargetLabel('first'), SpecializedTargetLabel('second')]
        string $label = 'default',
    ): void {}
}

test('parameter extensions can find inherited attributes and receive the first matching instance', function (): void {
    $reflection = new ReflectionParameter([TargetMetadataExample::class, 'read'], 'label');
    $target = new ParameterTarget($reflection);

    expect($target->hasAttribute(SpecializedTargetLabel::class))->toBeTrue()
        ->and($target->hasAttribute(TargetLabel::class))->toBeTrue()
        ->and($target->attributeClasses)->toBe([OtherTargetLabel::class, SpecializedTargetLabel::class])
        ->and($target->firstAttribute(TargetLabel::class))->toBeInstanceOf(SpecializedTargetLabel::class)
        ->and($target->firstAttribute(TargetLabel::class)?->label)->toBe('first')
        ->and($target->firstAttribute(SpecializedTargetLabel::class)?->label)->toBe('first')
        ->and($target->name)->toBe('label')
        ->and($target->position)->toBe(1)
        ->and($target->hasDefault)->toBeTrue()
        ->and($target->allowsNull)->toBeFalse()
        ->and($target->declaringContext)->toBe(TargetMetadataExample::class . '::read()')
        ->and($target->reflector())->toBe($reflection);
});

test('missing attributes remain absent even when a different attribute is present', function (): void {
    $target = new ParameterTarget(new ReflectionParameter(
        static fn(#[OtherTargetLabel] string $value): string => $value,
        0,
    ));

    expect($target->hasAttribute(TargetLabel::class))->toBeFalse()
        ->and($target->firstAttribute(TargetLabel::class))->toBeNull()
        ->and($target->declaringContext)->toBe('Closure')
        ->and($target->firstAttribute(OtherTargetLabel::class))->toBeInstanceOf(OtherTargetLabel::class);
});
