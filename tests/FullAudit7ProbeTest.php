<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Compile\Autowire\AutowireClassGraph;
use Componenta\DI\Compile\Factory\CompiledFactoryDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\InvalidConfigurationException;

#[Attribute(Attribute::TARGET_PROPERTY)]
readonly class FullAudit7Inject extends Inject {}

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
readonly class FullAudit7SetUp extends SetUp {}

final class FullAudit7Dependency {}

final class FullAudit7InjectedRoot
{
    #[FullAudit7Inject]
    public FullAudit7Dependency $dependency;
}

#[FullAudit7SetUp('boot')]
final class FullAudit7SetUpRoot
{
    public function boot(FullAudit7Dependency $dependency): void {}
}

#[NoConstructor]
final class FullAudit7NoConstructorRoot
{
    private function __construct(FullAudit7Dependency $unused) {}
}

it('does not load a relative compiled shard outside the configured base directory', function (): void {
    $root = sys_get_temp_dir() . '/componenta-di-audit7-' . bin2hex(random_bytes(5));
    $base = $root . '/cache';
    mkdir($base, 0777, true);
    $class = 'FullAudit7EscapedShard_' . bin2hex(random_bytes(5));
    $outside = $root . '/outside.php';
    file_put_contents($outside, sprintf(<<<'PHP'
<?php
final class %s
{
    public function __construct(array $parameterResolvers, array $attributeHandlers, \Componenta\DI\ProxyFactoryInterface $proxyFactory) {}
    public function create(array $parameters = []): object { return new \stdClass(); }
}
return %s::class;
PHP, $class, $class));

    try {
        $container = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => [ConfigKey::FACTORIES => [
                    'escaped' => new CompiledFactoryDefinition('../outside.php', $class, 'create'),
                ]],
            ],
            $base,
        )->build();

        expect(fn() => $container->get('escaped'))
            ->toThrow(InvalidConfigurationException::class);
    } finally {
        @unlink($outside);
        @rmdir($base);
        @rmdir($root);
    }
});

it('invalidates decorated entries when an alias used by a deferred delegator changes', function (): void {
    $first = new class () {
        public function __invoke(mixed $entry): string { return $entry . ':first'; }
    };
    $second = new class () {
        public function __invoke(mixed $entry): string { return $entry . ':second'; }
    };

    $container = (new ContainerBuilder())
        ->addService('audit7.handler.first', $first)
        ->addService('audit7.handler.second', $second)
        ->addAlias('audit7.handler', 'audit7.handler.first')
        ->addService('audit7.service', 'base')
        ->addDelegator('audit7.service', 'audit7.handler')
        ->build();

    expect($container->get('audit7.service'))->toBe('base:first');

    $container->alias('audit7.handler', 'audit7.handler.second');

    expect($container->get('audit7.service'))->toBe('base:second');
});

it('tracks Inject attribute subclasses in the compiled autowire graph', function (): void {
    expect((new AutowireClassGraph())->expand([FullAudit7InjectedRoot::class]))
        ->toContain(FullAudit7Dependency::class);
});

it('tracks SetUp attribute subclasses in the compiled autowire graph', function (): void {
    expect((new AutowireClassGraph())->expand([FullAudit7SetUpRoot::class]))
        ->toContain(FullAudit7Dependency::class);
});

it('does not compile constructor dependencies disabled by NoConstructor', function (): void {
    expect((new AutowireClassGraph())->expand([FullAudit7NoConstructorRoot::class]))
        ->toBe([FullAudit7NoConstructorRoot::class]);
});
