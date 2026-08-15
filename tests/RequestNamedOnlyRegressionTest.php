<?php

declare(strict_types=1);

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ConfigProvider;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class RequestNamedOnlyRegressionDto
{
    public function __construct(
        public string $name,
        public bool $administrator = false,
    ) {}
}

it('keeps HTTP DTO input named-only while retaining trusted positional make parameters', function (): void {
    $caster = new class () implements CasterInterface {
        public string $name {
            get => 'unused';
        }

        public function cast(mixed $value): mixed
        {
            return $value;
        }
    };
    $provider = new class ($caster) implements CasterProviderInterface {
        public function __construct(private readonly CasterInterface $caster) {}

        public function provide(string $name): ?CasterInterface
        {
            return $name === $this->caster->name ? $this->caster : null;
        }
    };
    $container = ContainerBuilder::configure(new Config((new ConfigProvider())()))
        ->addService(CasterProviderInterface::class, $provider)
        ->build();
    $request = new FakeServerRequest(parsedBody: [
        'name' => 'alice',
        1 => true,
    ]);

    expect(fn() => $container->call(
        static fn(
            #[MapRequestPayload]
            RequestNamedOnlyRegressionDto $dto,
        ): RequestNamedOnlyRegressionDto => $dto,
        [ServerRequestInterface::class => $request],
    ))->toThrow(ResolutionException::class, 'only named string keys');

    $programmatic = $container->make(RequestNamedOnlyRegressionDto::class, [
        0 => 'trusted',
        1 => true,
    ]);

    expect($programmatic->name)->toBe('trusted')
        ->and($programmatic->administrator)->toBeTrue();
});
