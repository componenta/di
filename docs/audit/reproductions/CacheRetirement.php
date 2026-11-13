<?php

declare(strict_types=1);

namespace Componenta\DI\Audit\CacheRetirement;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use LogicException;
use Throwable;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

final readonly class Source
{
    public function __construct(public string $value) {}
}

final class Wrapper
{
    public static bool $throw = true;

    public function __construct(public readonly Source $source, private readonly string $name) {}

    public function __destruct()
    {
        if (self::$throw && $this->name === 'a' && $this->source->value === 'old') {
            throw new LogicException('retired cached wrapper failed');
        }
    }
}

$container = (new ContainerFactory())->create(
    new Config([], new Environment([])),
    new DependencyDefinitions([
        ConfigKey::SERVICES => ['source' => new Source('old')],
        ConfigKey::ALIASES => ['a' => 'source', 'b' => 'source'],
        ConfigKey::DELEGATORS => [
            'a' => [static fn(Source $source): Wrapper => new Wrapper($source, 'a')],
            'b' => [static fn(Source $source): Wrapper => new Wrapper($source, 'b')],
        ],
    ]),
)->container;
if (!$container instanceof Container) {
    throw new LogicException('Expected the Componenta container.');
}
$before = [$container->get('a')->source->value, $container->get('b')->source->value];
$failure = null;
try {
    $container->set('source', new Source('new'));
} catch (Throwable $exception) {
    $failure = [$exception::class, $exception->getMessage()];
} finally {
    // Keep an intentional diagnostic destructor out of process-shutdown errors.
    Wrapper::$throw = false;
}

echo json_encode([
    'php' => PHP_VERSION,
    'before' => $before,
    'failure' => $failure,
    'after' => [
        'a' => $container->get('a')->source->value,
        'b' => $container->get('b')->source->value,
        'source' => $container->get('source')->value,
    ],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
