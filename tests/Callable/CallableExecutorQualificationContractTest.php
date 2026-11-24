<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\CallableExecutorQualificationContract;

use Closure;
use Componenta\DI\CallableExecutor;
use Componenta\DI\CallableResolver;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use DomainException;
use Error;
use LogicException;
use Psr\Container\ContainerInterface;

class ParentHandler
{
    /** @return array{string,class-string,string} */
    public static function concrete(Dependency $dependency, string $tail = 'default'): array
    {
        return [$dependency->value, static::class, $tail];
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,class-string,string,array<array-key,mixed>}
     */
    public static function __callStatic(string $method, array $arguments): array
    {
        if (($arguments['failure'] ?? null) instanceof DomainException) {
            throw $arguments['failure'];
        }
        return ['parent static', static::class, $method, $arguments];
    }
}

final class Dependency
{
    public string $value = 'dependency';
}

class ChildHandler extends ParentHandler
{
    public static function shadowed(string $named): string
    {
        return 'concrete:' . $named;
    }

    /**
     * @param array<array-key,mixed> $arguments
     * @return array{string,class-string,string,array<array-key,mixed>}
     */
    public static function __callStatic(string $method, array $arguments): array
    {
        return ['child magic', static::class, $method, $arguments];
    }
}

final class AlternateHandler extends ChildHandler {}

final class CustomResolver extends CallableResolver
{
    public int $resolutions = 0;

    public function __construct(
        ContainerInterface $container,
        private readonly AlternateHandler $owner,
        private readonly string $method = ParentHandler::class . '::shadowed',
    ) {
        parent::__construct($container);
    }

    public function resolve(mixed $callable): callable
    {
        ++$this->resolutions;
        /** @var array{AlternateHandler,string}&callable $selected */
        $selected = [$this->owner, $this->method];
        return $selected;
    }
}

/**
 * @param Closure():mixed $call
 * @return array{mixed,list<int>}
 */
function observeQualification(Closure $call): array
{
    $diagnostics = [];
    set_error_handler(static function (int $severity) use (&$diagnostics): bool {
        $diagnostics[] = $severity;
        return true;
    });
    try {
        return [$call(), $diagnostics];
    } finally {
        restore_error_handler();
    }
}

test('container calls qualify a selected magic target once through every DI routing form', function (string $form): void {
    $receiver = new ChildHandler();
    $method = ParentHandler::class . '::shadowed';
    $container = new ContainerBuilder()
        ->addServices([
            ChildHandler::class => $receiver,
            'qualified.receiver' => $receiver,
            'qualified.callable' => [$receiver, $method],
        ])
        ->build();
    $target = match ($form) {
        'object pair' => [$receiver, $method],
        'class pair' => [ChildHandler::class, $method],
        'service receiver' => ['qualified.receiver', $method],
        'exact callable service' => 'qualified.callable',
        'instance shorthand' => ChildHandler::class . '::' . $method,
        default => throw new LogicException('Unknown callable fixture.'),
    };
    $called = $form === 'class pair' ? ParentHandler::class : ChildHandler::class;
    $named = 'value';
    $params = [7 => 'position', 'named' => &$named];

    [$result, $diagnostics] = observeQualification(fn() => $container->call($target, $params));
    $named = 'updated';

    expect($result)->toBe(['parent static', $called, 'shadowed', ['position', 'named' => 'updated']])
        ->and($diagnostics)->toBe([E_DEPRECATED]);
})->with(['object pair', 'class pair', 'service receiver', 'exact callable service', 'instance shorthand']);

test('qualified native discovery precedes the container receiver and a native miss permits fallback', function (bool $declares): void {
    $class = __NAMESPACE__ . '\\Pending_' . bin2hex(random_bytes(5));
    $container = new ContainerBuilder()->addService($class, new ChildHandler())->build();
    $loaderCalls = 0;
    $loader = static function (string $name) use ($class, $declares, &$loaderCalls): void {
        if ($name === $class) {
            ++$loaderCalls;
            if ($declares) {
                class_alias(ChildHandler::class, $class);
            }
        }
    };
    spl_autoload_register($loader);
    try {
        expect(observeQualification(fn() => $container->call([$class, ParentHandler::class . '::shadowed'], ['named' => 'value'])))
            ->toBe([
                ['parent static', $declares ? ParentHandler::class : ChildHandler::class, 'shadowed', ['named' => 'value']],
                [E_DEPRECATED],
            ])
            ->and($loaderCalls)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['native owner declared' => true, 'only a container receiver' => false]);

test('a qualified method uses the selected DI signature with one diagnostic', function (): void {
    $container = new ContainerBuilder()->build();
    $target = [new ChildHandler(), ParentHandler::class . '::concrete'];

    expect(observeQualification(fn() => $container->call($target, ['tail' => 'provided'])))
        ->toBe([['dependency', ChildHandler::class, 'provided'], [E_DEPRECATED]]);
});

test('public callable resolution keeps the qualified native representation', function (): void {
    $target = [new ChildHandler(), ParentHandler::class . '::shadowed'];
    $container = new ContainerBuilder()->build();

    [$resolved] = observeQualification(fn() => $container->resolve($target));

    expect($resolved)->toBe($target);
});

test('a custom resolver keeps control of selection and is not called again for invocation', function (): void {
    [$container, $runtime] = new ContainerBuilder()->assemble();
    $resolver = new CustomResolver($container, new AlternateHandler());
    $executor = new CallableExecutor($resolver, $runtime->parameters);

    [$result, $diagnostics] = observeQualification(
        fn() => $executor->call([new ChildHandler(), ParentHandler::class . '::shadowed'], ['named' => 'selected']),
    );

    expect($result)->toBe(['parent static', AlternateHandler::class, 'shadowed', ['named' => 'selected']])
        ->and($resolver->resolutions)->toBe(1)
        ->and($diagnostics)->toBe([E_DEPRECATED, E_DEPRECATED]);
});

test('a custom resolver target is captured within preparation and preserves the diagnostic cause', function (): void {
    [$container, $runtime] = new ContainerBuilder()->assemble();
    $resolver = new CustomResolver($container, new AlternateHandler());
    $executor = new CallableExecutor($resolver, $runtime->parameters);
    $cause = new DomainException('The qualification diagnostic failed during capture.');
    $diagnostics = [];
    set_error_handler(static function (int $severity) use (&$diagnostics, $cause): bool {
        $diagnostics[] = $severity;
        if (count($diagnostics) === 2) {
            throw $cause;
        }
        return true;
    });
    try {
        try {
            $executor->call('opaque input', ['named' => 'unreachable']);
            \PHPUnit\Framework\Assert::fail('The selected qualified target must fail capture.');
        } catch (InvalidCallableException $failure) {
            expect($failure->getPrevious())->toBe($cause)
                ->and($diagnostics)->toBe([E_DEPRECATED, E_DEPRECATED])
                ->and($resolver->resolutions)->toBe(1);
        }
    } finally {
        restore_error_handler();
    }
});

test('a failed qualification diagnostic is normalized before any magic body runs', function (): void {
    $container = new ContainerBuilder()->build();
    $target = [new ChildHandler(), ParentHandler::class . '::shadowed'];
    $cause = new DomainException('Original qualification failure.');
    $diagnostics = [];
    set_error_handler(static function (int $severity) use (&$diagnostics, $cause): never {
        $diagnostics[] = $severity;
        throw $cause;
    });
    try {
        try {
            $container->call($target, ['named' => 'unreachable']);
            \PHPUnit\Framework\Assert::fail('Qualification must reject the callable.');
        } catch (InvalidCallableException $failure) {
            expect($failure->getPrevious())->toBe($cause)
                ->and($diagnostics)->toBe([E_DEPRECATED]);
        }
    } finally {
        restore_error_handler();
    }
});

test('an exception in the selected qualified magic body propagates unchanged', function (): void {
    $container = new ContainerBuilder()->build();
    $target = [new ChildHandler(), ParentHandler::class . '::shadowed'];
    $cause = new DomainException('Original magic body failure.');
    $diagnostics = [];
    set_error_handler(static function (int $severity) use (&$diagnostics): bool {
        $diagnostics[] = $severity;
        return true;
    });
    try {
        try {
            $container->call($target, ['failure' => $cause]);
            \PHPUnit\Framework\Assert::fail('The selected body must throw.');
        } catch (DomainException $failure) {
            expect($failure)->toBe($cause)
                ->and($diagnostics)->toBe([E_DEPRECATED]);
        }
    } finally {
        restore_error_handler();
    }
});

test('qualified magic binding errors propagate after one diagnostic', function (): void {
    $container = new ContainerBuilder()->build();
    $target = [new ChildHandler(), ParentHandler::class . '::shadowed'];
    $diagnostics = [];
    set_error_handler(static function (int $severity) use (&$diagnostics): bool {
        $diagnostics[] = $severity;
        return true;
    });
    try {
        expect(fn() => $container->call($target, ['named' => 'first', 3 => 'second']))
            ->toThrow(Error::class, 'Cannot use positional argument after named argument')
            ->and($diagnostics)->toBe([E_DEPRECATED]);
    } finally {
        restore_error_handler();
    }
});
