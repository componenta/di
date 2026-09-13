<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Closure;
use Componenta\Config\Config;
use Componenta\Config\ConfigEntry;
use Componenta\Config\ContainerEntry;
use Componenta\Config\ContainerValue;
use Componenta\Config\Environment;
use Componenta\Config\EnvironmentEntry;
use Componenta\Config\LazyValue;
use Componenta\DI\Resolver\Entry\SetUp\ContainerValueUnwrapper;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ReflectionFunction;
use stdClass;

test('setup value unwrapping resolves each supported descriptor in its own context', function (object $descriptor, mixed $expected): void {
    $container = (new ContainerBuilder(new Config(['setting' => 'configured'], new Environment(['VALUE' => 'environment']))))
        ->addService('service', 'container')
        ->build();
    $unwrapper = new ContainerValueUnwrapper($container->get(ContainerValue::class));

    expect($unwrapper->supports($descriptor))->toBeTrue()
        ->and($unwrapper->unwrap($descriptor, 'argument'))->toBe($expected);
})->with([
    [new ContainerEntry('service'), 'container'],
    [new ConfigEntry('setting'), 'configured'],
    [new EnvironmentEntry('VALUE'), 'environment'],
    [new LazyValue(static function (ContainerValue $context): string {
        $service = $context->get('service');
        if (!is_string($service)) {
            throw new \LogicException('Expected a string fixture service.');
        }
        return $service . ':' . $context->config->string('setting');
    }), 'container:configured'],
]);

test('setup value unwrapping leaves unsupported values unchanged', function (mixed $value): void {
    $container = (new ContainerBuilder())->build();
    $unwrapper = new ContainerValueUnwrapper($container->get(ContainerValue::class));

    expect($unwrapper->supports($value))->toBeFalse()
        ->and($unwrapper->unwrap($value, 'argument'))->toBe($value);
})->with([null, 'literal', 7, [['nested' => 'data']], new stdClass()]);

test('setup environment descriptors convert according to the declared parameter type', function (Closure $signature, mixed $raw, mixed $expected): void {
    $container = (new ContainerBuilder(new Config([], new Environment([]))))->build();
    $unwrapper = new ContainerValueUnwrapper($container->get(ContainerValue::class));
    $parameter = (new ReflectionFunction($signature))->getParameters()[0];

    expect($unwrapper->unwrap(new EnvironmentEntry('MISSING', $raw), 'value', $parameter))->toBe($expected);
})->with([
    'integer to string' => [static fn(string $value): string => $value, 7, '7'],
    'string to nullable integer' => [static fn(?int $value): ?int => $value, '7', 7],
    'string to float' => [static fn(float $value): float => $value, '1.25', 1.25],
    'string to boolean' => [static fn(bool $value): bool => $value, 'no', false],
    'comma-separated array' => [static fn(array $value): array => $value, 'a,b', ['a', 'b']],
    'comma-separated variadic values' => [static fn(string ...$value): array => $value, 'a,b', ['a', 'b']],
    'null stays null' => [static fn(?int $value): ?int => $value, null, null],
    'union stays unresolved' => [static fn(int|string $value): int|string => $value, '7', '7'],
    'mixed stays unchanged' => [static fn(mixed $value): mixed => $value, ['a' => 1], ['a' => 1]],
]);
