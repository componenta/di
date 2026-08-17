<?php

declare(strict_types=1);

use Componenta\Caster\CasterProviderInterface;
use Componenta\Caster\NullCasterProvider;
use Componenta\Config\Config;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ConfigKey;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class MappedHighPriorityDto
{
    public function __construct(
        #[Header('X-Token')]
        public string $token,
    ) {}
}

final readonly class MappedHighPriorityEntry
{
    public function __construct(
        #[MapRequestPayload]
        public MappedHighPriorityDto $dto,
    ) {}
}

final readonly class MappedHighPriorityResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'token';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        return [$target->position, 'custom-high-priority'];
    }
}

function mappedHighPriorityBuilder(): ContainerBuilder
{
    return ContainerBuilder::configure(
        new Config((new ConfigProvider())()),
    )->addService(
        CasterProviderInterface::class,
        new NullCasterProvider(),
    )->addParameterResolver(
        new MappedHighPriorityResolver(),
        5000,
    );
}

/** @return array{0: Container, 1: Container, 2: string} */
function mappedHighPriorityContainers(): array
{
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-mapped-high-priority-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\MappedHighPriority' . $suffix;
    $development = mappedHighPriorityBuilder()->build();
    $compiler = mappedHighPriorityBuilder();
    $compiledFactories = $compiler->compileFactories(
        [MappedHighPriorityEntry::class, MappedHighPriorityDto::class],
        $directory,
        namespace: $namespace,
    );
    $configData = $compiler->toArray();
    $dependencies = $configData[ConfigKey::DEPENDENCIES] ?? [];
    $dependencies[ConfigKey::FACTORIES] = array_replace(
        $dependencies[ConfigKey::FACTORIES] ?? [],
        $compiledFactories,
    );

    $production = ContainerBuilder::configureFromCache(
        new Config([]),
        [
            'version' => ContainerBuilder::CACHE_VERSION,
            ConfigKey::DEPENDENCIES => $dependencies,
        ],
        $directory,
    )->build();

    return [$development, $production, $directory];
}

function cleanupMappedHighPriorityDirectory(string $directory): void
{
    foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
        @unlink($file);
    }

    if (is_dir($directory)) {
        @rmdir($directory);
    }
}

/** @return array{class-string, string, class-string, string} */
function mappedHighPriorityConflictSnapshot(
    Container $container,
    ServerRequestInterface $request,
): array {
    try {
        $container->make(MappedHighPriorityEntry::class, [
            ServerRequestInterface::class => $request,
        ]);
    } catch (RequestParameterSourceConflictException $exception) {
        return [
            $exception::class,
            $exception->key,
            $exception->source,
            $exception->parameter ?? '',
        ];
    }

    throw new RuntimeException('Expected mapped high-priority source conflict.');
}

it('rejects mapped source conflicts before a higher-priority custom resolver can run', function (): void {
    $request = (new FakeServerRequest(
        method: 'POST',
        parsedBody: ['token' => 'attacker-token'],
    ))->withHeader('X-Token', 'trusted-token');

    $snapshot = mappedHighPriorityConflictSnapshot(
        mappedHighPriorityBuilder()->build(),
        $request,
    );

    expect($snapshot[1])->toBe('token')
        ->and($snapshot[2])->toBe(Header::class)
        ->and($snapshot[3])->toBe('token');
});

it('keeps the pre-priority invariant identical in development and compiled production', function (): void {
    [$development, $production, $directory] = mappedHighPriorityContainers();
    $request = (new FakeServerRequest(
        method: 'POST',
        parsedBody: ['token' => 'attacker-token'],
    ))->withHeader('X-Token', 'trusted-token');

    try {
        expect(mappedHighPriorityConflictSnapshot($production, $request))
            ->toBe(mappedHighPriorityConflictSnapshot($development, $request));
    } finally {
        cleanupMappedHighPriorityDirectory($directory);
    }
});
