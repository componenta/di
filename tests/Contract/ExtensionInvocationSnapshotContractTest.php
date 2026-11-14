<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExtensionInvocationSnapshot;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
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
