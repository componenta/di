<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Componenta\DI\Container;
use Componenta\DI\PreparedCallable;
use PhpBench\Attributes as Bench;

final class CallableBench
{
    private Container $container;
    private mixed $target;
    private array $arguments;
    private mixed $result;

    public function provideCallables(): iterable
    {
        foreach (['closure', 'function-string', 'method', 'static-string', 'class-method', 'invokable', 'service-id', 'prepared'] as $kind) {
            yield $kind => ['kind' => $kind];
        }
    }

    public function prepare(array $params): void
    {
        $this->container = Fixtures::container(Fixtures::sections());
        $handler = new Handler();
        $this->arguments = ['number' => 7];
        $this->target = match ($params['kind']) {
            'closure' => static fn(Leaf $leaf, int $number): int => $number,
            'function-string' => __NAMESPACE__ . '\benchmarkHandler',
            'method' => [$handler, 'method'],
            'static-string' => Handler::class . '::staticMethod',
            'class-method' => [Handler::class, 'method'],
            'invokable' => $handler,
            'service-id' => 'bench.handler',
            'prepared' => new PreparedCallable($handler),
        };
        if ($params['kind'] === 'prepared') {
            $this->arguments['leaf'] = new Leaf();
        }
    }

    public function prepareWarm(array $params): void
    {
        $this->prepare($params);
        Fixtures::check($this->container->call($this->target, $this->arguments) === 7, 'Incorrect callable fixture.');
    }

    public function verify(array $params): void
    {
        Fixtures::check($this->result === 7, 'Incorrect callable result.');
    }

    public function verifyResolved(array $params): void
    {
        Fixtures::check(is_callable($this->result), 'Invalid resolved callable.');
        Fixtures::check(($this->result)(new Leaf(), 7) === 7, 'Incorrect resolved callable.');
    }

    #[Bench\ParamProviders('provideCallables')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verify')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstCall(array $params): void
    {
        $this->result = $this->container->call($this->target, $this->arguments);
    }

    #[Bench\ParamProviders('provideCallables')]
    #[Bench\BeforeMethods('prepareWarm')]
    #[Bench\AfterMethods('verify')]
    #[Bench\Warmup(100)]
    public function benchRepeatedCall(array $params): void
    {
        $this->result = $this->container->call($this->target, $this->arguments);
    }

    #[Bench\ParamProviders('provideCallables')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyResolved')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstResolve(array $params): void
    {
        $this->result = $this->container->resolve($this->target);
    }

    #[Bench\ParamProviders('provideCallables')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyResolved')]
    #[Bench\Warmup(100)]
    public function benchRepeatedResolve(array $params): void
    {
        $this->result = $this->container->resolve($this->target);
    }

    #[Bench\BeforeMethods('prepareFresh')]
    #[Bench\AfterMethods('verifyFresh')]
    #[Bench\Warmup(100)]
    public function benchFreshClosure(): void
    {
        $this->result = $this->container->call(
            static fn(Leaf $leaf, int $number): int => $number,
            $this->arguments,
        );
    }

    public function prepareFresh(): void
    {
        $this->prepare(['kind' => 'closure']);
    }

    public function verifyFresh(): void
    {
        $this->verify([]);
    }
}
