<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

class PipelineSourceProduct
{
    public bool $configured = false;

    public function __construct(public int $value) {}
}

#[Lazy]
final class PipelineLazySourceProduct extends PipelineSourceProduct {}

#[Proxy]
final class PipelineProxySourceProduct extends PipelineSourceProduct {}

final class PipelineInvalidConstruction
{
    public function __construct(#[CurrentRequest] public ServerRequestInterface $request) {}
}

test('object pipeline preparation validates constructor attributes without constructing an object', function (): void {
    $pipeline = (new ContainerBuilder())->build()->get(ObjectPipeline::class);

    expect(fn() => $pipeline->prepare(PipelineInvalidConstruction::class))
        ->toThrow(AttributeCompositionException::class, 'cannot target constructor parameter $request');
});

test('object pipeline exposes constructor targets and accepts class-name inputs', function (): void {
    $pipeline = (new ContainerBuilder())->build()->get(ObjectPipeline::class);
    $targets = $pipeline->constructorTargets(PipelineSourceProduct::class);

    expect(array_map(static fn(ParameterTarget $target): string => $target->name, $targets))->toBe(['value'])
        ->and($pipeline->canCreate(PipelineSourceProduct::class))->toBeTrue()
        ->and($pipeline->create(PipelineSourceProduct::class, ['value' => 7]))->toEqual(new PipelineSourceProduct(7));
});

test('deferred object initialization retries the parameter source after configuration fails', function (string $class): void {
    if (!is_a($class, PipelineSourceProduct::class, true)) {
        throw new \LogicException('Expected a deferred pipeline fixture.');
    }
    $pipeline = (new ContainerBuilder())->build()->get(ObjectPipeline::class);
    $sources = 0;
    $configures = 0;
    $entry = $pipeline->create(
        $class,
        configure: static function (object $entry) use (&$configures): void {
            if (++$configures === 1) {
                throw new RuntimeException('configuration failed');
            }
            if (!$entry instanceof PipelineSourceProduct) {
                throw new \LogicException('Unexpected pipeline product.');
            }
            $entry->configured = true;
        },
        resolveParameters: static function () use (&$sources): array {
            return ['value' => ++$sources];
        },
    );
    if (!$entry instanceof PipelineSourceProduct) {
        throw new \LogicException('Unexpected deferred pipeline product.');
    }

    expect($sources)->toBe(0)
        ->and($configures)->toBe(0)
        ->and(fn() => $entry->value)->toThrow(RuntimeException::class, 'configuration failed')
        ->and($sources)->toBe(1)
        ->and($configures)->toBe(1)
        ->and($entry->value)->toBe(2)
        ->and($entry->configured)->toBeTrue()
        ->and($sources)->toBe(2)
        ->and($configures)->toBe(2)
        ->and($entry->value)->toBe(2);
})->with([PipelineLazySourceProduct::class, PipelineProxySourceProduct::class]);
