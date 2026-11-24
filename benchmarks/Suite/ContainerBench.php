<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Componenta\DI\Container;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(7)]
#[Bench\Revs(1000)]
final class ContainerBench
{
    private Container $container;
    private mixed $result;
    private mixed $previous;

    public function provideEntries(): iterable
    {
        foreach ([
            'value' => 'bench.value',
            'null' => 'bench.null',
            'false' => 'bench.false',
            'autowire-leaf' => Leaf::class,
            'autowire-product' => Product::class,
            'autowire-graph' => Graph::class,
            'factory' => 'bench.factory',
            'definition' => 'bench.definition',
            'definition-autowire' => 'bench.autowire',
            'definition-methods' => 'bench.configured',
            'invokable' => EmptyEntry::class,
            'alias' => 'bench.alias',
            'delegators' => 'bench.decorated',
        ] as $label => $id) {
            yield $label => ['id' => $id];
        }
    }

    public function provideHas(): iterable
    {
        yield from $this->provideEntries();
        yield 'missing' => ['id' => 'bench.missing'];
    }

    public function provideMake(): iterable
    {
        foreach ([
            'reflection' => Product::class,
            'factory' => 'bench.factory',
            'definition' => 'bench.definition',
            'definition-autowire' => 'bench.autowire',
        ] as $label => $id) {
            yield $label . '-default' => ['id' => $id, 'args' => [], 'number' => 7];
            yield $label . '-override' => ['id' => $id, 'args' => ['number' => 42], 'number' => 42];
        }
        yield 'definition-methods' => ['id' => 'bench.configured', 'args' => [], 'number' => null];
        yield 'invokable' => ['id' => EmptyEntry::class, 'args' => [], 'number' => null];
        yield 'graph' => ['id' => Graph::class, 'args' => [], 'number' => null];
    }

    public function prepare(array $params): void
    {
        $this->container = Fixtures::container(Fixtures::sections());
    }

    public function prepareShared(array $params): void
    {
        $this->prepare($params);
        $this->previous = $this->container->get($params['id']);
        Fixtures::verifyEntry($params['id'], $this->previous);
    }

    public function prepareHas(array $params): void
    {
        $this->prepare($params);
        $this->container->has($params['id']);
    }

    public function prepareMake(array $params): void
    {
        $this->prepare($params);
        $this->previous = $this->container->make($params['id'], $params['args']);
        $this->verifyProduct($params, $this->previous);
    }

    private function verifyProduct(array $params, mixed $result): void
    {
        $valid = match ($params['id']) {
            EmptyEntry::class => $result instanceof EmptyEntry,
            'bench.configured' => $result instanceof ConfiguredProduct
                && $result->tags === ['first', 'second']
                && $result->parts['leaf'] === $result->leaf,
            Graph::class => $result instanceof Graph && $result->left->leaf === $result->right->leaf,
            default => $result instanceof Product && $result->number === $params['number'],
        };
        Fixtures::check($valid, 'Unexpected make() result.');
    }

    public function verifyGet(array $params): void
    {
        Fixtures::verifyEntry($params['id'], $this->result);
    }

    public function verifyShared(array $params): void
    {
        $this->verifyGet($params);
        Fixtures::check($this->result === $this->previous, 'Shared result was replaced.');
    }

    public function verifyHas(array $params): void
    {
        Fixtures::check($this->result === ($params['id'] !== 'bench.missing'), 'Incorrect has() result.');
    }

    public function verifyColdMake(array $params): void
    {
        $this->verifyProduct($params, $this->result);
    }

    public function verifyFresh(array $params): void
    {
        $this->verifyColdMake($params);
        Fixtures::check($this->result !== $this->previous, 'make() reused the previous fixture.');
    }

    #[Bench\ParamProviders('provideEntries')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyGet')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstGet(array $params): void
    {
        $this->result = $this->container->get($params['id']);
    }

    #[Bench\ParamProviders('provideEntries')]
    #[Bench\BeforeMethods('prepareShared')]
    #[Bench\AfterMethods('verifyShared')]
    #[Bench\Revs(10000)]
    #[Bench\Warmup(100)]
    public function benchSharedGet(array $params): void
    {
        $this->result = $this->container->get($params['id']);
    }

    #[Bench\ParamProviders('provideHas')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyHas')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstHas(array $params): void
    {
        $this->result = $this->container->has($params['id']);
    }

    #[Bench\ParamProviders('provideHas')]
    #[Bench\BeforeMethods('prepareHas')]
    #[Bench\AfterMethods('verifyHas')]
    #[Bench\Revs(10000)]
    #[Bench\Warmup(100)]
    public function benchRepeatedHas(array $params): void
    {
        $this->result = $this->container->has($params['id']);
    }

    #[Bench\ParamProviders('provideMake')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyColdMake')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstMake(array $params): void
    {
        $this->result = $this->container->make($params['id'], $params['args']);
    }

    #[Bench\ParamProviders('provideMake')]
    #[Bench\BeforeMethods('prepareMake')]
    #[Bench\AfterMethods('verifyFresh')]
    #[Bench\Warmup(100)]
    public function benchRepeatedMake(array $params): void
    {
        $this->result = $this->container->make($params['id'], $params['args']);
    }
}
