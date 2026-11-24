<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\CallableInvocationSnapshotContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\CallableResolver;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class Target
{
    public function __construct(public string $label) {}

    public function first(#[EntryId('argument-loader')] object $dependency): string
    {
        return $this->label . ':first';
    }

    public function alternate(#[EntryId('argument-loader')] object $dependency): string
    {
        return $this->label . ':alternate';
    }
}

test('callable invocation retains its selected owner and method while resolving dependencies', function (
    bool $service,
    bool $warm,
): void {
    $original = new Target('original');
    $owner = $original;
    $method = 'first';
    $callable = [&$owner, &$method];
    $container = (new ContainerBuilder())
        ->addService('selected-callable', $callable)
        ->addFactory('argument-loader', static function () use (&$owner, &$method, $original): object {
            $original->label = 'updated business state';
            $owner = new Target('replacement');
            $method = 'alternate';
            return new \stdClass();
        })
        ->build();
    $input = $service ? 'selected-callable' : $callable;
    if ($warm) {
        expect($container->call($input, ['dependency' => new \stdClass()]))->toBe('original:first');
    }

    expect($container->call($input))->toBe('updated business state:first')
        ->and($owner->label)->toBe('replacement')
        ->and($method)->toBe('alternate')
        ->and($container->call($input))->toBe('replacement:alternate');
})->with(['explicit array' => false, 'service callable' => true])
    ->with(['cold plan' => false, 'warm plan' => true]);

final class StaticTarget
{
    public static function first(): string
    {
        return 'first';
    }

    public static function alternate(): string
    {
        return 'alternate';
    }
}

test('explicit callable routing is captured before callable validation can autoload application code', function (): void {
    $requested = __NAMESPACE__ . '\\AutoloadedTarget_' . bin2hex(random_bytes(5));
    $owner = $requested;
    $method = 'first';
    $container = (new ContainerBuilder())->build();
    $autoload = static function (string $class) use ($requested, &$owner, &$method): void {
        if ($class === $requested) {
            $owner = StaticTarget::class;
            $method = 'alternate';
            class_alias(StaticTarget::class, $class);
        }
    };
    spl_autoload_register($autoload);

    try {
        expect($container->call([&$owner, &$method]))->toBe('first')
            ->and($owner)->toBe(StaticTarget::class)
            ->and($method)->toBe('alternate');
    } finally {
        spl_autoload_unregister($autoload);
    }
});

#[Attribute(Attribute::TARGET_PARAMETER)]
final class CacheControl {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class ReplacementStaticTarget
{
    public static function first(): string
    {
        return 'replacement:first';
    }

    public static function alternate(): string
    {
        return 'replacement:alternate';
    }
}

test('callable resolution captures array routing before validation mutates referenced slots', function (
    string $mutation,
    ?string $nextResult,
    bool $uncached,
    string $entryPoint,
): void {
    $requested = __NAMESPACE__ . '\\ServiceTarget_' . bin2hex(random_bytes(5));
    $owner = $requested;
    $method = 'first';
    $specification = [&$owner, &$method];
    $builder = (new ContainerBuilder())->addService('autoloaded-callable', $specification);
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(CacheControl::class, rules: [new NoOpRule()]));
    }
    $container = $builder->build();
    $resolver = new CallableResolver($container);
    $invoke = static fn(): mixed => match ($entryPoint) {
        'service call' => $container->call('autoloaded-callable'),
        'service resolve' => ($container->resolve('autoloaded-callable'))(),
        'explicit resolve' => ($container->resolve($specification))(),
        'standalone resolve' => ($resolver->resolve($specification))(),
        default => throw new \LogicException('Unknown callable entry point.'),
    };
    $loads = 0;
    $autoload = static function (string $class) use ($requested, $mutation, &$owner, &$method, &$loads): void {
        if ($class !== $requested) {
            return;
        }
        ++$loads;
        class_alias(StaticTarget::class, $class);
        if ($mutation === 'owner' || $mutation === 'both') {
            $owner = ReplacementStaticTarget::class;
        } elseif ($mutation === 'invalid owner') {
            $owner = '';
        }
        if ($mutation === 'method' || $mutation === 'both') {
            $method = 'alternate';
        }
    };
    spl_autoload_register($autoload);

    try {
        expect($invoke())->toBe('first')
            ->and($loads)->toBe(1);

        if ($nextResult === null) {
            expect($invoke)->toThrow(InvalidCallableException::class);
        } else {
            expect($invoke())->toBe($nextResult);
        }
        expect($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with([
    'receiver changed' => ['owner', 'replacement:first'],
    'method changed' => ['method', 'alternate'],
    'both changed' => ['both', 'replacement:alternate'],
    'receiver invalidated' => ['invalid owner', null],
])->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['service call', 'service resolve', 'explicit resolve', 'standalone resolve']);
