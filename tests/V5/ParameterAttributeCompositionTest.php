<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final class QueryThenCastDto
{
    public function __construct(
        #[QueryParam('count'), Cast('int')]
        public int $count,
    ) {}
}

final class CastDeclaredBeforeQueryDto
{
    public function __construct(
        #[Cast('int'), QueryParam('count')]
        public int $count,
    ) {}
}

final class ConflictingRequestSourcesDto
{
    public function __construct(
        #[QueryParam('value'), Header('X-Value')]
        public string $value,
    ) {}
}

final class ExplicitCastCompositionDto
{
    public function __construct(#[Cast('int')] public int $value) {}
}

final class ConfigThenCastPropertyDto
{
    #[Cast('trim'), ConfigAttribute('raw')]
    public string $value;
}

function composedParameterContainer(): \Componenta\DI\Container
{
    return (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();
}

function cleanupParameterCompositionDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (glob($directory . '/*') ?: [] as $file) {
        is_dir($file) ? cleanupParameterCompositionDirectory($file) : @unlink($file);
    }
    @rmdir($directory);
}

test('a request value source composes with Cast on one parameter', function (): void {
    $request = (new ServerRequest('GET', '/?count=41'))
        ->withQueryParams(['count' => '41']);

    $entry = composedParameterContainer()->make(
        QueryThenCastDto::class,
        [ServerRequestInterface::class => $request],
    );

    expect($entry->count)->toBe(41);
});

test('composition ordering runs value providers before transformers regardless of declaration order', function (): void {
    $request = (new ServerRequest('GET', '/?count=42'))
        ->withQueryParams(['count' => '42']);

    $entry = composedParameterContainer()->make(
        CastDeclaredBeforeQueryDto::class,
        [ServerRequestInterface::class => $request],
    );

    expect($entry->count)->toBe(42);
});

test('incompatible parameter value sources fail composition before resolution', function (): void {
    $request = (new ServerRequest('GET', '/?value=query'))
        ->withQueryParams(['value' => 'query'])
        ->withHeader('X-Value', 'header');

    expect(fn() => composedParameterContainer()->make(
        ConflictingRequestSourcesDto::class,
        [ServerRequestInterface::class => $request],
    ))->toThrow(AttributeCompositionException::class);
});

test('attribute composition transforms explicit caller input', function (): void {
    $entry = composedParameterContainer()->make(
        ExplicitCastCompositionDto::class,
        ['value' => '43'],
    );

    expect($entry->value)->toBe(43);
});

test('property value providers compose with transformers in semantic order', function (): void {
    $container = ContainerBuilder::configure(new Config(['raw' => '  composed  ']))
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();

    expect($container->make(ConfigThenCastPropertyDto::class)->value)->toBe('composed');
});

test('composed parameter attributes execute identically in development and AOT', function (): void {
    $directory = sys_get_temp_dir() . '/componenta-di-v5-parameter-composition-' . bin2hex(random_bytes(5));
    $builder = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new TestCasterProvider());
    $development = $builder->build();

    try {
        $compiled = $builder->compileFactories([QueryThenCastDto::class], $directory);
        $data = $builder->toArray();
        $dependencies = $data[ConfigKey::DEPENDENCIES];
        $dependencies[ConfigKey::FACTORIES] = array_replace(
            $dependencies[ConfigKey::FACTORIES] ?? [],
            $compiled,
        );
        $production = ContainerBuilder::configureFromCache(
            new Config([]),
            [
                'version' => ContainerBuilder::CACHE_VERSION,
                ConfigKey::DEPENDENCIES => $dependencies,
            ],
            $directory,
        )->build();

        $request = (new ServerRequest('GET', '/?count=44'))
            ->withQueryParams(['count' => '44']);
        $params = [ServerRequestInterface::class => $request];

        expect($development->make(QueryThenCastDto::class, $params)->count)->toBe(44)
            ->and($production->make(QueryThenCastDto::class, $params)->count)->toBe(44);
    } finally {
        cleanupParameterCompositionDirectory($directory);
    }
});
