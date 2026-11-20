<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures\ContainerFactoryCompletion;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Internal\CycleGuard;
use Componenta\DI\Tests\Support\SubprocessAutoload;
use LogicException;
use ReflectionClass;
use RuntimeException;
use Throwable;

if (!isset($argv[1], $argv[2])) {
    throw new LogicException('Expected the autoload and source paths.');
}
require_once dirname(__DIR__) . '/Support/SubprocessAutoload.php';
SubprocessAutoload::load($argv[1]);
$source = realpath($argv[2]);
$factoryFile = new ReflectionClass(ContainerFactory::class)->getFileName();
if ($source === false || $factoryFile === false || realpath($factoryFile) !== $source . DIRECTORY_SEPARATOR . 'ContainerFactory.php') {
    throw new LogicException('The completion fixture must load the selected checkout source.');
}
if (class_exists(CycleGuard::class, false)) {
    throw new LogicException('The cycle guard must not already be loaded.');
}

$cause = new RuntimeException('Cold cycle guard loading failed.');
$loader = static function (string $class) use ($cause): void {
    if ($class === CycleGuard::class) {
        throw $cause;
    }
};
$config = new Config([], new Environment([]));
$factory = new ContainerFactory();
$failure = null;
spl_autoload_register($loader, true, true);
try {
    $factory->create($config, new DependencyDefinitions([]));
} catch (Throwable $error) {
    $failure = $error;
} finally {
    spl_autoload_unregister($loader);
}

$retry = $factory->create($config, new DependencyDefinitions([]));
$originalFailurePreserved = false;
for ($error = $failure; $error !== null; $error = $error->getPrevious()) {
    if ($error === $cause) {
        $originalFailurePreserved = true;
        break;
    }
}
echo json_encode([
    'failed_before_return' => $failure instanceof InvalidConfigurationException,
    'original_failure_preserved' => $originalFailurePreserved,
    'retry_has_own_core' => $retry->container->get(Container::class) === $retry->container,
    'retry_has_same_config' => $retry->container->get(Config::class) === $config,
], JSON_THROW_ON_ERROR);
