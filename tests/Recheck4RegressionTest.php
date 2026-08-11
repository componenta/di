<?php

declare(strict_types=1);

use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Compile\Factory\CompiledFactoryShardWriter;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\Request\RequestMapperPipeline;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Fixture\FakeServerRequest;

final class Recheck4DynamicInstanceCallable
{
    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): array
    {
        return [$name, $arguments];
    }
}

final class Recheck4DynamicStaticCallable
{
    /** @param array<int, mixed> $arguments */
    public static function __callStatic(string $name, array $arguments): array
    {
        return [$name, $arguments];
    }
}

it('rejects an existing compiled shard whose contents do not match the content-addressed artifact', function (): void {
    $file = sys_get_temp_dir() . '/componenta-corrupt-shard-' . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($file, "<?php\nreturn 'corrupt';\n");

    try {
        expect(fn() => (new CompiledFactoryShardWriter())->write(
            $file,
            "<?php\nreturn 'expected';\n",
        ))->toThrow(RuntimeException::class);
    } finally {
        @unlink($file);
    }
});

it('invokes a valid dynamic instance callable without reflecting a non-existent concrete method', function (): void {
    $container = (new ContainerBuilder())->build();

    expect($container->call([new Recheck4DynamicInstanceCallable(), 'dynamic'], [1, 2]))
        ->toBe(['dynamic', [1, 2]]);
});

it('invokes a valid dynamic static callable without reflecting a non-existent concrete method', function (): void {
    $container = (new ContainerBuilder())->build();

    expect($container->call(Recheck4DynamicStaticCallable::class . '::dynamic', [3, 4]))
        ->toBe(['dynamic', [3, 4]]);
});

it('does not treat an explicitly null sort alias as a missing sort value', function (): void {
    $pipeline = new RequestMapperPipeline();

    expect(fn() => $pipeline->run(
        ['sort' => null],
        [],
        [],
        [],
        ['recent' => ['createdAt' => 'desc']],
        [],
        new NullCasterProvider(),
    ))->toThrow(InvalidArgumentException::class);
});

it('rejects multiple request extraction attributes on the same parameter instead of using declaration order', function (): void {
    $container = (new ContainerBuilder())->build();
    $resolver = new RequestResolver($container, new NullCasterProvider());
    $callable = static function (
        #[QueryParam('value')]
        #[PayloadParam('value')]
        string $value,
    ): string {
        return $value;
    };
    $parameter = (new ReflectionFunction($callable))->getParameters()[0];
    $request = new FakeServerRequest(
        queryParams: ['value' => 'query'],
        parsedBody: ['value' => 'payload'],
    );

    expect(fn() => $resolver->resolveParameter(
        new ParameterTarget($parameter),
        new ParameterResolutionContext(RequestParameter::with([], $request)),
    ))->toThrow(ResolutionException::class);
});
