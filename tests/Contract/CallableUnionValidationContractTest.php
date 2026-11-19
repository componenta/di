<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CallableUnionValidation;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Container;
use Componenta\DI\Tests\Support\ContainerBuilder;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class CacheControl {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

function container(bool $uncached): Container
{
    $builder = new ContainerBuilder();
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(CacheControl::class, rules: [new NoOpRule()]));
    }
    return $builder->build();
}

/** @param callable|array<array-key,mixed> $value */
function readArray(callable|array $value): mixed
{
    return $value;
}

function readString(callable|string $value): mixed
{
    return $value;
}

/** @param callable|iterable<array-key,mixed> $value */
function readIterable(callable|iterable $value): mixed
{
    return $value;
}

test('callable unions accept an alternative native type without autoloading it as a callback', function (
    string $kind,
    callable $target,
    bool $uncached,
): void {
    $class = __NAMESPACE__ . '\\UnnecessaryOwner_' . bin2hex(random_bytes(5));
    $input = $kind === 'string' ? $class . '::run' : [$class, 'run'];
    $loads = 0;
    $loader = static function (string $requested) use ($class, &$loads): void {
        if ($requested === $class) {
            ++$loads;
            throw new \DomainException('An already accepted union value must not require callable autoloading.');
        }
    };
    spl_autoload_register($loader);
    $container = container($uncached);

    try {
        expect($target($input))->toBe($input)->and($loads)->toBe(0);
        foreach (['value', 0] as $key) {
            foreach ([1, 2] as $_attempt) {
                expect($container->call($target, [$key => $input]))->toBe($input)
                    ->and($loads)->toBe(0);
            }
        }
    } finally {
        spl_autoload_unregister($loader);
    }
})->with([
    'array' => ['array', __NAMESPACE__ . '\\readArray'],
    'string' => ['string', __NAMESPACE__ . '\\readString'],
    'iterable' => ['iterable', __NAMESPACE__ . '\\readIterable'],
])->with(['cached plans' => false, 'non-memoized plans' => true]);

final class AutoloadedOwner
{
    public static function value(): string
    {
        return 'loaded';
    }
}

test('callable unions still validate callable values that need their callable arm', function (
    string $kind,
    callable $target,
): void {
    $class = __NAMESPACE__ . '\\RequiredOwner_' . bin2hex(random_bytes(5));
    $input = $kind === 'string' ? [$class, 'value'] : $class . '::value';
    $loads = 0;
    $loader = static function (string $requested) use ($class, &$loads): void {
        if ($requested === $class) {
            ++$loads;
            class_alias(AutoloadedOwner::class, $class);
        }
    };
    spl_autoload_register($loader);
    $container = container(false);

    try {
        foreach (['value', 0] as $key) {
            $resolved = $container->call($target, [$key => $input]);
            if (!is_callable($resolved)) {
                throw new \LogicException('Expected the validated callable union value.');
            }
            expect($resolved)->toBe($input)
                ->and($resolved())->toBe('loaded')
                ->and($loads)->toBe(1);
        }
    } finally {
        spl_autoload_unregister($loader);
    }
})->with([
    'array' => ['array', __NAMESPACE__ . '\\readArray'],
    'string' => ['string', __NAMESPACE__ . '\\readString'],
    'iterable' => ['iterable', __NAMESPACE__ . '\\readIterable'],
]);
