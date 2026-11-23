<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures\ClassDefinitionCold;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Tests\Support\SubprocessAutoload;
use LogicException;
use stdClass;

final class Product
{
    /** @var array{nested:array{value:string,literal:object}}|null */
    public private(set) ?array $configured = null;

    /** @param array{nested:array{value:string,literal:object}} $payload */
    public function __construct(public readonly array $payload) {}

    /** @param array{nested:array{value:string,literal:object}} $payload */
    public function configure(array $payload): void
    {
        $this->configured = $payload;
    }
}

if (!isset($argv[1])) {
    throw new LogicException('Expected the autoload path.');
}
require_once dirname(__DIR__) . '/Support/SubprocessAutoload.php';
SubprocessAutoload::load($argv[1]);
if (class_exists(ResolutionMetadata::class, false)) {
    throw new LogicException('Expected the autoload trigger to be cold.');
}
$value = 'selected';
$literal = new stdClass();
$nested = ['value' => &$value, 'literal' => $literal];
$arguments = ['payload' => ['nested' => &$nested]];
$autoloads = 0;
$loader = static function (string $name) use (&$value, &$autoloads): void {
    if ($name === ResolutionMetadata::class) {
        $value = 'rebound';
        ++$autoloads;
    }
};
spl_autoload_register($loader, true, true);
try {
    $definition = new ClassDefinition(Product::class, $arguments, [['method' => 'configure', 'params' => $arguments]]);
    // Exercise the same callback when the descriptor does not need the helper.
    class_exists(ResolutionMetadata::class);
} finally {
    spl_autoload_unregister($loader);
}
$container = new ContainerFactory()->create(new Config([], new Environment([])), new DependencyDefinitions([]))->container;
if (!$container instanceof Container) {
    throw new LogicException('Expected the native container.');
}
$container->set('product', $definition);
$product = $container->make('product');
if (!$product instanceof Product || $product->configured === null) {
    throw new LogicException('Expected the configured class definition product.');
}
echo json_encode([
    'autoloads' => $autoloads,
    'caller' => $value,
    'constructor' => $product->payload['nested']['value'],
    'configured_method' => $product->configured['nested']['value'],
    'keeps_literal_identity' => $product->payload['nested']['literal'] === $literal
        && $product->configured['nested']['literal'] === $literal,
], JSON_THROW_ON_ERROR);
