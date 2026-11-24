<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\DI\ContainerFactory;
use Nyholm\Psr7\ServerRequest;
use PhpBench\Attributes as Bench;
use Psr\Http\Message\ServerRequestInterface;

#[Bench\Iterations(15)]
#[Bench\Revs(1)]
final class LifecycleBench
{
    private ContainerFactory $factory;
    private Config $config;
    private DependencyDefinitions $definitions;
    private array $provided;
    private ContainerValue $value;
    private mixed $result;

    public function provideWorkloads(): iterable
    {
        foreach ([false, true] as $targeted) {
            $suffix = $targeted ? '-targeted' : '-default';
            foreach (['graph', 'attributes', 'request-dto'] as $operation) {
                yield $operation . $suffix => ['operation' => $operation, 'targeted' => $targeted];
            }
        }
    }

    public function prepare(array $params): void
    {
        $this->factory = new ContainerFactory();
        $this->config = Fixtures::config();
        $this->definitions = new DependencyDefinitions(Fixtures::attributeSections(false));
        $this->provided = [
            ServerRequestInterface::class => (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'mapped']),
        ];
    }

    public function prepareWarm(array $params): void
    {
        $this->prepare($params);
        $this->execute($params);
        $this->verify($params);
        unset($this->value, $this->result);
        gc_collect_cycles();
    }

    private function execute(array $params): void
    {
        $this->value = $params['targeted']
            ? $this->factory->createWithTargetedAttributeDiscovery($this->config, $this->definitions)
            : $this->factory->create($this->config, $this->definitions);
        $container = $this->value->container;
        $this->result = match ($params['operation']) {
            'graph' => $container->get(Graph::class),
            'attributes' => $container->get(AttributedEntry::class),
            'request-dto' => $container->call([RequestTargets::class, 'dtoCallback'], $this->provided),
        };
    }

    public function verify(array $params): void
    {
        if ($params['operation'] === 'graph') {
            Fixtures::verifyEntry(Graph::class, $this->result);
            return;
        }
        $valid = match ($params['operation']) {
            'attributes' => $this->result instanceof AttributedEntry
                && $this->result->configured === 'configured'
                && $this->result->fresh !== $this->result->injected
                && $this->result->methodRan
                && $this->result->callbackRan,
            'request-dto' => $this->result instanceof MappedDto && $this->result->value === 'mapped-callback',
        };
        Fixtures::check($valid, 'Unexpected lifecycle result.');
    }

    #[Bench\ParamProviders('provideWorkloads')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verify')]
    public function benchFirstLifecycle(array $params): void
    {
        $this->execute($params);
    }

    #[Bench\ParamProviders('provideWorkloads')]
    #[Bench\BeforeMethods('prepareWarm')]
    #[Bench\AfterMethods('verify')]
    public function benchFirstLifecycleInWarmProcess(array $params): void
    {
        $this->execute($params);
    }
}
