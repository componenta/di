<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Definition\FactoryValidationContract;

use Closure;
use Componenta\Config\ContainerValue;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ErrorException;

class FactoryScopeParent {}

final class ScopedAuditFactory extends FactoryScopeParent
{
    /** @param array<string|int,mixed> $parameters */
    public static function create(self|ContainerValue $container, array $parameters): object
    {
        return (object) ['parameters' => $parameters];
    }

    public static function closure(): Closure
    {
        return static fn(parent|ContainerValue $container, array $parameters): object => (object) ['parameters' => $parameters];
    }
}

class QualifiedAbiFactory
{
    public static function incompatible(string $context): object
    {
        return (object) ['context' => $context];
    }
}

final class QualifiedAbiReceiver extends QualifiedAbiFactory {}

class_alias(QualifiedAbiFactory::class, QualifiedAbiReceiver::class . '::Alias');

final class ConfiguredMethodTarget
{
    /** @var list<string> */
    public array $calls = [];

    public function apply(string $value): void
    {
        $this->calls[] = $value;
    }

    /** @param array<array-key,mixed> $arguments */
    public function __call(string $method, array $arguments): void
    {
        $this->calls[] = $method;
    }
}

test('class definition validation rejects every malformed method call shape', function (mixed $call): void {
    // @phpstan-ignore argument.type (Intentionally malformed public configuration input.)
    $definition = new ClassDefinition(ConfiguredMethodTarget::class, methodCalls: [$call]);

    expect(fn() => (new ContainerBuilder())->addDefinition('target', $definition)->build())
        ->toThrow(InvalidConfigurationException::class, 'ClassDefinition for "target" contains malformed method call #0.');
})->with([
    'scalar' => [42],
    'empty record' => [[]],
    'missing method' => [['params' => []]],
    'non-string method' => [['method' => 42, 'params' => []]],
    'empty method' => [['method' => '', 'params' => []]],
    'missing params' => [['method' => 'apply']],
    'scalar params' => [['method' => 'apply', 'params' => 42]],
    'null params' => [['method' => 'apply', 'params' => null]],
]);

test('magic method dispatch does not skip validation of later configured calls', function (): void {
    // @phpstan-ignore argument.type (The second call deliberately has an empty method name.)
    $definition = new ClassDefinition(ConfiguredMethodTarget::class, methodCalls: [
        ['method' => 'virtual', 'params' => []],
        ['method' => '', 'params' => []],
    ]);

    expect(fn() => (new ContainerBuilder())->addDefinition('target', $definition)->build())
        ->toThrow(InvalidConfigurationException::class, 'ClassDefinition for "target" contains malformed method call #1.');
});

test('factory validation resolves self and parent types in the callable declaration scope', function (callable $factory): void {
    $container = (new ContainerBuilder())->addFactory('product', $factory)->build();
    $product = $container->make('product', ['count' => 3]);

    expect($product)->toEqual((object) ['parameters' => ['count' => 3]]);
})->with([
    'static method' => [[ScopedAuditFactory::class, 'create']],
    'scoped closure' => [ScopedAuditFactory::closure()],
]);

test('native factory callables reject the extra arguments of the factory contract before invocation', function (): void {
    expect(fn() => (new ContainerBuilder())
        ->addFactory('native', Closure::fromCallable('get_debug_type'))->build())
        ->toThrow(
            InvalidConfigurationException::class,
            'Factory "native" internal callable accepts at most 1 arguments, but the factory runtime supplies 2.',
        );
});

test('factory ABI validation checks the native declaration of qualified callable routes', function (string $route): void {
    $method = QualifiedAbiFactory::class . '::incompatible';
    $builder = new ContainerBuilder();
    $attempt = match ($route) {
        'qualified class pair' => fn() => $builder->addFactory('product', [QualifiedAbiReceiver::class, $method])->build(),
        'qualified object pair' => fn() => $builder->addFactory('product', [new QualifiedAbiReceiver(), $method])->build(),
        'deferred pair' => fn() => $builder->addService('factory.owner', new QualifiedAbiReceiver())
            ->addFactory('product', ['factory.owner', $method])->build()->make('product'),
        default => fn() => $builder->addFactory('product', QualifiedAbiReceiver::class . '::Alias::incompatible')
            ->build()->make('product'),
    };

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_DEPRECATED && str_starts_with($message, 'Callables of the form ')) {
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        expect($attempt)->toThrow(
            InvalidConfigurationException::class,
            'parameter #1 ($context) type "string" is incompatible',
        );
    } finally {
        restore_error_handler();
    }
})->with(['qualified class pair', 'qualified object pair', 'deferred pair', 'separator class alias']);
