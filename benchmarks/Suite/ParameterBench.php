<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use PhpBench\Attributes as Bench;

final class ParameterBench
{
    private Container $container;
    private array $target;
    private array $arguments;
    private mixed $expected;
    private mixed $result;

    public function provideParameters(): iterable
    {
        yield 'defaults-nullable' => ['method' => 'defaults', 'arguments' => [], 'expected' => [7, null]];
        yield 'named' => ['method' => 'defaults', 'arguments' => ['number' => 9, 'name' => 'named'], 'expected' => [9, 'named']];
        yield 'positional' => ['method' => 'defaults', 'arguments' => [9, 'positional'], 'expected' => [9, 'positional']];
        yield 'variadic' => ['method' => 'variadic', 'arguments' => [1, 2, 3, 4], 'expected' => [1, 2, 3, 4]];
        yield 'union-no-candidate' => ['method' => 'union', 'arguments' => [], 'expected' => false];
        yield 'union-one-candidate' => ['method' => 'union', 'arguments' => [], 'expected' => 'candidate'];
        yield 'custom-resolver' => ['method' => 'custom', 'arguments' => [], 'expected' => 'tenant-1'];
    }

    public function prepare(array $params): void
    {
        $sections = Fixtures::sections();
        if ($params['method'] === 'custom') {
            $sections[ConfigKey::PARAMETER_RESOLVERS] = [
                10000 => new AttributeMatchingParameterResolver(
                    TenantId::class,
                    static fn(ParameterTarget $target, ParameterResolutionContext $context): array => [
                        $target->position,
                        'tenant-1',
                    ],
                ),
            ];
        }
        $this->container = Fixtures::container($sections);
        $this->arguments = $params['arguments'];
        $this->target = [ParameterTargets::class, $params['method']];
        $this->expected = $params['expected'];
        if ($this->expected === 'candidate') {
            $this->expected = new LeftCandidate();
            $this->container->set(BenchLeft::class, $this->expected);
        }
    }

    public function prepareWarm(array $params): void
    {
        $this->prepare($params);
        $this->result = $this->container->call($this->target, $this->arguments);
        $this->verify($params);
    }

    public function verify(array $params): void
    {
        Fixtures::check($this->result === $this->expected, 'Unexpected parameter resolution result.');
    }

    #[Bench\ParamProviders('provideParameters')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verify')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstParameterResolution(array $params): void
    {
        $this->result = $this->container->call($this->target, $this->arguments);
    }

    #[Bench\ParamProviders('provideParameters')]
    #[Bench\BeforeMethods('prepareWarm')]
    #[Bench\AfterMethods('verify')]
    #[Bench\Warmup(100)]
    public function benchRepeatedParameterResolution(array $params): void
    {
        $this->result = $this->container->call($this->target, $this->arguments);
    }

    public function prepareMissing(): void
    {
        $this->container = Fixtures::container();
    }

    public function verifyMissing(): void
    {
        Fixtures::check($this->result instanceof NotFoundException, 'Missing entry did not throw the expected exception.');
    }

    #[Bench\BeforeMethods('prepareMissing')]
    #[Bench\AfterMethods('verifyMissing')]
    #[Bench\Warmup(100)]
    public function benchMissingEntry(): void
    {
        $this->result = null;
        try {
            $this->container->get('bench.missing');
        } catch (NotFoundException $exception) {
            $this->result = $exception;
        }
    }
}
