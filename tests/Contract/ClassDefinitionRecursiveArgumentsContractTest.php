<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use LogicException;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Support\container;

final class RecursiveArgumentsProduct
{
    /** @param array<array-key,mixed> $payload */
    public function __construct(public array $payload = []) {}

    /** @param array<array-key,mixed> $payload */
    public function configure(array $payload): void
    {
        $this->payload = $payload;
    }
}

test('class definitions reject recursive arrays in explicit arguments without exhausting the process', function (bool $method): void {
    $container = container();
    $container->addContainer(new class () implements ContainerInterface {
        private int $visits = 0;

        public function has(string $id): bool
        {
            return $id === 'recursion-probe';
        }

        public function get(string $id): mixed
        {
            if (++$this->visits > 3) {
                throw new LogicException('Recursive argument traversal did not stop.');
            }
            return 'visited';
        }
    });
    $payload = ['probe' => Definition::reference('recursion-probe')];
    $payload['self'] = &$payload;
    $definition = ClassDefinition::create(RecursiveArgumentsProduct::class);
    $definition = $method
        ? $definition->call('configure', ['payload' => $payload])
        : $definition->constructor(['payload' => $payload]);
    $container->set('recursive-arguments', $definition);

    expect(fn() => $container->get('recursive-arguments'))
        ->toThrow(InvalidConfigurationException::class, 'recursive array');
})->with(['constructor' => false, 'configured method' => true]);

test('class definitions allow sibling references to the same non-recursive argument array', function (): void {
    $container = container();
    $dependency = new \stdClass();
    $container->set('shared-dependency', $dependency);
    $shared = ['dependency' => Definition::reference('shared-dependency')];
    $payload = ['left' => &$shared, 'right' => &$shared];
    $container->set('shared-arguments', ClassDefinition::create(RecursiveArgumentsProduct::class)->constructor(['payload' => $payload]));

    $first = $container->make('shared-arguments');
    $second = $container->make('shared-arguments');

    expect($first)->toBeInstanceOf(RecursiveArgumentsProduct::class)
        ->and($second)->toBeInstanceOf(RecursiveArgumentsProduct::class);
    if (!$first instanceof RecursiveArgumentsProduct || !$second instanceof RecursiveArgumentsProduct) {
        throw new LogicException('Expected configured products.');
    }
    $expected = ['left' => ['dependency' => $dependency], 'right' => ['dependency' => $dependency]];
    expect($first->payload)->toBe($expected)
        ->and($second->payload)->toBe($expected);
});

test('class definition runtime overrides preserve recursive arrays as literal values', function (): void {
    $container = container();
    $container->set('runtime-arguments', ClassDefinition::create(RecursiveArgumentsProduct::class)->constructor([
        'payload' => Definition::reference('unused-missing-entry'),
    ]));
    $payload = ['value' => 'runtime'];
    $payload['self'] = &$payload;

    $product = $container->make('runtime-arguments', ['payload' => $payload]);

    expect($product)->toBeInstanceOf(RecursiveArgumentsProduct::class);
    if (!$product instanceof RecursiveArgumentsProduct) {
        throw new LogicException('Expected a configured product.');
    }
    $nested = $product->payload['self'];
    if (!is_array($nested)) {
        throw new LogicException('Expected the literal recursive array.');
    }
    expect($nested['value'])->toBe('runtime');
});
