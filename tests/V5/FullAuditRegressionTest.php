<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\CompilationException;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class AuditMutableValidationDto
{
    public function __construct(public string $value) {}
}

final class AuditOffsetCaster implements CasterInterface
{
    public string $name { get => 'audit-offset'; }

    public function __construct(private int $offset) {}

    public function cast(mixed $value): mixed
    {
        return (int) $value + $this->offset;
    }
}

final readonly class AuditOffsetCasterProvider implements CasterProviderInterface
{
    public function __construct(private int $offset) {}

    public function provide(string $name): ?CasterInterface
    {
        return $name === 'audit-offset' ? new AuditOffsetCaster($this->offset) : null;
    }
}

#[SetUp('configure')]
final class AuditSetUpMappedDto
{
    public string $configured = '';

    public function __construct(public string $value) {}

    public function configure(#[Header('X-Value')] string $value): void
    {
        $this->configured = $value;
    }
}

final readonly class AuditSetUpMappedEnvelope
{
    public function __construct(
        #[MapRequestPayload]
        public AuditSetUpMappedDto $dto,
    ) {}
}

final readonly class AuditUnsafeReadonlyCacheValue
{
    public string $value;

    public function __construct(string $value)
    {
        $this->value = '[' . $value . ']';
    }
}

test('request validation provider added after an initial miss is observed without rebuilding the container', function (): void {
    $container = (new ContainerBuilder())->build();
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'first']);

    $first = $container->call(
        static fn(#[MapRequestPayload] AuditMutableValidationDto $dto): string => $dto->value,
        [ServerRequestInterface::class => $request],
    );

    $validator = new class () implements ValidatorInterface {
        public int $validations = 0;

        public function validate(
            iterable $data,
            ?ContextInterface $context = null,
        ): true|ErrorMessageCollectorInterface {
            ++$this->validations;
            return true;
        }
    };
    $provider = new class ($validator) implements ValidationProviderInterface {
        public function __construct(private readonly ValidatorInterface $validator) {}

        public function provide(string $entryId): ?ValidatorInterface
        {
            return $entryId === AuditMutableValidationDto::class ? $this->validator : null;
        }
    };

    $container->set(ValidationProviderInterface::class, $provider);
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'second']);
    $second = $container->call(
        static fn(#[MapRequestPayload] AuditMutableValidationDto $dto): string => $dto->value,
        [ServerRequestInterface::class => $request],
    );

    expect($first)->toBe('first')
        ->and($second)->toBe('second')
        ->and($validator->validations)->toBe(1);
});

test('request caster provider replacement is observed without rebuilding the container', function (): void {
    $container = (new ContainerBuilder())
        ->addService(CasterProviderInterface::class, new AuditOffsetCasterProvider(1))
        ->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => '5']);

    $first = $container->call(
        static fn(#[QueryParam('value', cast: 'audit-offset')] int $value): int => $value,
        [ServerRequestInterface::class => $request],
    );

    $container->set(CasterProviderInterface::class, new AuditOffsetCasterProvider(10));
    $second = $container->call(
        static fn(#[QueryParam('value', cast: 'audit-offset')] int $value): int => $value,
        [ServerRequestInterface::class => $request],
    );

    expect($first)->toBe(6)
        ->and($second)->toBe(15);
});

test('mapped request provenance protects source-bound SetUp parameters', function (): void {
    $request = (new ServerRequest('POST', '/'))
        ->withParsedBody(['value' => 'payload'])
        ->withHeader('X-Value', 'header');
    $container = (new ContainerBuilder())->build();

    expect(fn() => $container->make(AuditSetUpMappedEnvelope::class, [
        ServerRequestInterface::class => $request,
    ]))->toThrow(RequestParameterSourceConflictException::class);
});

test('persistent cache rejects readonly objects whose constructor state cannot be round-tripped exactly', function (): void {
    $path = sys_get_temp_dir() . '/componenta-di-unsafe-readonly-' . bin2hex(random_bytes(5)) . '.php';

    try {
        expect(fn() => (new DiCacheGenerator())->generate([
            ConfigKey::SERVICES => [
                'unsafe' => new AuditUnsafeReadonlyCacheValue('value'),
            ],
        ], $path))->toThrow(CompilationException::class, 'public promoted property');
    } finally {
        @unlink($path);
    }
});
