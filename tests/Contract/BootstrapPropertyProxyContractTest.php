<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\BootstrapProxy;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use LogicException;
use ReflectionClass;

use function Componenta\DI\Tests\Support\container;

#[Attribute(Attribute::TARGET_CLASS)]
final class ExtensionMarker {}

final class LoadProxyArgument
{
    public static int $constructions = 0;

    public function __construct(string $alias)
    {
        ++self::$constructions;
        if (!class_exists($alias, false)) {
            class_alias(Proxy::class, $alias);
        }
    }
}

final class Product
{
    public function __construct(public LoadProxyArgument $argument) {}
}

abstract class OwnerState
{
    public Product $value;
}

it('accepts property Proxy loaded by Make arguments during bootstrap without replaying construction', function (bool $bootstrap, bool $preload): void {
    LoadProxyArgument::$constructions = 0;
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'PropertyProxy_' . $suffix;
    $owner = 'Owner_' . $suffix;
    $alias = __NAMESPACE__ . '\\' . $attribute;
    eval(sprintf(
        'namespace %s; final class %s extends OwnerState { #[\\Componenta\\DI\\Attribute\\Make(Product::class, ["argument" => new LoadProxyArgument(%s)]), %s] public Product $value; }',
        __NAMESPACE__,
        $owner,
        var_export($alias, true),
        $attribute,
    ));
    $class = __NAMESPACE__ . '\\' . $owner;
    if (!is_a($class, OwnerState::class, true)) {
        throw new LogicException('Expected the property owner fixture.');
    }
    if ($preload) {
        class_alias(Proxy::class, $alias);
    }
    $observed = null;
    $sections = $bootstrap ? [ConfigKey::ATTRIBUTE_DEFINITIONS => [
        static function (Container $di) use ($class, &$observed): AttributeDefinition {
            $observed = $di->make($class);
            return new AttributeDefinition(ExtensionMarker::class);
        },
    ]] : [];
    $di = container($sections);
    $observed ??= $di->make($class);
    if (!$observed instanceof OwnerState) {
        throw new LogicException('Expected the created property owner.');
    }

    expect(new ReflectionClass(Product::class)->isUninitializedLazyObject($observed->value))->toBeTrue()
        ->and(LoadProxyArgument::$constructions)->toBe(1)
        ->and($observed->value->argument)->toBeInstanceOf(LoadProxyArgument::class)
        ->and(LoadProxyArgument::$constructions)->toBe(1);
})->with([
    'runtime late' => [false, false],
    'runtime preloaded' => [false, true],
    'bootstrap late' => [true, false],
    'bootstrap preloaded' => [true, true],
]);
