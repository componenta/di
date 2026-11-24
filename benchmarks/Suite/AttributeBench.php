<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Componenta\DI\Container;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(7)]
#[Bench\Revs(1000)]
final class AttributeBench
{
    private Container $container;
    private mixed $result;
    private string $entry;
    private array $arguments;
    private array $target;

    public function provideModes(): iterable
    {
        foreach ([false, true] as $uncached) {
            foreach ([false, true] as $targeted) {
                $label = ($uncached ? 'uncached-plan' : 'cached-plan') . ($targeted ? '-targeted' : '-default');
                yield $label => ['uncached' => $uncached, 'targeted' => $targeted];
            }
        }
    }

    public function prepare(array $params): void
    {
        $this->container = Fixtures::container(
            Fixtures::attributeSections($params['uncached']),
            $params['targeted'],
        );
        $this->entry = AttributedEntry::class;
    }

    public function prepareWarm(array $params): void
    {
        $this->prepare($params);
        $this->result = $this->container->make($this->entry);
        $this->verify($params);
    }

    public function verify(array $params): void
    {
        $entry = $this->result;
        Fixtures::check(
            $entry instanceof AttributedEntry
            && $entry->configured === 'configured'
            && $entry->mode === 'default'
            && $entry->number === 7
            && $entry->fresh !== $entry->injected
            && $entry->label === 'example'
            && $entry->initialized === 'initialized'
            && $entry->methodRan
            && $entry->callbackRan,
            'Attribute fixture did not execute as expected.',
        );
    }

    #[Bench\ParamProviders('provideModes')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verify')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstAttributedMake(array $params): void
    {
        $this->result = $this->container->make($this->entry);
    }

    #[Bench\ParamProviders('provideModes')]
    #[Bench\BeforeMethods('prepareWarm')]
    #[Bench\AfterMethods('verify')]
    #[Bench\Warmup(100)]
    public function benchRepeatedAttributedMake(array $params): void
    {
        $this->result = $this->container->make($this->entry);
    }

    public function prepareSkipped(): void
    {
        $this->container = Fixtures::container();
    }

    public function verifySkipped(): void
    {
        Fixtures::check(
            $this->result instanceof SkippedConstructor && $this->result->leaf instanceof Leaf,
            'NoConstructor did not preserve property injection.',
        );
    }

    #[Bench\BeforeMethods('prepareSkipped')]
    #[Bench\AfterMethods('verifySkipped')]
    #[Bench\Warmup(100)]
    public function benchNoConstructor(): void
    {
        $this->result = $this->container->make(SkippedConstructor::class);
    }

    public function provideCallbacks(): iterable
    {
        foreach (['transform', 'inlineTransform', 'cast'] as $method) {
            foreach ([false, true] as $uncached) {
                yield $method . ($uncached ? '-uncached-plan' : '-cached-plan') => [
                    'method' => $method,
                    'uncached' => $uncached,
                ];
            }
        }
    }

    public function prepareCallback(array $params): void
    {
        $this->container = Fixtures::container(Fixtures::attributeSections($params['uncached'], true));
        $this->target = [RequestTargets::class, $params['method']];
        $this->arguments = ['input' => ' example '];
        $this->result = $this->container->call($this->target, $this->arguments);
        $this->verifyCallback($params);
    }

    public function verifyCallback(array $params): void
    {
        Fixtures::check($this->result === 'example', 'The callback or caster did not transform the input.');
    }

    #[Bench\ParamProviders('provideCallbacks')]
    #[Bench\BeforeMethods('prepareCallback')]
    #[Bench\AfterMethods('verifyCallback')]
    #[Bench\Warmup(100)]
    public function benchValueTransformation(array $params): void
    {
        $this->result = $this->container->call($this->target, $this->arguments);
    }
}
