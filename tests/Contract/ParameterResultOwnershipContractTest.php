<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ParameterResultOwnership;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use PHPUnit\Framework\Assert;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class CallableOwner
{
    public static function value(): string
    {
        return 'original';
    }
}

final class Receiver
{
    public int $calls = 0;

    /**
     * @param callable():string $value
     * @param list<int> $next
     * @return array{string,list<int>}
     */
    public function consume(callable $value, array $next): array
    {
        ++$this->calls;
        return [$value(), $next];
    }
}

final class ReferencedResolver implements ParameterResolverInterface
{
    public int $position = 0;
    public mixed $value;
    public int $calls = 0;
    /** @var list<array<int,mixed>> */
    public array $contexts = [];

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value' || $target->name === 'next';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        if ($target->name === 'next') {
            $this->contexts[] = $context->resolved;
            return [$target->position, array_keys($context->resolved)];
        }
        ++$this->calls;
        return [&$this->position, &$this->value];
    }
}

test('parameter result validation owns the tuple it accepted across autoloading and later calls', function (
    string $mutation,
    bool $single,
    bool $uncached,
): void {
    $extension = new ReferencedResolver();
    $builder = (new ContainerBuilder())->addParameterResolver($extension, 2000);
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    [$container, $runtime] = $builder->assemble();
    $receiver = new Receiver();
    $parameters = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $parameters);
    $target = $parameters->target(new ReflectionParameter([Receiver::class, 'consume'], 0));
    $contexts = [];

    foreach ([1, 2] as $attempt) {
        $requested = __NAMESPACE__ . '\\PendingOwner_' . bin2hex(random_bytes(5));
        $original = $requested . '::value';
        $extension->position = 0;
        $extension->value = $original;
        $loads = 0;
        $loader = static function (string $class) use ($requested, $extension, $mutation, &$loads): void {
            if ($class !== $requested) {
                return;
            }
            ++$loads;
            class_alias(CallableOwner::class, $class);
            if ($mutation !== 'value') {
                $extension->position = 9;
            }
            if ($mutation !== 'position') {
                $extension->value = 23;
            }
        };
        spl_autoload_register($loader);

        try {
            $result = $single
                ? $parameters->resolveParameter($target, new ParameterResolutionContext())
                : $container->call([$receiver, 'consume']);

            if ($single) {
                expect($result)->toBe([0, $original]);
                // The accepted tuple is not an alias for the extension's later state.
                $extension->position = 17;
                $extension->value = 'later';
                expect($result)->toBe([0, $original]);
            } else {
                $contexts[] = [0 => $original];
                expect($result)->toBe(['original', [0]])
                    ->and($extension->contexts)->toBe($contexts);
            }
            expect($loads)->toBe(1)
                ->and($extension->calls)->toBe($attempt)
                ->and($receiver->calls)->toBe($single ? 0 : $attempt);
        } finally {
            spl_autoload_unregister($loader);
        }
    }
})->with(['position', 'value', 'both'])
    ->with(['call' => false, 'single parameter' => true])
    ->with(['cached plans' => false, 'non-memoized plans' => true]);

test('parameter result ownership still rejects invalid extension values and positions', function (
    bool $invalidPosition,
    bool $single,
): void {
    $extension = new ReferencedResolver();
    $extension->position = $invalidPosition ? 9 : 0;
    $extension->value = $invalidPosition ? CallableOwner::value(...) : 23;
    [$container, $runtime] = (new ContainerBuilder())->addParameterResolver($extension, 2000)->assemble();
    $receiver = new Receiver();
    $parameters = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $parameters);
    $target = $parameters->target(new ReflectionParameter([Receiver::class, 'consume'], 0));
    $operation = $single
        ? static fn(): array => $parameters->resolveParameter($target, new ParameterResolutionContext())
        : static fn(): mixed => $container->call([$receiver, 'consume']);

    expect($operation)->toThrow(ResolutionException::class, $invalidPosition ? 'expected [position 0, value]' : 'does not satisfy the declared type')
        ->and($extension->calls)->toBe(1)
        ->and($receiver->calls)->toBe(0);

    $extension->position = 0;
    $valid = CallableOwner::value(...);
    $extension->value = $valid;
    expect($operation())->toBe($single ? [0, $valid] : ['original', [0]])
        ->and($extension->calls)->toBe(2)
        ->and($receiver->calls)->toBe($single ? 0 : 1);
})->with(['position' => true, 'value' => false])
    ->with(['call' => false, 'single parameter' => true]);

test('result ownership keeps native nested references and object identity', function (bool $variadic): void {
    $extension = new ReferencedResolver();
    $original = 'before';
    $object = new \stdClass();
    $extension->value = ['object' => $object, 'nested' => ['value' => &$original]];
    $container = (new ContainerBuilder())->addParameterResolver($extension, 2000)->build();
    $callback = $variadic
        ? static fn(mixed ...$value): array => $value
        : static fn(array $value): array => $value;
    $result = $container->call($callback);
    if (!is_array($result) || !is_array($result['nested'] ?? null)) {
        throw new \LogicException('Expected the supplied array graph.');
    }
    $result['nested']['value'] = 'changed';

    expect($result['object'])->toBe($object)
        ->and($original)->toBe('changed')
        ->and($extension->value)->toBe(['object' => $object, 'nested' => ['value' => 'changed']]);
})->with(['ordinary' => false, 'variadic' => true]);

test('variadic result validation retains its accepted collection across tuple reference changes', function (bool $single): void {
    $requested = __NAMESPACE__ . '\\VariadicOwner_' . bin2hex(random_bytes(5));
    $original = $requested . '::value';
    $extension = new ReferencedResolver();
    $extension->value = [$original];
    [$container, $runtime] = (new ContainerBuilder())->addParameterResolver($extension, 2000)->assemble();
    $callback = static fn(callable ...$value): array => array_map(static fn(callable $item): mixed => $item(), $value);
    $loads = 0;
    $loader = static function (string $class) use ($requested, $extension, &$loads): void {
        if ($class === $requested) {
            ++$loads;
            class_alias(CallableOwner::class, $class);
            $extension->position = 9;
            $extension->value = ['invalid'];
        }
    };
    spl_autoload_register($loader);

    try {
        if ($single) {
            $parameters = $runtime->parameters;
            Assert::assertInstanceOf(ParametersResolver::class, $parameters);
            $target = $parameters->target(new ReflectionParameter($callback, 0));
            expect($parameters->resolveParameter($target, new ParameterResolutionContext()))->toBe([0, [$original]]);
        } else {
            expect($container->call($callback))->toBe(['original']);
        }
        expect($loads)->toBe(1)
            ->and($extension->position)->toBe(9)
            ->and($extension->value)->toBe(['invalid']);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['call' => false, 'single parameter' => true]);
