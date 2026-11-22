<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionException;
use ReflectionMethod;
use ReflectionObject;
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
    $pipeline = (new ContainerBuilder())->assemble()[1]->objects;
    Assert::assertInstanceOf(ObjectPipeline::class, $pipeline);

    expect(fn() => $pipeline->prepare(PipelineInvalidConstruction::class))
        ->toThrow(AttributeCompositionException::class, 'cannot target constructor parameter $request');
});

test('object pipeline accepts class-name inputs for eligibility and construction', function (): void {
    $pipeline = (new ContainerBuilder())->assemble()[1]->objects;
    Assert::assertInstanceOf(ObjectPipeline::class, $pipeline);
    expect($pipeline->canCreate(PipelineSourceProduct::class))->toBeTrue()
        ->and($pipeline->create(PipelineSourceProduct::class, ['value' => 7]))->toEqual(new PipelineSourceProduct(7));
});

final readonly class PipelineRequestedProduct
{
    public function __construct(public string $requested = 'requested default') {}
}

final readonly class PipelineOtherProduct
{
    public function __construct(public string $other = 'other default') {}
}

test('an object reflector cannot change the class or constructor plan of a suspended creation', function (): void {
    [$container, $runtime] = (new ContainerBuilder())->assemble();
    $source = new ReflectionObject(new PipelineRequestedProduct());
    $fiber = new Fiber(static fn(): object => $runtime->objects->create(
        $source,
        resolveParameters: static function (): array {
            Fiber::suspend('waiting for arguments');
            return ['requested' => 'supplied'];
        },
    ));

    expect($fiber->start())->toBe('waiting for arguments');
    new ReflectionMethod($source, '__construct')->invoke($source, new PipelineOtherProduct());
    $fiber->resume();

    expect($container->make(PipelineOtherProduct::class))->toEqual(new PipelineOtherProduct())
        ->and($fiber->getReturn())->toEqual(new PipelineRequestedProduct('supplied'));
});

test('class-name admission is independent of the warm object-plan cache', function (string $operation): void {
    $pipeline = (new ContainerBuilder())->assemble()[1]->objects;
    $invalid = '\\\\' . PipelineRequestedProduct::class;
    $valid = '\\' . PipelineRequestedProduct::class;
    $method = new ReflectionMethod($pipeline, $operation);

    expect(fn() => $method->invoke($pipeline, $invalid))->toThrow(ReflectionException::class);
    $pipeline->prepare(PipelineRequestedProduct::class);

    expect(fn() => $method->invoke($pipeline, $invalid))->toThrow(ReflectionException::class)
        ->and($pipeline->create($valid))->toEqual(new PipelineRequestedProduct());
})->with(['prepare', 'canCreate', 'create']);

test('the pipeline rejects two argument sources before calling either source or the configuration callback', function (string $class): void {
    if (!is_a($class, PipelineSourceProduct::class, true)) {
        throw new \LogicException('Expected a pipeline product.');
    }
    $pipeline = (new ContainerBuilder())->assemble()[1]->objects;
    $sources = 0;
    $configures = 0;

    expect(fn() => $pipeline->create(
        $class,
        ['value' => 1],
        configure: static function () use (&$configures): void {
            ++$configures;
        },
        resolveParameters: static function () use (&$sources): array {
            ++$sources;
            return ['value' => 2];
        },
    ))->toThrow(InvalidConfigurationException::class, 'one source of construction parameters')
        ->and($sources)->toBe(0)
        ->and($configures)->toBe(0);
})->with([PipelineSourceProduct::class, PipelineLazySourceProduct::class, PipelineProxySourceProduct::class]);

test('deferred object initialization retries the parameter source after configuration fails', function (string $class): void {
    if (!is_a($class, PipelineSourceProduct::class, true)) {
        throw new \LogicException('Expected a deferred pipeline fixture.');
    }
    $pipeline = (new ContainerBuilder())->assemble()[1]->objects;
    Assert::assertInstanceOf(ObjectPipeline::class, $pipeline);
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

final readonly class PipelineAttemptSource
{
    public function __construct(public int $generation) {}
}

final class PipelineFiberState
{
    public bool $suspend = true;
}

abstract class PipelineCancellableProduct
{
    public function __construct(public PipelineAttemptSource $source, \Closure $onConstruct)
    {
        $onConstruct();
    }
}

#[Lazy]
final class PipelineCancellableLazy extends PipelineCancellableProduct {}

#[Proxy]
final class PipelineCancellableProxy extends PipelineCancellableProduct {}

test('deferred initialization owns one source snapshot per completed or cancelled Fiber attempt', function (string $class, string $completion): void {
    if (!is_a($class, PipelineCancellableProduct::class, true)) {
        throw new \LogicException('Expected a cancellable pipeline fixture.');
    }
    $pipeline = (new ContainerBuilder())->assemble()[1]->objects;
    Assert::assertInstanceOf(ObjectPipeline::class, $pipeline);
    $sources = 0;
    $references = [];
    $state = new PipelineFiberState();
    $onConstruct = static function () use ($state): void {
        if ($state->suspend) {
            \Fiber::suspend('constructing');
        }
    };
    $entry = $pipeline->create($class, resolveParameters: static function () use (&$sources, &$references, $onConstruct): array {
        $source = new PipelineAttemptSource(++$sources);
        $references[] = \WeakReference::create($source);
        return ['source' => $source, 'onConstruct' => $onConstruct];
    });
    if (!$entry instanceof PipelineCancellableProduct) {
        throw new \LogicException('Expected a deferred cancellable product.');
    }
    $fiber = new \Fiber(static fn(): int => $entry->source->generation);

    expect($sources)->toBe(0)
        ->and($fiber->start())->toBe('constructing')
        ->and($sources)->toBe(1);

    if ($completion === 'resume') {
        $fiber->resume();
        expect($fiber->getReturn())->toBe(1);
    } elseif ($completion === 'throw') {
        $cause = new RuntimeException('interrupt constructor');
        try {
            $fiber->throw($cause);
            throw new \LogicException('The interrupted constructor must fail.');
        } catch (\Componenta\DI\Exception\ResolutionException $error) {
            expect($error->getPrevious())->toBe($cause);
        }
        unset($error);
    }
    $fiberReference = \WeakReference::create($fiber);
    unset($fiber);
    gc_collect_cycles();

    expect($fiberReference->get())->toBeNull()
        ->and((new \ReflectionClass($entry))->isUninitializedLazyObject($entry))->toBe($completion !== 'resume');

    $state->suspend = false;
    $expectedGeneration = $completion === 'resume' ? 1 : 2;
    expect($entry->source->generation)->toBe($expectedGeneration)
        ->and($sources)->toBe($expectedGeneration)
        ->and($entry->source->generation)->toBe($expectedGeneration)
        ->and($sources)->toBe($expectedGeneration);
    if ($completion !== 'resume') {
        expect($references[0]->get())->toBeNull();
    }
    unset($entry);
    gc_collect_cycles();
    expect(array_all($references, static fn(\WeakReference $reference): bool => $reference->get() === null))->toBeTrue();
})->with([PipelineCancellableLazy::class, PipelineCancellableProxy::class])
    ->with(['cancel', 'resume', 'throw']);
