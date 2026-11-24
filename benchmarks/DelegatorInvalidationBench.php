<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;

final class BenchStaticDecorator
{
    public static function decorate(object $value): object
    {
        return $value;
    }
}

$autoload = getenv('COMPONENTA_DI_BENCH_AUTOLOAD');
$source = getenv('COMPONENTA_DI_BENCH_SOURCE');
if (!is_string($autoload) || !is_file($autoload) || !is_string($source) || !is_dir($source)) {
    throw new RuntimeException('Set COMPONENTA_DI_BENCH_AUTOLOAD and COMPONENTA_DI_BENCH_SOURCE.');
}
(require __DIR__ . '/autoload.php')($autoload, $source);

$value = new stdClass();
$factory = new ContainerFactory();
printf("PHP %s, source %s\n", PHP_VERSION, $source);

foreach (['exact-id', 'late-native'] as $route) {
    $container = $factory->create(new Config([], new Environment([])), new DependencyDefinitions([]))->container;
    if (!$container instanceof Container) {
        throw new RuntimeException('Expected the Componenta container.');
    }
    $container->set('product', $value);
    if ($route === 'exact-id') {
        $container->set('decorator', static fn(object $value): object => $value);
        $container->delegator('product', 'decorator');
    } else {
        $container->delegator('product', 'LateBenchDecorator::decorate');
        class_alias(BenchStaticDecorator::class, 'LateBenchDecorator');
    }
    if ($container->get('product') !== $value) {
        throw new RuntimeException('The delegator must keep the product identity.');
    }

    $samples = [];
    for ($round = 0; $round < 7; ++$round) {
        gc_collect_cycles();
        $start = hrtime(true);
        for ($index = 0; $index < 10_000; ++$index) {
            $container->set('product', $value);
            $container->get('product');
        }
        $samples[] = (hrtime(true) - $start) / 10_000;
    }
    sort($samples, SORT_NUMERIC);
    printf("%-14s %9.1f ns\n", $route, $samples[3]);
}
