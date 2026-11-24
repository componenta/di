<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Parameter\TargetedPendingParameterDiscoveryContract;

use Attribute;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

use function Componenta\DI\Tests\Support\fixtureInstance;

#[Attribute(Attribute::TARGET_CLASS)]
final class Marker {}

test('targeted discovery still retries an incomplete parameter classification after a new declaration', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $feature = 'OptionalFeature_' . $suffix;
    $unrelated = 'OptionalMarker_' . $suffix;
    $header = 'OptionalHeader_' . $suffix;
    $function = 'readHeader_' . $suffix;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        #[%s] final class %s {}
        function %s(#[%s('X-Label')] string $value = 'fallback'): string { return $value; }
        PHP,
        __NAMESPACE__,
        $unrelated,
        $feature,
        $function,
        $header,
    ));
    $di = fixtureInstance(
        new ContainerFactory()->createWithTargetedAttributeDiscovery(
            new Config([], new Environment([])),
            new DependencyDefinitions([]),
        )->container,
        Container::class,
    );
    $callable = __NAMESPACE__ . '\\' . $function;
    $provided = [ServerRequestInterface::class => new ServerRequest('GET', '/', ['X-Label' => 'loaded'])];
    expect($di->call($callable, $provided))->toBe('fallback');
    $di->make(__NAMESPACE__ . '\\' . $feature);
    class_alias(Marker::class, __NAMESPACE__ . '\\' . $unrelated, false);

    $name = __NAMESPACE__ . '\\' . $header;
    $attempts = 0;
    $loader = static function (string $class) use ($name, &$attempts): void {
        if ($class === $name && ++$attempts === 3) {
            class_alias(Header::class, $name);
        }
    };
    spl_autoload_register($loader, prepend: true);
    try {
        $value = $di->call($callable, $provided);
        expect([$value, $attempts])->toBe(['loaded', 3]);
    } finally {
        spl_autoload_unregister($loader);
    }
});
