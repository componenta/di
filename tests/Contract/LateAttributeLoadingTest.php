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

final class LateLoadingDependency {}

test('parameters recognize an attribute loaded by an earlier dependency in the same resolution', function (bool $constructor): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'DuringResolutionHeader_' . $suffix;
    $target = 'DuringResolutionTarget_' . $suffix;
    $class = __NAMESPACE__ . '\\' . $target;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class %s extends LateParameterState {
            public function __construct(LateLoadingDependency $dependency, #[%s("X-Late")] string $value = "fallback") {
                $this->value = $value;
            }
            public static function read(LateLoadingDependency $dependency, #[%s("X-Late")] string $value = "fallback"): string {
                return $value;
            }
        }
        PHP,
        __NAMESPACE__,
        $target,
        $attribute,
        $attribute,
    ));
    $creations = 0;
    $container = \Componenta\DI\Tests\Support\container([
        \Componenta\DI\ConfigKey::FACTORIES => [
            LateLoadingDependency::class => static function () use ($attribute, &$creations): LateLoadingDependency {
                ++$creations;
                eval(sprintf(
                    'namespace %s; #[\\Attribute(\\Attribute::TARGET_PARAMETER)] readonly class %s extends \\Componenta\\DI\\Attribute\\Header {}',
                    __NAMESPACE__,
                    $attribute,
                ));
                return new LateLoadingDependency();
            },
        ],
    ]);
    if (!class_exists($class)) {
        throw new \LogicException('Expected the late attribute target class.');
    }
    $params = [
        \Psr\Http\Message\ServerRequestInterface::class => new \Nyholm\Psr7\ServerRequest(
            'GET',
            '/',
            ['X-Late' => 'loaded'],
        ),
    ];

    $result = $constructor
        ? $container->make($class, $params)
        : $container->call([$class, 'read'], $params);

    expect($result instanceof LateParameterState ? $result->value : $result)->toBe('loaded')
        ->and($creations)->toBe(1);
})->with(['constructor' => true, 'callable' => false]);

test('request mapping detects a source conflict loaded by an earlier DTO dependency', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'DuringMappingHeader_' . $suffix;
    $target = 'DuringMappingTarget_' . $suffix;
    $function = 'readDuringMapping_' . $suffix;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class %s extends LateParameterState {
            public function __construct(LateLoadingDependency $dependency, #[%s("X-Late")] string $value) {
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
    $creations = 0;
    $container = \Componenta\DI\Tests\Support\container([
        \Componenta\DI\ConfigKey::FACTORIES => [
            LateLoadingDependency::class => static function () use ($attribute, &$creations): LateLoadingDependency {
                ++$creations;
                eval(sprintf(
                    'namespace %s; #[\Attribute(\Attribute::TARGET_PARAMETER)] readonly class %s extends \Componenta\DI\Attribute\Header {}',
                    __NAMESPACE__,
                    $attribute,
                ));
                return new LateLoadingDependency();
            },
        ],
    ]);
    $params = [
        \Psr\Http\Message\ServerRequestInterface::class => new \Nyholm\Psr7\ServerRequest(
            'POST',
            '/',
            ['X-Late' => 'header-value'],
        )->withParsedBody(['value' => 'payload-value']),
    ];

    expect(fn() => $container->call(__NAMESPACE__ . '\\' . $function, $params))
        ->toThrow(\Componenta\DI\Exception\RequestParameterSourceConflictException::class, 'value');
    expect($creations)->toBe(1);
});

final class LateObjectEvents
{
    public int $constructions = 0;
}

abstract class LateObjectState
{
    /** @var list<string> */
    public array $steps = [];

    public function initialize(string $step): void
    {
        $this->steps[] = $step;
    }
}

test('object initialization uses attributes loaded by dependencies without replaying completed work', function (string $dependencySource, bool $useDefinition): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'DuringObjectSetUp_' . $suffix;
    $target = 'DuringObjectTarget_' . $suffix;
    $property = $dependencySource === 'property';
    $knownSetUp = $property ? '#[\\Componenta\\DI\\Attribute\\SetUp("initialize", ["step" => "known"])]' : '';
    $propertyDeclaration = $property ? '#[\\Componenta\\DI\\Attribute\\Inject] public LateLoadingDependency $dependency;' : '';
    $dependencyParameter = $dependencySource === 'constructor' ? 'LateLoadingDependency $dependency, ' : '';
    if ($dependencySource === 'hook') {
        $knownSetUp = '#[\Componenta\DI\Attribute\SetUp("load")]';
    }
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        %s
        #[%s("initialize")]
        final class %s extends LateObjectState {
            %s
            public function __construct(%sLateObjectEvents $events) { ++$events->constructions; }
            public function load(LateLoadingDependency $dependency): void { $this->steps[] = "known"; }
        }
        PHP,
        __NAMESPACE__,
        $knownSetUp,
        $attribute,
        $target,
        $propertyDeclaration,
        $dependencyParameter,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!class_exists($class)) {
        throw new \LogicException('Expected the late object attribute fixture class.');
    }
    $events = new LateObjectEvents();
    $dependencyCreations = 0;
    $container = \Componenta\DI\Tests\Support\container([
        \Componenta\DI\ConfigKey::SERVICES => [LateObjectEvents::class => $events],
        \Componenta\DI\ConfigKey::FACTORIES => [
            ...($useDefinition ? [
                $class => \Componenta\DI\Definition\ClassDefinition::create($class)->autowire()
                    ->constructor(['step' => 'late'])
                    ->call('initialize', ['step' => 'configured']),
            ] : []),
            LateLoadingDependency::class => static function () use ($attribute, &$dependencyCreations): LateLoadingDependency {
                ++$dependencyCreations;
                eval(sprintf(
                    'namespace %s; #[\\Attribute(\\Attribute::TARGET_CLASS)] readonly class %s extends \\Componenta\\DI\\Attribute\\SetUp {}',
                    __NAMESPACE__,
                    $attribute,
                ));
                return new LateLoadingDependency();
            },
        ],
    ]);
    expect($container->has($class))->toBeTrue()->and($events->constructions)->toBe(0);

    $params = $useDefinition ? [] : ['step' => 'late'];
    $first = $container->make($class, $params);
    $second = $container->make($class, $params);
    if (!$first instanceof LateObjectState || !$second instanceof LateObjectState) {
        throw new \LogicException('Expected the late object attribute fixtures.');
    }

    $expectedSteps = $useDefinition ? ['configured'] : ($dependencySource === 'constructor' ? ['late'] : ['known', 'late']);
    expect($first->steps)->toBe($expectedSteps)
        ->and($second->steps)->toBe($expectedSteps)
        ->and($events->constructions)->toBe(2)
        ->and($dependencyCreations)->toBe(1);
})->with([
    'property dependency' => ['property', false],
    'constructor dependency' => ['constructor', false],
    'hook dependency' => ['hook', false],
    'ClassDefinition constructor dependency' => ['constructor', true],
]);
