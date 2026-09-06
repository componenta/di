<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\ConfigFactory;
use Componenta\Config\ConfigProvider;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;

final class PipelineFirstDecorator
{
    public function __invoke(string $entry): string
    {
        return $entry . ':first';
    }

    public function decorate(string $entry): string
    {
        return $entry . ':method';
    }
}

final class PipelineFirstProvider extends ConfigProvider
{
    public function __construct(private readonly bool $useObject = false) {}

    protected function getServices(): array
    {
        return [
            'pipeline.product' => 'base',
            PipelineFirstDecorator::class => new PipelineFirstDecorator(),
        ];
    }

    protected function getDelegators(): array
    {
        return [
            'pipeline.product' => [
                $this->useObject ? new PipelineFirstDecorator() : PipelineFirstDecorator::class,
            ],
        ];
    }
}

final class PipelineSecondProvider extends ConfigProvider
{
    protected function getServices(): array
    {
        return ['decorate' => static fn(string $entry): string => $entry . ':second'];
    }

    protected function getDelegators(): array
    {
        return ['pipeline.product' => ['decorate']];
    }
}

test('two provider delegators remain a pipeline when they also resemble a callable pair', function (
    bool $useObject,
): void {
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        new PipelineFirstProvider($useObject),
        new PipelineSecondProvider(),
    );
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies)->container;

    expect($container->get('pipeline.product'))->toBe('base:first:second');
})->with([
    'service id and method-shaped id' => [false],
    'callable object and method-shaped id' => [true],
]);

test('nested service and object method pairs remain individual pipeline entries', function (): void {
    $provider = new class () extends ConfigProvider {
        protected function getServices(): array
        {
            return [
                'pipeline.product' => 'base',
                PipelineFirstDecorator::class => new PipelineFirstDecorator(),
            ];
        }

        protected function getDelegators(): array
        {
            return [
                'pipeline.product' => [
                    [PipelineFirstDecorator::class, 'decorate'],
                    [new PipelineFirstDecorator(), 'decorate'],
                ],
            ];
        }
    };
    $composition = (new ConfigFactory())->create(new Environment([]), $provider);
    $container = (new ContainerFactory())->create($composition->config, $composition->dependencies)->container;

    expect($container->get('pipeline.product'))->toBe('base:method:method');
});
