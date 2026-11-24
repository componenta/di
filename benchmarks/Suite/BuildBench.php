<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\DI\ConfigKey;
use Componenta\DI\Configuration\DependencyConfiguration;
use Componenta\DI\ContainerFactory;
use PhpBench\Attributes as Bench;

#[Bench\Iterations(7)]
final class BuildBench
{
    private Config $config;
    private DependencyDefinitions $definitions;
    private ContainerFactory $factory;
    private array $sections;
    private ContainerValue $result;
    private array $normalized;

    public function provideSections(): iterable
    {
        yield 'empty' => ['size' => 0, 'shape' => 'empty'];
        yield 'representative' => ['size' => 0, 'shape' => 'mixed'];
        foreach ([32, 512, 2048] as $size) {
            yield 'alias-chain-' . $size => ['size' => $size, 'shape' => 'chain'];
            yield 'services-' . $size => ['size' => $size, 'shape' => 'services'];
        }
    }

    public function prepare(array $params): void
    {
        $this->config = Fixtures::config();
        $this->factory = new ContainerFactory();
        $this->sections = match ($params['shape']) {
            'empty' => [],
            'mixed' => Fixtures::sections(),
            'chain' => [ConfigKey::SERVICES => ['terminal' => 7], ConfigKey::ALIASES => []],
            'services' => [ConfigKey::SERVICES => []],
        };
        for ($i = 0; $i < $params['size']; ++$i) {
            if ($params['shape'] === 'chain') {
                $this->sections[ConfigKey::ALIASES]['alias.' . $i] = $i + 1 === $params['size']
                    ? 'terminal'
                    : 'alias.' . ($i + 1);
            } else {
                $this->sections[ConfigKey::SERVICES]['service.' . $i] = $i;
            }
        }
        $this->definitions = new DependencyDefinitions($this->sections);
    }

    public function verifyBuild(array $params): void
    {
        $container = $this->result->container;
        $valid = match ($params['shape']) {
            'empty' => $container->has(Leaf::class),
            'mixed' => $container->get('bench.decorated') === 16,
            'chain' => $container->get('alias.0') === 7,
            'services' => $container->get('service.' . ($params['size'] - 1)) === $params['size'] - 1,
        };
        Fixtures::check($valid, 'Invalid assembled container.');
    }

    #[Bench\ParamProviders('provideSections')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyBuild')]
    #[Bench\Revs(1)]
    #[Bench\Iterations(15)]
    public function benchFirstAssembly(array $params): void
    {
        $this->result = $this->factory->create($this->config, $this->definitions);
    }

    #[Bench\ParamProviders('provideSections')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyBuild')]
    #[Bench\Revs(20)]
    #[Bench\Warmup(1)]
    public function benchRepeatedAssembly(array $params): void
    {
        $this->result = $this->factory->create($this->config, $this->definitions);
    }

    #[Bench\ParamProviders('provideSections')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyBuild')]
    #[Bench\Revs(20)]
    #[Bench\Warmup(1)]
    public function benchTargetedAssembly(array $params): void
    {
        $this->result = $this->factory->createWithTargetedAttributeDiscovery($this->config, $this->definitions);
    }

    public function verifyNormalized(array $params): void
    {
        $valid = match ($params['shape']) {
            'empty' => $this->normalized === [],
            'mixed' => $this->normalized[ConfigKey::SERVICES]['bench.value'] === 7
                && isset($this->normalized[ConfigKey::FACTORIES]['bench.definition']),
            'chain' => count($this->normalized[ConfigKey::ALIASES]) === $params['size'],
            'services' => count($this->normalized[ConfigKey::SERVICES]) === $params['size'],
        };
        Fixtures::check($valid, 'Unexpected normalized dependency configuration.');
    }

    #[Bench\ParamProviders('provideSections')]
    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyNormalized')]
    #[Bench\Revs(100)]
    public function benchNormalize(array $params): void
    {
        $this->normalized = DependencyConfiguration::normalize($this->sections);
    }
}
