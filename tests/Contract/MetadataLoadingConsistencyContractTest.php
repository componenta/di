<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class MetadataLoadingMarker {}

abstract class MetadataLoadingResult
{
    public string $value = 'ready';
}

test('constructor policies loaded while inspecting members apply to the first container operation', function (string $operation, bool $parameter): void {
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
    $loader = static function (string $requested) use ($trigger, $policy): void {
        if ($requested === __NAMESPACE__ . '\\' . $trigger) {
            class_alias(MetadataLoadingMarker::class, $requested);
            class_alias(NoConstructor::class, __NAMESPACE__ . '\\' . $policy);
        }
    };
    $builder = new ContainerBuilder();
    if ($operation === 'definition') {
        $builder->addDefinition('metadata.target', ClassDefinition::create($class));
        expect(fn() => $builder->build())->toThrow(\Componenta\DI\Exception\InvalidConfigurationException::class, 'runtime-ineligible');
        return;
    }
    $container = $builder->build();
    spl_autoload_register($loader);

    try {
        if ($operation === 'has') {
            expect($container->has($class))->toBeTrue();
        }
        $first = $container->make($operation === 'definition' ? 'metadata.target' : $class);
        $second = $container->make($class);
        if (!$first instanceof MetadataLoadingResult || !$second instanceof MetadataLoadingResult) {
            throw new LogicException('Expected the declared result type.');
        }

        expect($first->value)->toBe('ready')
            ->and($second->value)->toBe('ready')
            ->and($first)->not->toBe($second);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with([
    'has, property attribute' => ['has', false],
    'make, property attribute' => ['make', false],
    'ClassDefinition, property attribute' => ['definition', false],
    'make, constructor parameter attribute' => ['make', true],
]);
