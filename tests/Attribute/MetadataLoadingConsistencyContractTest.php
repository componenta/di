<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\MetadataLoadingConsistencyContract;

use Attribute;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class MetadataLoadingMarker {}

abstract class MetadataLoadingResult
{
    public string $value = 'ready';
}

test('constructor policies loaded while inspecting members apply to the first container operation', function (bool $inspectFirst, bool $parameter): void {
    $suffix = bin2hex(random_bytes(5));
    $target = 'MetadataLoadingTarget_' . $suffix;
    $policy = 'MetadataLoadingPolicy_' . $suffix;
    $trigger = 'MetadataLoadingTrigger_' . $suffix;
    $property = $parameter ? '' : '#[' . $trigger . '] public string $marker;';
    $argument = $parameter ? '#[' . $trigger . '] string $unused' : '';
    eval(sprintf(
        'namespace %s; #[%s] final class %s extends MetadataLoadingResult { %s private function __construct(%s) { throw new \LogicException("Constructor must be skipped."); } }',
        __NAMESPACE__,
        $policy,
        $target,
        $property,
        $argument,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, MetadataLoadingResult::class, true)) {
        throw new LogicException('Expected the metadata loading fixture.');
    }
    $loads = 0;
    $loader = static function (string $requested) use ($trigger, $policy, &$loads): void {
        if ($requested === __NAMESPACE__ . '\\' . $trigger) {
            ++$loads;
            class_alias(MetadataLoadingMarker::class, $requested);
            class_alias(NoConstructor::class, __NAMESPACE__ . '\\' . $policy);
        }
    };
    $container = new ContainerBuilder()->build();
    spl_autoload_register($loader);

    try {
        if ($inspectFirst) {
            expect($container->has($class))->toBeTrue();
        }
        $first = $container->make($class);
        $second = $container->make($class);
        if (!$first instanceof MetadataLoadingResult || !$second instanceof MetadataLoadingResult) {
            throw new LogicException('Expected the declared result type.');
        }

        expect($first->value)->toBe('ready')
            ->and($second->value)->toBe('ready')
            ->and($first)->not->toBe($second)
            ->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with([
    'has, property attribute' => [true, false],
    'make, property attribute' => [false, false],
    'make, constructor parameter attribute' => [false, true],
]);
