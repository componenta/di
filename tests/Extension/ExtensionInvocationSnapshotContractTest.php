<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Extension\ExtensionInvocationSnapshotContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Internal\ExtensionFactory as ExtensionMaterializer;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Support\container;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class SelectedValue {}

#[Attribute(Attribute::TARGET_CLASS)]
final class Preparation {}

final readonly class SelectedResolver implements ParameterResolverInterface
{
    public function __construct(private ExtensionFactory $owner, private string $method) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'selected';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return [$target->position, $this->owner->label . ':' . $this->method];
    }
}

final readonly class SelectedHandler implements ParameterAttributeHandlerInterface
{
    public function __construct(private ExtensionFactory $owner, private string $method) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        return ParameterAttributeValue::resolved($this->owner->label . ':' . $this->method);
    }
}

final class ExtensionFactory
{
    public function __construct(private readonly bool $attribute, public string $label) {}

    public function first(ContainerInterface $container): ParameterResolverInterface|AttributeDefinition
    {
        return $this->extension('first');
    }

    public function alternate(ContainerInterface $container): ParameterResolverInterface|AttributeDefinition
    {
        return $this->extension('alternate');
    }

    public function materialize(ContainerInterface $container): ParameterResolverInterface|AttributeDefinition
    {
        return $this->extension('materialize');
    }

    private function extension(string $method): ParameterResolverInterface|AttributeDefinition
    {
        return $this->attribute
            ? new AttributeDefinition(SelectedValue::class, new SelectedHandler($this, $method), [ValueProvider::class])
            : new SelectedResolver($this, $method);
    }
}

final class StaticExtensionFactory
{
    public static function first(ContainerInterface $container): ParameterResolverInterface|AttributeDefinition
    {
        /** @var ExtensionFactory $factory */
        $factory = $container->get('extension.factory');
        return $factory->first($container);
    }

    public static function alternate(ContainerInterface $container): ParameterResolverInterface|AttributeDefinition
    {
        /** @var ExtensionFactory $factory */
        $factory = $container->get('extension.factory');
        return $factory->alternate($container);
    }
}

/** @param array{services?:array<string,mixed>,factories?:array<string,mixed>,attribute_definitions?:list<mixed>} $sections */
function extensionContainer(bool $attribute, mixed $specification, array $sections): Container
{
    if ($attribute) {
        $sections[ConfigKey::ATTRIBUTE_DEFINITIONS][] = $specification;
    } else {
        $sections[ConfigKey::PARAMETER_RESOLVERS] = [500 => $specification];
    }
    return container($sections);
}

test('extension invocation retains its selected route while resolving the factory owner', function (bool $attribute): void {
    $original = new ExtensionFactory($attribute, 'original');
    $owner = 'extension.owner';
    $method = 'first';
    $di = extensionContainer($attribute, [&$owner, &$method], [
        ConfigKey::SERVICES => ['replacement.owner' => new ExtensionFactory($attribute, 'replacement')],
        ConfigKey::FACTORIES => [
            'extension.owner' => static function () use ($original, &$owner, &$method): ExtensionFactory {
                $original->label = 'updated business state';
                $owner = 'replacement.owner';
                $method = 'alternate';
                return $original;
            },
        ],
    ]);

    expect($di->call(static fn(#[SelectedValue] string $selected): string => $selected))->toBe('updated business state:first')
        ->and($owner)->toBe('replacement.owner')
        ->and($method)->toBe('alternate');
})->with(['parameter resolver' => false, 'attribute definition' => true]);

test('extension invocation captures routing before callable inspection can autoload application code', function (bool $attribute): void {
    $requested = __NAMESPACE__ . '\\AutoloadedFactory_' . bin2hex(random_bytes(5));
    $owner = $requested;
    $method = 'first';
    $autoload = static function (string $class) use ($requested, &$owner, &$method): void {
        if ($class === $requested) {
            $owner = StaticExtensionFactory::class;
            $method = 'alternate';
            class_alias(StaticExtensionFactory::class, $class);
        }
    };

    try {
        $di = extensionContainer($attribute, [&$owner, &$method], [
            ConfigKey::SERVICES => ['extension.factory' => new ExtensionFactory($attribute, 'original')],
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                static function () use ($autoload): AttributeDefinition {
                    // Activate after configuration validation, before this invocation.
                    spl_autoload_register($autoload);
                    return new AttributeDefinition(Preparation::class);
                },
            ],
        ]);

        expect($di->call(static fn(#[SelectedValue] string $selected): string => $selected))->toBe('original:first')
            ->and($owner)->toBe(StaticExtensionFactory::class)
            ->and($method)->toBe('alternate');
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['parameter resolver' => false, 'attribute definition' => true]);

test('deferred extension discovery starts after preceding extensions are materialized', function (bool $attribute, bool $asPair): void {
    $owner = __NAMESPACE__ . '\\DeferredFactory_' . bin2hex(random_bytes(5));
    $preparation = new class () {
        public bool $configured = false;
        public int $loads = 0;
    };
    $autoload = static function (string $class) use ($owner, $preparation): void {
        if ($class !== $owner) {
            return;
        }
        if (!$preparation->configured) {
            throw new LogicException('Extension discovery preceded its configuration.');
        }
        ++$preparation->loads;
        class_alias(StaticExtensionFactory::class, $class, false);
    };

    spl_autoload_register($autoload);
    try {
        $di = extensionContainer($attribute, $asPair ? [$owner, 'first'] : $owner . '::first', [
            ConfigKey::SERVICES => ['extension.factory' => new ExtensionFactory($attribute, 'configured')],
            ConfigKey::ATTRIBUTE_DEFINITIONS => [
                static function () use ($preparation): AttributeDefinition {
                    $preparation->configured = true;
                    return new AttributeDefinition(Preparation::class);
                },
            ],
        ]);

        expect($di->call(static fn(#[SelectedValue] string $selected): string => $selected))->toBe('configured:first')
            ->and($preparation->loads)->toBe(1);
    } finally {
        spl_autoload_unregister($autoload);
    }
})->with(['parameter resolver' => false, 'attribute definition' => true])
    ->with(['callable string' => false, 'callable pair' => true]);

test('a later extension selects the current caller route and retains its factory object identity', function (bool $attribute): void {
    $original = new ExtensionFactory($attribute, 'original');
    $replacement = new ExtensionFactory($attribute, 'replacement');
    $owner = $original;
    $method = 'first';
    $di = extensionContainer($attribute, [&$owner, &$method], [
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            static function () use ($replacement, &$owner, &$method): AttributeDefinition {
                $owner = $replacement;
                $method = 'alternate';
                return new AttributeDefinition(Preparation::class);
            },
        ],
    ]);
    $callback = static fn(#[SelectedValue] string $selected): string => $selected;

    expect($di->call($callback))->toBe('replacement:alternate');
    $replacement->label = 'updated business state';
    expect($di->call($callback))->toBe('updated business state:alternate')
        ->and($owner)->toBe($replacement)
        ->and($original->label)->toBe('original');
})->with(['parameter resolver' => false, 'attribute definition' => true]);

test('native static extension factories retain their callable owner when a same-named service exists', function (bool $attribute): void {
    $original = new ExtensionFactory($attribute, 'native');
    $shadow = new ExtensionFactory($attribute, 'service');
    $di = extensionContainer($attribute, [StaticExtensionFactory::class, 'first'], [
        ConfigKey::SERVICES => [
            'extension.factory' => $original,
            StaticExtensionFactory::class => $shadow,
        ],
    ]);

    expect($di->call(static fn(#[SelectedValue] string $selected): string => $selected))->toBe('native:first')
        ->and($di->get(StaticExtensionFactory::class))->toBe($shadow);
})->with(['parameter resolver' => false, 'attribute definition' => true]);

test('extension discovery selects a public service method over an inaccessible native method', function (bool $attribute): void {
    $factory = new ExtensionFactory($attribute, 'service');
    $di = extensionContainer($attribute, [ExtensionMaterializer::class, 'materialize'], [
        ConfigKey::SERVICES => [ExtensionMaterializer::class => $factory],
    ]);

    expect($di->call(static fn(#[SelectedValue] string $selected): string => $selected))->toBe('service:materialize')
        ->and($di->get(ExtensionMaterializer::class))->toBe($factory);
})->with(['parameter resolver' => false, 'attribute definition' => true]);
