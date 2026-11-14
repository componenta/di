<?php

declare(strict_types=1);

namespace Componenta\DI\Audit\MutationParity;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\NotFoundException;
use LogicException;
use Psr\Container\ContainerInterface;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

// Run from the repository root: php -n docs/audit/reproductions/MutationParity.php
// Twelve deterministic seeds compare 100 mutations across 24 warmed entries
// with fresh containers that replay exactly the same mutation history.
final readonly class Value
{
    public function __construct(public string $value = 'invokable') {}

    public function __invoke(self $entry): self
    {
        return new self($entry->value . '[' . $this->value . ']');
    }
}

final readonly class ExternalValue implements ContainerInterface
{
    public function __construct(private string $id, private Value $value) {}

    public function has(string $id): bool
    {
        return $id === $this->id;
    }

    public function get(string $id): Value
    {
        if (!$this->has($id)) {
            throw new NotFoundException($id);
        }
        return $this->value;
    }
}

function initialContainer(): Container
{
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([]),
    )->container;
    if (!$container instanceof Container) {
        throw new LogicException('Expected the Componenta container.');
    }
    foreach (range(0, 7) as $index) {
        $container->set('base.' . $index, new Value('base:' . $index));
        $container->set('decorator.' . $index, new Value('decorator:' . $index));
        $container->alias('alias.' . $index, 'base.' . $index);
    }
    return $container;
}

/** @param array{string, string, string} $operation */
function applyOperation(Container $container, array $operation): void
{
    [$action, $id, $value] = $operation;
    match ($action) {
        'set' => $container->set($id, new Value($value)),
        'alias' => $container->alias($id, $value),
        'decorate' => $container->delegator($id, static fn(Value $entry): Value => new Value($entry->value . ':' . $value)),
        'defer' => $container->delegator($id, $value),
        'factory' => $container->set($id, Definition::factory(static fn(): Value => new Value($value))),
        'definition' => $container->set($id, ClassDefinition::create(Value::class)->constructor(['value' => $value])),
        'invokable' => $container->set($id, Definition::invokable(Value::class)),
        'external' => $container->addContainer(new ExternalValue($id, new Value($value))),
        default => throw new LogicException('Unexpected operation.'),
    };
}

function observed(Container $container, string $id): string
{
    $value = $container->get($id);
    if (!$value instanceof Value) {
        throw new LogicException('Unexpected resolved value for ' . $id);
    }
    return $value->value;
}

$ids = [];
foreach (['base', 'decorator', 'alias'] as $kind) {
    foreach (range(0, 7) as $index) {
        $ids[] = $kind . '.' . $index;
    }
}

$operations = 0;
$comparisons = 0;
foreach (range(1, 12) as $seed) {
    $random = new Randomizer(new Mt19937($seed));
    $warm = initialContainer();
    $history = [];
    for ($step = 0; $step < 100; ++$step) {
        foreach ($ids as $id) {
            observed($warm, $id);
        }
        $index = $random->getInt(0, 7);
        $action = $random->getInt(0, 9);
        $operation = match ($action) {
            0 => ['set', 'base.' . $index, "changed:$seed:$step"],
            1 => ['set', 'decorator.' . $index, "changed:$seed:$step"],
            2 => ['alias', 'alias.' . $index, 'base.' . $random->getInt(0, 7)],
            3 => ['decorate', ($random->getInt(0, 1) ? 'alias.' : 'base.') . $index, "suffix:$seed:$step"],
            4 => ['defer', ($random->getInt(0, 1) ? 'alias.' : 'base.') . $index, 'decorator.' . $random->getInt(0, 7)],
            5 => ['decorate', 'decorator.' . $index, "transitive:$seed:$step"],
            6 => ['factory', ($random->getInt(0, 1) ? 'decorator.' : 'base.') . $index, "factory:$seed:$step"],
            7 => ['definition', ($random->getInt(0, 1) ? 'decorator.' : 'base.') . $index, "definition:$seed:$step"],
            8 => ['invokable', ($random->getInt(0, 1) ? 'decorator.' : 'base.') . $index, 'invokable'],
            9 => ['external', 'decorator.' . $index, "external:$seed:$step"],
        };
        applyOperation($warm, $operation);
        $history[] = $operation;
        ++$operations;
        $cold = initialContainer();
        foreach ($history as $previous) {
            applyOperation($cold, $previous);
        }
        foreach ($ids as $id) {
            $actual = observed($warm, $id);
            $expected = observed($cold, $id);
            ++$comparisons;
            if ($actual !== $expected) {
                throw new RuntimeException(json_encode(
                    compact('seed', 'step', 'id', 'actual', 'expected', 'history'),
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT,
                ));
            }
        }
    }
}

echo json_encode(['php' => PHP_VERSION, 'operations' => $operations, 'comparisons' => $comparisons], JSON_THROW_ON_ERROR), "\n";
