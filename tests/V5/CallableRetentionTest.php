<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Closure;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use WeakReference;

final class AuditCallableDependency {}

final readonly class AuditCallableTargetProbe implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'first' || $target->name === 'second';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return $this->supports($target)
            ? [$target->position, $target->name . '-target']
            : null;
    }
}

final class AuditMagicCallable
{
    private function hidden(string $value): string
    {
        return 'private:' . $value;
    }

    protected function protectedHidden(string $value): string
    {
        return 'protected:' . $value;
    }

    public function __call(string $name, array $arguments): string
    {
        return $name . ':' . implode(',', array_map(static fn(mixed $value): string => (string) $value, $arguments));
    }
}

final class AuditStaticMagicCallable
{
    private static function hidden(string $value): string
    {
        return 'private:' . $value;
    }

    public static function __callStatic(string $name, array $arguments): string
    {
        return $name . ':' . implode(',', array_map(static fn(mixed $value): string => (string) $value, $arguments));
    }
}

/** @return Closure(AuditCallableDependency):object */
function auditClosureWithCapture(object $capture): Closure
{
    return static function (AuditCallableDependency $_dependency) use ($capture): object {
        return $capture;
    };
}

test('callable executor does not retain closure captures through signature metadata', function (): void {
    $container = (new ContainerBuilder())->build();
    $capture = new \stdClass();
    $reference = WeakReference::create($capture);
    $closure = auditClosureWithCapture($capture);

    expect($container->call($closure))->toBe($capture);

    unset($closure, $capture);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});

test('different closures resolve against their own parameter contract', function (): void {
    $container = (new ContainerBuilder())
        ->addParameterResolver(new AuditCallableTargetProbe(), 2000)
        ->build();
    $first = static fn(string $first): string => $first;
    $second = static fn(string $second): string => $second;

    expect($container->call($first))->toBe('first-target')
        ->and($container->call($second))->toBe('second-target');
});

test('native magic dispatch is preserved for inaccessible instance methods', function (): void {
    $container = (new ContainerBuilder())->build();
    $target = new AuditMagicCallable();

    expect($container->call([$target, 'hidden'], [0 => 'one']))->toBe('hidden:one')
        ->and($container->call([$target, 'protectedHidden'], [0 => 'two']))->toBe('protectedHidden:two');
});

test('native magic static dispatch is preserved for inaccessible static methods', function (): void {
    $container = (new ContainerBuilder())->build();

    expect($container->call(AuditStaticMagicCallable::class . '::hidden', [0 => 'value']))
        ->toBe('hidden:value');
});
