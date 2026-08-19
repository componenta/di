<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\Config\Config;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Cache\DiCacheGenerator;
use Componenta\DI\ConfigKey;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\CompilationException;
use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Exception\ValidationExceptionInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use DomainException;
use Nyholm\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Throwable;

final class ForeignDiFailure extends RuntimeException {}
final class ForeignNotFoundFailure extends RuntimeException implements NotFoundExceptionInterface {}
final class SyntheticValidationFailure extends RuntimeException implements ValidationExceptionInterface {}

final readonly class ThrowingParameterResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'value';
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        throw new ForeignDiFailure('parameter failure');
    }
}

final readonly class ExceptionResolutionTarget
{
    public function __construct(public string $value) {}
}

final class ThrowingConstructorTarget
{
    public function __construct()
    {
        throw new ForeignDiFailure('constructor failure');
    }
}

#[Lazy]
class LazyThrowingConstructorTarget
{
    public string $value;

    public function __construct()
    {
        throw new ForeignDiFailure('lazy constructor failure');
    }
}

final readonly class ValidationDto
{
    public function __construct(public string $name) {}
}

final readonly class ValidationEnvelope
{
    public function __construct(
        #[MapRequestPayload]
        public ValidationDto $dto,
    ) {}
}

final readonly class ThrowingValidator implements ValidatorInterface
{
    public function validate(
        iterable $data,
        ?ContextInterface $context = null,
    ): true|ErrorMessageCollectorInterface {
        throw new SyntheticValidationFailure('validation failure');
    }
}

final readonly class ThrowingValidationProvider implements ValidationProviderInterface
{
    public function provide(string $entryId): ?ValidatorInterface
    {
        return $entryId === ValidationDto::class ? new ThrowingValidator() : null;
    }
}

final readonly class ForeignExternalContainer implements ContainerInterface
{
    public function __construct(
        private bool $throwFromHas = false,
        private bool $notFound = false,
    ) {}

    public function get(string $id): mixed
    {
        if ($this->notFound) {
            throw new ForeignNotFoundFailure('foreign not found');
        }

        throw new ForeignDiFailure('foreign container failure');
    }

    public function has(string $id): bool
    {
        if ($this->throwFromHas) {
            throw new ForeignDiFailure('foreign has failure');
        }

        return true;
    }
}

/** @return Throwable */
function exceptionFrom(callable $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $e) {
        return $e;
    }

    throw new RuntimeException('Expected operation to throw.');
}

test('foreign parameter resolver failures normalize identically for make and call', function (): void {
    $container = (new ContainerBuilder())
        ->addParameterResolver(new ThrowingParameterResolver(), 2000)
        ->build();

    $make = exceptionFrom(fn() => $container->make(ExceptionResolutionTarget::class));
    $call = exceptionFrom(fn() => $container->call(static fn(string $value): string => $value));

    expect($make)->toBeInstanceOf(ResolutionException::class)
        ->and($make)->toBeInstanceOf(ExceptionInterface::class)
        ->and($make->getPrevious())->toBeInstanceOf(ForeignDiFailure::class)
        ->and($call)->toBeInstanceOf(ResolutionException::class)
        ->and($call)->toBeInstanceOf(ExceptionInterface::class)
        ->and($call->getPrevious())->toBeInstanceOf(ForeignDiFailure::class);
});

test('foreign resolver failures have the same contract in reflection and compiled mode', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $directory = sys_get_temp_dir() . '/componenta-di-v5-exception-aot-' . $suffix;
    $namespace = 'Componenta\\DI\\Tests\\Generated\\ExceptionContract' . $suffix;
    $builder = (new ContainerBuilder())
        ->addParameterResolver(new ThrowingParameterResolver(), 2000);

    try {
        $reflection = exceptionFrom(fn() => $builder->build()->make(ExceptionResolutionTarget::class));
        $compiled = $builder->compileFactories(
            [ExceptionResolutionTarget::class],
            $directory,
            namespace: $namespace,
        );

        $data = $builder->toArray();
        $dependencies = $data[ConfigKey::DEPENDENCIES] ?? [];
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
        $aot = exceptionFrom(fn() => $production->make(ExceptionResolutionTarget::class));

        expect($reflection)->toBeInstanceOf(ResolutionException::class)
            ->and($aot)->toBeInstanceOf(ResolutionException::class)
            ->and($aot::class)->toBe($reflection::class)
            ->and($reflection->getPrevious())->toBeInstanceOf(ForeignDiFailure::class)
            ->and($aot->getPrevious())->toBeInstanceOf(ForeignDiFailure::class);
    } finally {
        foreach (glob($directory . '/container.factories.*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($directory);
    }
});

test('exceptions thrown by the explicit user callable body propagate unchanged', function (): void {
    $container = (new ContainerBuilder())->build();
    $expected = new DomainException('application failure');

    $actual = exceptionFrom(static function () use ($container, $expected): void {
        $container->call(static function () use ($expected): never {
            throw $expected;
        });
    });

    expect($actual)->toBe($expected);
});

test('constructor failures have the same Componenta contract for get and make', function (): void {
    $container = (new ContainerBuilder())->build();

    foreach ([
        fn() => $container->get(ThrowingConstructorTarget::class),
        fn() => $container->make(ThrowingConstructorTarget::class),
    ] as $operation) {
        $error = exceptionFrom($operation);
        expect($error)->toBeInstanceOf(ResolutionException::class)
            ->and($error)->toBeInstanceOf(ExceptionInterface::class)
            ->and($error->getPrevious())->toBeInstanceOf(ForeignDiFailure::class);
    }
});

test('built in lazy initialization keeps the strict exception boundary after get returns', function (): void {
    $container = (new ContainerBuilder())->build();
    $entry = $container->get(LazyThrowingConstructorTarget::class);

    $error = exceptionFrom(static fn() => $entry->value);

    expect($error)->toBeInstanceOf(ResolutionException::class)
        ->and($error)->toBeInstanceOf(ExceptionInterface::class)
        ->and($error->getPrevious())->toBeInstanceOf(ForeignDiFailure::class);
});

test('foreign delegator failures become DelegatorException with their cause preserved', function (): void {
    $container = (new ContainerBuilder())
        ->addService('decorated', new \stdClass())
        ->addDelegator('decorated', static function (): never {
            throw new ForeignDiFailure('delegator failure');
        })
        ->build();

    $error = exceptionFrom(fn() => $container->get('decorated'));

    expect($error)->toBeInstanceOf(DelegatorException::class)
        ->and($error->getPrevious())->toBeInstanceOf(ForeignDiFailure::class);
});

test('foreign external container failures are normalized to Componenta exceptions', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->addContainer(new ForeignExternalContainer());

    $error = exceptionFrom(fn() => $container->get('foreign'));

    expect($error)->toBeInstanceOf(ResolutionException::class)
        ->and($error->getPrevious())->toBeInstanceOf(ForeignDiFailure::class);
});

test('foreign PSR not found errors retain PSR semantics through Componenta NotFoundException', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->addContainer(new ForeignExternalContainer(notFound: true));

    $error = exceptionFrom(fn() => $container->get('foreign-missing'));

    expect($error)->toBeInstanceOf(NotFoundException::class)
        ->and($error)->toBeInstanceOf(NotFoundExceptionInterface::class)
        ->and($error->id)->toBe('foreign-missing')
        ->and($error->getPrevious())->toBeInstanceOf(ForeignNotFoundFailure::class);
});

test('has remains a boolean capability check for ordinary foreign exceptions', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->addContainer(new ForeignExternalContainer(throwFromHas: true));

    expect($container->has('unstable'))->toBeFalse();
});

test('request validation failures are preserved as the cause of a parameter ResolutionException', function (): void {
    $container = (new ContainerBuilder())
        ->addService(ValidationProviderInterface::class, new ThrowingValidationProvider())
        ->build();
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['name' => 'Ada']);

    $error = exceptionFrom(fn() => $container->make(ValidationEnvelope::class, [
        ServerRequestInterface::class => $request,
    ]));

    expect($error)->toBeInstanceOf(ResolutionException::class)
        ->and($error->parameter?->getName())->toBe('dto')
        ->and($error->getPrevious())->toBeInstanceOf(SyntheticValidationFailure::class);
});

test('builder extension factory failures are configuration failures with the original cause', function (): void {
    $builder = (new ContainerBuilder())->addParameterResolver(
        static function (ContainerInterface $_container): object {
            throw new ForeignDiFailure('extension factory failure');
        },
        2100,
    );

    $error = exceptionFrom(fn() => $builder->build());

    expect($error)->toBeInstanceOf(InvalidConfigurationException::class)
        ->and($error->getPrevious())->toBeInstanceOf(ForeignDiFailure::class);
});

test('attribute composition failures are a specialized configuration failure', function (): void {
    expect(is_subclass_of(AttributeCompositionException::class, InvalidConfigurationException::class))->toBeTrue();
});

test('cache artifact filesystem failures use CompilationException', function (): void {
    $error = exceptionFrom(fn() => (new DiCacheGenerator())->generate([], '/dev/null/componenta-di-cache.php'));

    expect($error)->toBeInstanceOf(CompilationException::class)
        ->and($error)->toBeInstanceOf(ExceptionInterface::class);
});

test('the redundant callable exception marker is no longer exposed', function (): void {
    expect(interface_exists('Componenta\\DI\\Exception\\CallableExceptionInterface'))->toBeFalse();
});
