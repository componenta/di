<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Compile\Autowire\AutowireClassGraph;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Psr\Container\ContainerInterface;

interface IndependentOpaqueAliasContract {}

final class IndependentExtensionResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return false;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return null;
    }
}

final class IndependentExtensionFactory
{
    public function create(ContainerInterface $_container): ParameterResolverInterface
    {
        return new IndependentExtensionResolver();
    }
}

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

it('does not broaden extension configuration to deferred service method arrays', function (): void {
    expect(fn() => ContainerBuilder::configureWithDependencies(
        new Config([]),
        [
            ConfigKey::PARAMETER_RESOLVERS => [
                5000 => ['resolver.factory', 'create'],
            ],
        ],
    ))->toThrow(InvalidConfigurationException::class);
});

it('keeps unrelated namespace mutations from invalidating a cached deferred decoration', function (): void {
    $calls = 0;
    $container = (new ContainerBuilder())->build();

    $container->set('handler', function (string $entry) use (&$calls): string {
        ++$calls;

        return $entry . ':handled';
    });
    $container->set('service', 'base');
    $container->delegator('service', 'handler');

    expect($container->get('service'))->toBe('base:handled')
        ->and($calls)->toBe(1);

    $container->set('unrelated', 'value');
    expect($container->get('service'))->toBe('base:handled')
        ->and($calls)->toBe(1);

    $container->alias('unrelated.alias', 'unrelated');
    expect($container->get('service'))->toBe('base:handled')
        ->and($calls)->toBe(1);

    $container->set('other', 'base');
    $container->delegator('other', static fn(string $entry): string => $entry . ':other');
    expect($container->get('service'))->toBe('base:handled')
        ->and($calls)->toBe(1);
});

it('keeps opaque alias roots on the runtime path instead of treating them as missing classes', function (): void {
    $classes = (new AutowireClassGraph([
        IndependentOpaqueAliasContract::class => 'external.service',
    ]))->expand([IndependentOpaqueAliasContract::class]);

    expect($classes)->toBe([]);
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
