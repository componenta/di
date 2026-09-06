<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use LogicException;

use function Componenta\DI\Tests\Support\container;

#[Attribute(Attribute::TARGET_CLASS)]
final class BootstrapLateExtension {}

final class BootstrapAttributeLoader {}

abstract class BootstrapLateState
{
    public int $initializations = 0;

    public function initialize(): void
    {
        ++$this->initializations;
    }

    public function load(BootstrapAttributeLoader $loader): void {}
}

it('accepts bootstrap dependencies that complete a late loaded lifecycle hook', function (string $source, bool $shared): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'BootstrapLateSetUp_' . $suffix;
    $target = 'BootstrapLateReady_' . $suffix;
    $knownHook = $source === 'hook' ? '#[\Componenta\DI\Attribute\SetUp("load")]' : '';
    $constructor = $source === 'constructor' ? 'public function __construct(BootstrapAttributeLoader $loader) {}' : '';
    eval(sprintf('namespace %s; %s #[%s("initialize")] final class %s extends BootstrapLateState { %s }', __NAMESPACE__, $knownHook, $attribute, $target, $constructor));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!class_exists($class)) {
        throw new LogicException('Expected the bootstrap fixture class.');
    }
    $observed = null;
    $container = container([
        ConfigKey::FACTORIES => [
            BootstrapAttributeLoader::class => static function () use ($attribute): BootstrapAttributeLoader {
                eval(sprintf('namespace %s; #[\Attribute(\Attribute::TARGET_CLASS)] readonly class %s extends \Componenta\DI\Attribute\SetUp {}', __NAMESPACE__, $attribute));
                return new BootstrapAttributeLoader();
            },
        ],
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static function (Container $container) use ($class, $shared, &$observed): AttributeDefinition {
                $dependency = $shared ? $container->get($class) : $container->make($class);
                if (!$dependency instanceof BootstrapLateState) {
                    throw new LogicException('Expected the bootstrap dependency.');
                }
                $observed = $dependency->initializations;
                return new AttributeDefinition(BootstrapLateExtension::class);
            },
        ],
    ]);

    $next = $container->make($class);
    if (!$next instanceof BootstrapLateState) {
        throw new LogicException('Expected the bootstrap dependency.');
    }
    expect($observed)->toBe(1)->and($next->initializations)->toBe(1);
})->with([
    'get constructor dependency' => ['constructor', true],
    'make constructor dependency' => ['constructor', false],
    'get hook dependency' => ['hook', true],
    'make hook dependency' => ['hook', false],
]);

it('rejects bootstrap attributes that become available after their execution phase was skipped', function (string $phase): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'BootstrapMissed_' . $suffix;
    $target = 'BootstrapMissedTarget_' . $suffix;
    if ($phase === 'before') {
        $declaration = sprintf('#[%s] final class %s extends BootstrapLateState { public function __construct(BootstrapAttributeLoader $loader) {} }', $attribute, $target);
        $parent = '\\Componenta\\DI\\Attribute\\NoConstructor';
    } else {
        $declaration = sprintf('#[\Componenta\DI\Attribute\SetUp("load")] final class %s extends BootstrapLateState { #[%s] public BootstrapAttributeLoader $dependency; }', $target, $attribute);
        $parent = '\\Componenta\\DI\\Attribute\\Inject';
    }
    eval(sprintf('namespace %s; %s', __NAMESPACE__, $declaration));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!class_exists($class)) {
        throw new LogicException('Expected the missed-phase fixture class.');
    }

    expect(fn() => container([
        ConfigKey::FACTORIES => [
            BootstrapAttributeLoader::class => static function () use ($attribute, $parent): BootstrapAttributeLoader {
                class_alias($parent, __NAMESPACE__ . '\\' . $attribute);
                return new BootstrapAttributeLoader();
            },
        ],
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static function (Container $container) use ($class): AttributeDefinition {
                $container->get($class);
                return new AttributeDefinition(BootstrapLateExtension::class);
            },
        ],
    ]))->toThrow(InvalidConfigurationException::class, $class);
})->with(['before', 'earlier property']);

it('rejects a parameter attribute loaded by a resolver that did not execute it', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'BootstrapMissedEntryId_' . $suffix;
    $target = 'BootstrapMissedParameter_' . $suffix;
    eval(sprintf(
        'namespace %s; final class %s { public function __construct(#[%s("alternative")] public BootstrapAttributeLoader $dependency) {} }',
        __NAMESPACE__,
        $target,
        $attribute,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!class_exists($class)) {
        throw new LogicException('Expected the missed parameter fixture class.');
    }

    expect(fn() => container([
        ConfigKey::SERVICES => ['alternative' => new BootstrapAttributeLoader()],
        ConfigKey::FACTORIES => [
            BootstrapAttributeLoader::class => static function () use ($attribute): BootstrapAttributeLoader {
                class_alias(\Componenta\DI\Attribute\EntryId::class, __NAMESPACE__ . '\\' . $attribute);
                return new BootstrapAttributeLoader();
            },
        ],
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static function (Container $container) use ($class): AttributeDefinition {
                $container->get($class);
                return new AttributeDefinition(BootstrapLateExtension::class);
            },
        ],
    ]))->toThrow(InvalidConfigurationException::class, $class);
});
