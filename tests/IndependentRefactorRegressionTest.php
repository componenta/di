<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Psr\Container\ContainerInterface;

interface IndependentOpaqueAliasContract {}

it('keeps concrete object delegators fail-fast at the configuration boundary', function (): void {
    $delegator = new class () {
        private function hidden(string $entry): string
        {
            return $entry;
        }
    };

    expect(fn() => (new ContainerBuilder())->addDelegator(
        'entry',
        [$delegator, 'hidden'],
    ))->toThrow(InvalidConfigurationException::class);
});

it('keeps an unrelated namespace mutation from invalidating a cached decorated entry', function (): void {
    $container = (new ContainerBuilder())->build();

    $container->set(
        'handler',
        static fn(string $entry): object => (object) ['value' => $entry . ':handled'],
    );
    $container->set('service', 'base');
    $container->delegator('service', 'handler');

    $resolved = $container->get('service');
    expect($resolved->value)->toBe('base:handled');

    $container->set('unrelated', 'value');
    expect($container->get('service'))->toBe($resolved);

    $container->alias('unrelated.alias', 'unrelated');
    expect($container->get('service'))->toBe($resolved);

    $container->set('other', 'base');
    $container->delegator('other', static fn(string $entry): string => $entry . ':other');
    expect($container->get('service'))->toBe($resolved);
});

it('re-resolves a deferred callable when that callable service is decorated', function (): void {
    $container = (new ContainerBuilder())->build();
    $handler = static fn(string $entry, ContainerInterface $_container): string => $entry . ':handler';

    $container->set('handler', $handler);
    $container->set('service', 'base');
    $container->delegator('service', 'handler');

    expect($container->get('service'))->toBe('base:handler');

    $container->delegator(
        'handler',
        static fn(callable $entry, ContainerInterface $_container): callable =>
            static fn(string $value, ContainerInterface $container): string =>
                $entry($value, $container) . ':decorated',
    );

    expect($container->get('service'))->toBe('base:handler:decorated');
});

it('keeps opaque alias roots on the runtime path instead of treating them as missing classes', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-opaque-aot-' . bin2hex(random_bytes(5));

    try {
        $compiled = (new ContainerBuilder())
            ->addAlias(IndependentOpaqueAliasContract::class, 'external.service')
            ->compileFactories([IndependentOpaqueAliasContract::class], $directory);

        expect($compiled)->toBe([]);
    } finally {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
});

it('keeps direct compiled definitions path-flexible without skipping returned-class validation', function (): void {
    $class = 'IndependentCompiledDefinition_' . bin2hex(random_bytes(6));
    $file = tempnam(sys_get_temp_dir(), 'componenta-di-independent-compiled-');

    expect($file)->not->toBeFalse();
    /** @var non-empty-string $file */
    file_put_contents(
        $file,
        sprintf(
            <<<'PHP'
<?php

final class %s
{
    public function __construct(
        array $parameterResolvers,
        array $attributeHandlers,
        \Componenta\DI\ProxyFactoryInterface $proxyFactory,
    ) {}

    public function create(array $parameters = []): string
    {
        return 'must-not-resolve';
    }
}

return \stdClass::class;
PHP,
            $class,
        ),
    );

    try {
        $container = ContainerBuilder::configureWithDependencies(
            new Config([]),
            [
                ConfigKey::FACTORIES => [
                    'entry' => new CompiledFactoryDefinition($file, $class, 'create'),
                ],
            ],
        )->build();

        expect(fn() => $container->get('entry'))
            ->toThrow(InvalidConfigurationException::class, 'unexpected class');
    } finally {
        @unlink($file);
    }
});
