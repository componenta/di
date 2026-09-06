<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CompletedPhases;

use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

abstract class ConstructionState
{
    public static int $constructors = 0;
    public string $state = 'default';

    public function after(): void {}
}

it('rejects a constructor policy loaded by its dependency before invoking the constructor or sharing the object', function (bool $preloaded, bool $knownHook): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'ConstructorPolicy_' . $suffix;
    $target = 'ConstructorTarget_' . $suffix;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    $prefix = $knownHook ? '#[\Componenta\DI\Attribute\SetUp("after")]' : '';
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        %s #[%s] final class %s extends ConstructionState {
            public function __construct(#[\Componenta\DI\Attribute\EntryId('loader')] object $loader) {
                ++self::$constructors;
                $this->state = 'constructed';
            }
        }
        PHP,
        __NAMESPACE__,
        $prefix,
        $alias,
        $target,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, ConstructionState::class, true)) {
        throw new LogicException('Expected the constructor policy fixture.');
    }
    if ($preloaded) {
        class_alias(NoConstructor::class, $fullAlias);
    }
    $loads = 0;
    $container = (new ContainerBuilder())->addFactory('loader', static function () use ($fullAlias, &$loads): object {
        ++$loads;
        if (!class_exists($fullAlias, false)) {
            class_alias(NoConstructor::class, $fullAlias);
        }
        return new \stdClass();
    })->build();
    ConstructionState::$constructors = 0;

    if (!$preloaded) {
        expect(fn() => $container->get($class))->toThrow(AttributeCompositionException::class, $class);
    }
    $fresh = $container->make($class);
    $shared = $container->get($class);
    expect($fresh->state)->toBe('default')
        ->and($shared->state)->toBe('default')
        ->and($container->get($class))->toBe($shared)
        ->and($fresh)->not->toBe($shared)
        ->and(ConstructionState::$constructors)->toBe(0)
        ->and($loads)->toBe($preloaded ? 0 : 1);
})->with([
    'fast path, late' => [false, false],
    'fast path, preloaded control' => [true, false],
    'known hook, late' => [false, true],
    'known hook, preloaded control' => [true, true],
]);
it('rejects a late constructor policy when a lazy object initializes and allows a fresh make', function (string $strategy): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'DeferredConstructorPolicy_' . $suffix;
    $target = 'DeferredConstructorTarget_' . $suffix;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        #[\Componenta\DI\Attribute\%s, %s] final class %s extends ConstructionState {
            public function __construct(#[\Componenta\DI\Attribute\EntryId('loader')] object $loader) {
                ++self::$constructors;
                $this->state = 'constructed';
            }
        }
        PHP,
        __NAMESPACE__,
        $strategy,
        $alias,
        $target,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, ConstructionState::class, true)) {
        throw new LogicException('Expected the deferred constructor policy fixture.');
    }
    $loads = 0;
    $container = (new ContainerBuilder())->addFactory('loader', static function () use ($fullAlias, &$loads): object {
        ++$loads;
        class_alias(NoConstructor::class, $fullAlias);
        return new \stdClass();
    })->build();
    ConstructionState::$constructors = 0;
    $pending = $container->make($class);
    expect($loads)->toBe(0)->and(ConstructionState::$constructors)->toBe(0);

    expect(fn() => $pending->state)->toThrow(AttributeCompositionException::class, $class)
        ->and($loads)->toBe(1)->and(ConstructionState::$constructors)->toBe(0);
    $fresh = $container->make($class);
    expect($fresh->state)->toBe('default')
        ->and(fn() => $pending->state)->toThrow(AttributeCompositionException::class, $class)
        ->and(ConstructionState::$constructors)->toBe(0)->and($loads)->toBe(1);
})->with(['Lazy', 'Proxy']);

it('does not share an object when a constructor or lifecycle hook loads a policy for its completed before phase', function (bool $hook): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'AfterConstructorPolicy_' . $suffix;
    $target = 'AfterConstructorTarget_' . $suffix;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    $prefix = $hook ? '#[\Componenta\DI\Attribute\SetUp("loadPolicy")]' : '';
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        %s #[%s] final class %s extends ConstructionState {
            public function __construct() {
                ++self::$constructors;
                $this->state = 'constructed';
                %s
            }
            public function loadPolicy(): void {
                if (!class_exists(%s, false)) {
                    class_alias(\Componenta\DI\Attribute\NoConstructor::class, %s);
                }
            }
        }
        PHP,
        __NAMESPACE__,
        $prefix,
        $alias,
        $target,
        $hook ? '' : '$this->loadPolicy();',
        var_export($fullAlias, true),
        var_export($fullAlias, true),
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, ConstructionState::class, true)) {
        throw new LogicException('Expected the late constructor policy fixture.');
    }
    $container = (new ContainerBuilder())->build();
    ConstructionState::$constructors = 0;

    expect(fn() => $container->get($class))->toThrow(AttributeCompositionException::class, $class)
        ->and(ConstructionState::$constructors)->toBe(1);
    expect($container->get($class)->state)->toBe('default')
        ->and($container->make($class)->state)->toBe('default')
        ->and(ConstructionState::$constructors)->toBe(1);
})->with(['constructor' => false, 'lifecycle hook' => true]);
