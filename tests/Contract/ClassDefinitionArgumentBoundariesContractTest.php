<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\ReferenceDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class ArgumentDiagnosticProduct
{
    public function __construct(public string $known = 'default') {}

    public function configure(string $known = 'default'): void
    {
        $this->known = $known;
    }
}

final class ArgumentDiagnosticEmpty {}

final readonly class NativeVariadicDefinitionProduct
{
    /** @var array<array-key,mixed> */
    public array $items;

    public function __construct(public string $prefix = 'default', mixed ...$items)
    {
        $this->items = $items;
    }
}

test('unknown definition arguments identify the complete constructor or method and argument keys', function (ClassDefinition $definition, string $target): void {
    $container = (new ContainerBuilder())->addDefinition('product', $definition)->build();

    try {
        $container->make('product');
        \PHPUnit\Framework\Assert::fail('The unknown definition arguments must fail.');
    } catch (InvalidConfigurationException $exception) {
        expect($exception->getMessage())->toBe('Unknown ClassDefinition arguments for ' . $target . ': unknown, 7.')
            ->and($exception->getPrevious())->toBeNull();
    }
})->with([
    [
        new ClassDefinition(ArgumentDiagnosticProduct::class, ['unknown' => 'first', 7 => 'second']),
        ArgumentDiagnosticProduct::class . '::__construct()',
    ],
    [
        ClassDefinition::create(ArgumentDiagnosticProduct::class)->call('configure', ['unknown' => 'first', 7 => 'second']),
        ArgumentDiagnosticProduct::class . '::configure()',
    ],
    [
        new ClassDefinition(ArgumentDiagnosticEmpty::class, ['unknown' => 'first', 7 => 'second']),
        'a class without a constructor',
    ],
]);

test('definition variadics resolve configured references but keep runtime values literal', function (bool $positional): void {
    $service = new \stdClass();
    $runtimeValue = new ReferenceDefinition('not-a-resolvable-service');
    $configured = ['prefix' => 'prefix', 'configured' => new ReferenceDefinition('value')];
    $runtime = ['runtime' => $runtimeValue];
    if ($positional) {
        $configured[1] = new ReferenceDefinition('value');
        $runtime[1] = $runtimeValue;
    }
    $container = (new ContainerBuilder())
        ->addService('value', $service)
        ->addDefinition('product', new ClassDefinition(NativeVariadicDefinitionProduct::class, $configured))
        ->build();
    $entry = $container->make('product', $runtime);
    if (!$entry instanceof NativeVariadicDefinitionProduct) {
        throw new \LogicException('Expected the variadic definition product.');
    }

    expect($entry->prefix)->toBe('prefix')
        ->and($entry->items)->toBe($positional
            ? [0 => $runtimeValue, 'configured' => $service, 'runtime' => $runtimeValue]
            : ['configured' => $service, 'runtime' => $runtimeValue]);
})->with([false, true]);

test('positional definition variadics fill omitted defaults and retain named trailing arguments', function (): void {
    $container = (new ContainerBuilder())
        ->addDefinition('product', new ClassDefinition(NativeVariadicDefinitionProduct::class, [1 => 'first']))
        ->build();
    $entry = $container->make('product', [2 => 'second', 'tail' => 'third']);
    if (!$entry instanceof NativeVariadicDefinitionProduct) {
        throw new \LogicException('Expected the variadic definition product.');
    }

    expect($entry->prefix)->toBe('default')
        ->and($entry->items)->toBe(['first', 'second', 'tail' => 'third']);
});
