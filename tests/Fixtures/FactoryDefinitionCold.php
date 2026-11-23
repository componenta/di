<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixtures\FactoryDefinitionCold;

use Componenta\DI\Definition\Definition;
use Componenta\DI\Definition\FactoryDefinition;
use Componenta\DI\Internal\ResolutionMetadata;
use Componenta\DI\Tests\Support\SubprocessAutoload;
use LogicException;

final class Factory
{
    public function __construct(public string $label) {}

    public function create(): string
    {
        return 'create:' . $this->label;
    }

    public function alternate(): string
    {
        return 'alternate:' . $this->label;
    }
}

if (!isset($argv[1], $argv[2])) {
    throw new LogicException('Expected the autoload path and factory definition route.');
}
require_once dirname(__DIR__) . '/Support/SubprocessAutoload.php';
SubprocessAutoload::load($argv[1]);
$trigger = match ($argv[2]) {
    'constructor' => ResolutionMetadata::class,
    'facade' => FactoryDefinition::class,
    default => throw new LogicException('Unexpected factory definition route.'),
};
if (class_exists($trigger, false)) {
    throw new LogicException('Expected the autoload trigger to be cold.');
}
$original = new Factory('original');
$replacement = new Factory('replacement');
$owner = $original;
$method = 'create';
$specification = [1 => &$method, 0 => &$owner];
$autoloads = 0;
$loader = static function (string $name) use ($trigger, $replacement, &$owner, &$method, &$autoloads): void {
    if ($name === $trigger) {
        ++$autoloads;
        $owner = $replacement;
        $method = 'alternate';
    }
};
spl_autoload_register($loader, true, true);
try {
    $definition = $argv[2] === 'constructor' ? new FactoryDefinition($specification) : Definition::factory($specification);
    // Also exercise the caller's rebinding when the definition does not need the helper.
    class_exists($trigger);
} finally {
    spl_autoload_unregister($loader);
}
$original->label = 'updated business state';
$callback = $definition->value;
if (!is_array($callback)) {
    throw new LogicException('Expected an array callable.');
}
echo json_encode([
    'autoloads' => $autoloads,
    'caller_rebound' => $owner === $replacement && $method === 'alternate',
    'retained_receiver' => $callback[0] === $original,
    'key_order' => array_keys($callback),
    'product' => $callback(),
], JSON_THROW_ON_ERROR);
