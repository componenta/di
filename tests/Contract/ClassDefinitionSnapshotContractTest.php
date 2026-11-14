<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ClassDefinitionSnapshot;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

final class Product
{
    public string $method = '';
    /** @var array<array-key,mixed> */
    public array $configured = [];

    /** @param array<array-key,mixed> $payload */
    public function __construct(public array $payload) {}

    /** @param array<array-key,mixed> $payload */
    public function configure(array $payload): void
    {
        $this->method = 'configure';
        $this->configured = $payload;
    }

    /** @param array<array-key,mixed> $payload */
    public function alternate(array $payload): void
    {
        $this->method = 'alternate';
        $this->configured = $payload;
    }
}

function product(mixed $value): Product
{
    if (!$value instanceof Product) {
        throw new LogicException('Expected a configured product.');
    }
    return $value;
}

test('class definition admission snapshots nested argument and method routing references', function (
    bool $runtime,
    bool $warm,
): void {
    $label = 'constructor';
    $methodLabel = 'method';
    $payload = ['nested' => ['label' => &$label]];
    $method = 'configure';
    $methodParams = ['payload' => ['label' => &$methodLabel]];
    $call = ['method' => &$method, 'params' => &$methodParams];
    $definition = new ClassDefinition(Product::class, ['payload' => &$payload], [&$call]);
    $builder = new ContainerBuilder();
    if (!$runtime) {
        $builder->addDefinition('product', $definition);
    }
    $container = $builder->build();
    if ($runtime) {
        $container->set('product', $definition);
    }
    $shared = $warm ? $container->get('product') : null;

    $label = 'changed constructor';
    $methodLabel = 'changed method';
    $payload['extra'] = 'unregistered';
    $methodParams['payload']['extra'] = 'unregistered';
    $method = 'alternate';
    $resolved = product($container->get('product'));
    $fresh = product($container->make('product'));

    foreach ([$resolved, $fresh] as $value) {
        expect($value->payload)->toBe(['nested' => ['label' => 'constructor']])
            ->and($value->method)->toBe('configure')
            ->and($value->configured)->toBe(['label' => 'method']);
    }
    if ($warm) {
        expect($resolved)->toBe($shared);
    }
})->with(['bootstrap' => false, 'runtime' => true])
    ->with(['cold' => false, 'warm' => true]);

test('shared configured array edges keep literal objects and fresh service references across resolutions', function (): void {
    $literal = new \stdClass();
    $initial = new \stdClass();
    $replacement = new \stdClass();
    $shared = ['literal' => $literal, 'dependency' => Definition::reference('dependency')];
    $payload = ['left' => &$shared, 'right' => &$shared];
    $container = (new ContainerBuilder())
        ->addService('dependency', $initial)
        ->addDefinition('product', ClassDefinition::create(Product::class)
            ->constructor(['payload' => $payload])
            ->call('configure', ['payload' => $payload]))
        ->build();

    $shared['literal'] = new \stdClass();
    $shared['extra'] = 'unregistered';
    $first = product($container->make('product'));
    $container->set('dependency', $replacement);
    $literal->changed = true;
    $second = product($container->make('product'));
    $third = product($container->make('product'));

    foreach ([$first, $second, $third] as $index => $value) {
        $expected = ['literal' => $literal, 'dependency' => $index === 0 ? $initial : $replacement];
        expect($value->payload)->toBe(['left' => $expected, 'right' => $expected])
            ->and($value->configured)->toBe(['left' => $expected, 'right' => $expected]);
    }
});

test('recursive configuration stays deferred and stable while runtime overrides remain literal', function (bool $mutual): void {
    $payload = ['label' => 'configured'];
    if ($mutual) {
        $other = ['back' => &$payload];
        $payload['cycle'] = &$other;
    } else {
        $payload['cycle'] = &$payload;
    }
    $container = (new ContainerBuilder())
        ->addDefinition('product', ClassDefinition::create(Product::class)->constructor(['payload' => $payload]))
        ->build();

    // Breaking the caller's graph cannot change the admitted configuration.
    $payload = ['label' => 'changed'];
    if ($mutual) {
        $other = ['label' => 'changed'];
    }
    expect(fn() => $container->get('product'))->toThrow(InvalidConfigurationException::class, 'recursive array')
        ->and(fn() => $container->make('product'))->toThrow(InvalidConfigurationException::class, 'recursive array');

    $literal = Definition::reference('unused.runtime.reference');
    $override = ['literal' => $literal];
    $override['cycle'] = &$override;
    $resolved = product($container->make('product', ['payload' => $override]));
    $nested = $resolved->payload['cycle'];
    if (!is_array($nested)) {
        throw new LogicException('Expected the literal recursive runtime value.');
    }
    expect($resolved->payload['literal'])->toBe($literal)
        ->and($nested['literal'])->toBe($literal);
})->with(['self reference' => false, 'mutual reference' => true]);
