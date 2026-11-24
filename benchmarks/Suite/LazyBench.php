<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Closure;
use Componenta\DI\Container;
use PhpBench\Attributes as Bench;
use ReflectionClass;

#[Bench\Iterations(7)]
#[Bench\Revs(1000)]
final class LazyBench
{
    private Container $container;
    private Closure $initializer;
    private Closure $factory;
    private LazyValue $object;
    private int $value;

    public function provideStrategies(): iterable
    {
        foreach (['ghost', 'proxy', 'attribute-ghost', 'attribute-proxy', 'parameter-proxy', 'property-proxy'] as $strategy) {
            yield $strategy => ['strategy' => $strategy];
        }
    }

    public function prepare(array $params): void
    {
        $this->container = Fixtures::container();
        $this->initializer = static function (LazyValue $object): void {
            $object->__construct(7);
        };
        $this->factory = static fn(LazyValue $proxy): LazyValue => new LazyValue(7);
    }

    private function allocate(string $strategy): LazyValue
    {
        return match ($strategy) {
            'ghost' => $this->container->makeLazy(LazyValue::class, $this->initializer),
            'proxy' => $this->container->makeProxy(LazyValue::class, $this->factory),
            'attribute-ghost' => $this->container->make(GhostEntry::class),
            'attribute-proxy' => $this->container->make(ProxyEntry::class),
            'parameter-proxy' => $this->container->make(ProxyParameterEntry::class)->value,
            'property-proxy' => $this->container->make(ProxyPropertyEntry::class)->value,
        };
    }

    public function verifyAllocation(array $params): void
    {
        Fixtures::check(
            (new ReflectionClass($this->object))->isUninitializedLazyObject($this->object),
            'Lazy allocation executed its initializer.',
        );
    }

    public function prepareFirstAccess(array $params): void
    {
        $this->prepare($params);
        $this->object = $this->allocate($params['strategy']);
        $this->verifyAllocation($params);
    }

    public function prepareWarmAccess(array $params): void
    {
        $this->prepareFirstAccess($params);
        $this->value = $this->object->read();
        $this->verifyRead($params);
    }

    public function verifyRead(array $params): void
    {
        Fixtures::check(
            $this->value === 7 && !(new ReflectionClass($this->object))->isUninitializedLazyObject($this->object),
            'Lazy initialization returned an invalid value.',
        );
    }

    #[Bench\ParamProviders('provideStrategies')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyAllocation')]
    #[Bench\Warmup(100)]
    public function benchAllocate(array $params): void
    {
        $this->object = $this->allocate($params['strategy']);
    }

    #[Bench\ParamProviders('provideStrategies')]
    #[Bench\BeforeMethods('prepareFirstAccess')]
    #[Bench\AfterMethods('verifyRead')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstAccess(array $params): void
    {
        $this->value = $this->object->read();
    }

    #[Bench\ParamProviders('provideStrategies')]
    #[Bench\BeforeMethods('prepareWarmAccess')]
    #[Bench\AfterMethods('verifyRead')]
    #[Bench\Revs(10000)]
    #[Bench\Warmup(100)]
    public function benchInitializedAccess(array $params): void
    {
        $this->value = $this->object->read();
    }
}
