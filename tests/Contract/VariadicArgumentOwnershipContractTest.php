<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\VariadicArgumentOwnership;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
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
        return 'middle';
    }
}

final class Receiver
{
    public int $calls = 0;

    /**
     * @param callable():string ...$values
     * @return array<array-key,string>
     */
    public function consume(callable ...$values): array
    {
        ++$this->calls;
        return array_map(static fn(callable $value): string => $value(), $values);
    }
}

final class CollectionResolver implements ParameterResolverInterface
{
    /** @var array<array-key,mixed> */
    public array $values = [];

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'values';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return [$target->position, $this->values];
    }
}

test('variadic validation owns all argument slots before an autoloader can change their references', function (
    int $mutated,
    string $route,
    bool $uncached,
): void {
    $extension = new CollectionResolver();
    $builder = new ContainerBuilder();
    if (str_starts_with($route, 'extension')) {
        $builder->addParameterResolver($extension, 2000);
    }
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    [$container, $runtime] = $builder->assemble();
    $receiver = new Receiver();
    $parameters = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $parameters);
    $target = $parameters->target(new ReflectionParameter([Receiver::class, 'consume'], 0));
    $single = str_ends_with($route, 'single');

    foreach ([1, 2] as $attempt) {
        $nativeResult = null;
        foreach (['native', 'resolved'] as $operation) {
            $requested = __NAMESPACE__ . '\\PendingOwner_' . bin2hex(random_bytes(5));
            $first = static fn(): string => 'first';
            $middle = $requested . '::value';
            $last = static fn(): string => 'last';
            $cells = [$first, $middle, $last];
            $values = [4 => &$cells[0], 9 => &$cells[1], 'named' => &$cells[2]];
            $extension->values = $values;
            $loads = 0;
            $loader = static function (string $class) use ($requested, $mutated, &$cells, &$loads): void {
                if ($class === $requested) {
                    ++$loads;
                    class_alias(CallableOwner::class, $class);
                    $cells[$mutated] = 17;
                }
            };
            spl_autoload_register($loader);

            try {
                if ($operation === 'native') {
                    $native = new Receiver();
                    // @phpstan-ignore argument.type (The registered loader supplies the deferred callable during native validation.)
                    $nativeResult = $native->consume(...$values);
                    expect($nativeResult)->toBe([0 => 'first', 1 => 'middle', 'named' => 'last'])
                        ->and($native->calls)->toBe(1);
                } elseif ($single) {
                    $result = $parameters->resolveParameter($target, new ParameterResolutionContext(provided: ['values' => $values]));
                    $expected = [0, [4 => $first, 9 => $middle, 'named' => $last]];
                    expect($result)->toBe($expected);
                    // Later changes to the caller's slots cannot replace accepted arguments.
                    $cells[0] = 23;
                    $cells[1] = 23;
                    $cells[2] = 23;
                    expect($result)->toBe($expected)
                        ->and($receiver->calls)->toBe(0);
                } else {
                    expect($container->call([$receiver, 'consume'], ['values' => $values]))->toBe($nativeResult)
                        ->and($receiver->calls)->toBe($attempt);
                }
                expect($loads)->toBe(1)
                    ->and($cells[$mutated])->toBe($operation === 'resolved' && $single ? 23 : 17);
            } finally {
                spl_autoload_unregister($loader);
            }
        }
    }
})->with(['earlier argument' => 0, 'current argument' => 1, 'later argument' => 2])
    ->with(['provided call', 'provided single', 'extension call', 'extension single'])
    ->with(['cached plans' => false, 'non-memoized plans' => true]);

test('owning variadic slots preserves nested references object identity and single parameter array state', function (): void {
    $original = 'before';
    $object = new \stdClass();
    $values = [11 => 'removed'];
    unset($values[11]);
    $values['object'] = $object;
    $values['nested'] = ['value' => &$original];
    [$container, $runtime] = (new ContainerBuilder())->assemble();
    $parameters = $runtime->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $parameters);
    $callback = static fn(mixed ...$values): array => $values;
    $target = $parameters->target(new ReflectionParameter($callback, 0));
    $tuple = $parameters->resolveParameter($target, new ParameterResolutionContext(provided: ['values' => $values]));
    if (!is_array($tuple[1]) || !is_array($tuple[1]['nested'] ?? null)) {
        throw new \LogicException('Expected the selected variadic argument collection.');
    }
    $result = $tuple[1];
    $result['nested']['value'] = 'changed';
    $result[] = 'appended';

    expect($result['object'])->toBe($object)
        ->and($original)->toBe('changed')
        ->and($values['nested'])->toBe(['value' => 'changed'])
        ->and(array_keys($result))->toBe(['object', 'nested', 12]);

    $native = $callback(...$values);
    expect($container->call($callback, ['values' => $values]))->toBe($native);
});
