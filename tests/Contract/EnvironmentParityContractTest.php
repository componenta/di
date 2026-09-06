<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\NotFoundException;

final readonly class EnvironmentParityProduct
{
    /** @param resource $resource */
    public function __construct(
        public string $name,
        public object $capture,
        public mixed $resource,
        public string $runtime,
    ) {}
}

/**
 * @param resource $resource
 * @return array{
 *     config_identity: bool,
 *     environment_identity: bool,
 *     environment_mode: bool,
 *     config_has_dependencies: bool,
 *     shared_identity: bool,
 *     fresh_identity: bool,
 *     name: string,
 *     capture_identity: bool,
 *     resource_identity: bool,
 *     fresh_runtime: string,
 *     failure: array{class-string<NotFoundException>, string}
 * }
 */
function environmentParitySnapshot(
    string $mode,
    object $capture,
    mixed $resource,
): array {
    $environment = new Environment(['APP_ENV' => $mode]);
    $config = new Config(['app.name' => 'same'], $environment);
    $container = (new ContainerFactory())->create(
        $config,
        new DependencyDefinitions([
            ConfigKey::SERVICES => [
                'runtime.capture' => $capture,
                'runtime.resource' => $resource,
            ],
            ConfigKey::FACTORIES => [
                EnvironmentParityProduct::class => static function (
                    ContainerValue $value,
                    array $params,
                ): EnvironmentParityProduct {
                    $capture = $value->container->get('runtime.capture');
                    $resource = $value->container->get('runtime.resource');
                    $runtime = $params['runtime'] ?? 'shared';
                    if (!is_object($capture) || !is_resource($resource) || !is_string($runtime)) {
                        throw new \LogicException('The environment parity factory received invalid runtime values.');
                    }

                    return new EnvironmentParityProduct(
                        $value->config->string('app.name'),
                        $capture,
                        $resource,
                        $runtime,
                    );
                },
            ],
        ]),
    )->container;

    if (!$container instanceof Container) {
        throw new \RuntimeException('ContainerFactory returned an unsupported container implementation.');
    }

    $shared = $container->get(EnvironmentParityProduct::class);
    $fresh = $container->make(EnvironmentParityProduct::class, ['runtime' => 'fresh']);

    try {
        $container->get('missing.service');
        throw new \LogicException('The missing service unexpectedly resolved.');
    } catch (NotFoundException $exception) {
        $failure = [$exception::class, $exception->getMessage()];
    }

    return [
        'config_identity' => $container->get(Config::class) === $config,
        'environment_identity' => $container->get(Environment::class) === $environment,
        'environment_mode' => $container->get(Environment::class)->get('APP_ENV') === $mode,
        'config_has_dependencies' => $config->has(ConfigKey::DEPENDENCIES),
        'shared_identity' => $container->get(EnvironmentParityProduct::class) === $shared,
        'fresh_identity' => $fresh !== $shared,
        'name' => $shared->name,
        'capture_identity' => $shared->capture === $capture && $fresh->capture === $capture,
        'resource_identity' => $shared->resource === $resource && $fresh->resource === $resource,
        'fresh_runtime' => $fresh->runtime,
        'failure' => $failure,
    ];
}

test('development and production modes keep the same observable DI semantics', function (): void {
    $capture = new \stdClass();
    $resource = fopen('php://memory', 'r+');
    if ($resource === false) {
        throw new \RuntimeException('Unable to open the environment parity fixture.');
    }

    try {
        $development = environmentParitySnapshot('development', $capture, $resource);
        $production = environmentParitySnapshot('production', $capture, $resource);

        expect($development)->toBe($production)
            ->and($development)->toMatchArray([
                'config_identity' => true,
                'environment_identity' => true,
                'environment_mode' => true,
                'config_has_dependencies' => false,
                'shared_identity' => true,
                'fresh_identity' => true,
                'name' => 'same',
                'capture_identity' => true,
                'resource_identity' => true,
                'fresh_runtime' => 'fresh',
            ])
            ->and($development['failure'][0] ?? null)->toBe(NotFoundException::class);
    } finally {
        fclose($resource);
    }
});
