<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;

abstract class LateAttributeState
{
    public bool $initialized = false;

    public function initialize(): void
    {
        $this->initialized = true;
    }
}

test('metadata warmed before attribute autoloading still executes the later attribute handler', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'LateSetUp_' . $suffix;
    $target = 'LateAttributeTarget_' . $suffix;
    $class = __NAMESPACE__ . '\\' . $target;
    eval(sprintf(
        'namespace %s; #[%s("initialize")] final class %s extends LateAttributeState {}',
        __NAMESPACE__,
        $attribute,
        $target,
    ));
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }

    expect($container->has($class))->toBeTrue();

    $loader = static function (string $requested) use ($attribute): void {
        if ($requested === __NAMESPACE__ . '\\' . $attribute) {
            eval(sprintf(
                'namespace %s; #[\Attribute(\Attribute::TARGET_CLASS)] readonly class %s extends \Componenta\DI\Attribute\SetUp {}',
                __NAMESPACE__,
                $attribute,
            ));
        }
    };
    spl_autoload_register($loader);

    try {
        $entry = $container->make($class);
        if (!$entry instanceof LateAttributeState) {
            throw new \LogicException('Expected the late attribute target.');
        }

        expect($entry->initialized)->toBeTrue();
    } finally {
        spl_autoload_unregister($loader);
    }
});


abstract class LateParameterState
{
    public string $value;
}

test('prepared constructors and callables recognize a later parameter attribute', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'LateHeader_' . $suffix;
    $target = 'LateParameterTarget_' . $suffix;
    $class = __NAMESPACE__ . '\\' . $target;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class %s extends LateParameterState {
            public function __construct(#[%s("X-Late")] string $value = "fallback") {
                $this->value = $value;
            }
            public static function read(#[%s("X-Late")] string $value = "fallback"): string {
                return $value;
            }
        }
        PHP,
        __NAMESPACE__,
        $target,
        $attribute,
        $attribute,
    ));
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }
    $params = [
        \Psr\Http\Message\ServerRequestInterface::class => new \Nyholm\Psr7\ServerRequest(
            'GET',
            '/',
            ['X-Late' => 'loaded'],
        ),
    ];
    $initial = $container->make($class, $params);
    if (!$initial instanceof LateParameterState) {
        throw new \LogicException('Expected the late parameter target.');
    }

    expect($initial->value)->toBe('fallback')
        ->and($container->call([$class, 'read'], $params))->toBe('fallback');

    $loader = static function (string $requested) use ($attribute): void {
        if ($requested === __NAMESPACE__ . '\\' . $attribute) {
            eval(sprintf(
                'namespace %s; #[\Attribute(\Attribute::TARGET_PARAMETER)] readonly class %s extends \Componenta\DI\Attribute\Header {}',
                __NAMESPACE__,
                $attribute,
            ));
        }
    };
    spl_autoload_register($loader);

    try {
        $value = $container->call([$class, 'read'], $params);
        $entry = $container->make($class, $params);
        if (!$entry instanceof LateParameterState) {
            throw new \LogicException('Expected the late parameter target.');
        }

        expect($value)->toBe('loaded')
            ->and($entry->value)->toBe('loaded');
    } finally {
        spl_autoload_unregister($loader);
    }
});


test('late source attributes cannot be bypassed through a warmed request mapper and an explicit DTO factory', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'LateDtoHeader_' . $suffix;
    $target = 'LateDtoTarget_' . $suffix;
    $function = 'readLateDto_' . $suffix;
    $class = __NAMESPACE__ . '\\' . $target;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class %s extends LateParameterState {
            public function __construct(#[%s("X-Late")] string $value) {
                $this->value = $value;
            }
        }
        function %s(#[\Componenta\DI\Attribute\MapRequestPayload] %s $dto): string {
            return $dto->value;
        }
        PHP,
        __NAMESPACE__,
        $target,
        $attribute,
        $function,
        $target,
    ));
    $container = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([
            \Componenta\DI\ConfigKey::FACTORIES => [
                $class => static function (\Componenta\Config\ContainerValue $context, array $params) use ($class): LateParameterState {
                    $value = $params['value'] ?? null;
                    if (!is_string($value)) {
                        throw new \LogicException('Expected a DTO value.');
                    }
                    $entry = new $class($value);
                    if (!$entry instanceof LateParameterState) {
                        throw new \LogicException('Expected a DTO fixture.');
                    }
                    return $entry;
                },
            ],
        ]),
    )->container;
    if (!$container instanceof Container) {
        throw new \LogicException('Expected the Componenta container.');
    }
    $callable = __NAMESPACE__ . '\\' . $function;
    $params = [
        \Psr\Http\Message\ServerRequestInterface::class => new \Nyholm\Psr7\ServerRequest('POST', '/')
            ->withParsedBody(['value' => 'supplied']),
    ];

    expect($container->call($callable, $params))->toBe('supplied');

    $loader = static function (string $requested) use ($attribute): void {
        if ($requested === __NAMESPACE__ . '\\' . $attribute) {
            eval(sprintf(
                'namespace %s; #[\Attribute(\Attribute::TARGET_PARAMETER)] readonly class %s extends \Componenta\DI\Attribute\Header {}',
                __NAMESPACE__,
                $attribute,
            ));
        }
    };
    spl_autoload_register($loader);

    try {
        expect(fn() => $container->call($callable, $params))
            ->toThrow(\Componenta\DI\Exception\RequestParameterSourceConflictException::class, 'value');
    } finally {
        spl_autoload_unregister($loader);
    }
});
