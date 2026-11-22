<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\V5;

use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\DelegatorException;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Exception\ValidationExceptionInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use DomainException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;
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

final class OwnedConstructorFailureState
{
    public bool $fail = true;
    public int $attempts = 0;
    public int $completed = 0;
    public ?NotFoundException $failure = null;
}

class OwnedThrowingConstructorTarget
{
    public string $value;

    public function __construct(ContainerInterface $container, OwnedConstructorFailureState $state)
    {
        ++$state->attempts;
        if ($state->fail) {
            try {
                $container->get('constructor-body.missing');
            } catch (NotFoundException $error) {
                $state->failure = $error;
                throw $error;
            }
        }

        $this->value = 'ready';
        ++$state->completed;
    }
}

#[Lazy]
final class LazyOwnedThrowingConstructorTarget extends OwnedThrowingConstructorTarget {}

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
    /** @param iterable<array-key,mixed> $data */
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

test('foreign resolver failures have the same contract for every container build', function (): void {
    $errors = [];
    foreach ([1, 2] as $_build) {
        $container = (new ContainerBuilder())
            ->addParameterResolver(new ThrowingParameterResolver(), 2000)
            ->build();
        $errors[] = exceptionFrom(
            fn() => $container->make(ExceptionResolutionTarget::class),
        );
    }

    expect($errors[0])->toBeInstanceOf(ResolutionException::class)
        ->and($errors[1])->toBeInstanceOf(ResolutionException::class)
        ->and($errors[1]::class)->toBe($errors[0]::class)
        ->and($errors[0]->getPrevious())->toBeInstanceOf(ForeignDiFailure::class)
        ->and($errors[1]->getPrevious())->toBeInstanceOf(ForeignDiFailure::class);
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
    Assert::assertInstanceOf(LazyThrowingConstructorTarget::class, $entry);

    $error = exceptionFrom(static fn() => $entry->value);

    expect($error)->toBeInstanceOf(ResolutionException::class)
        ->and($error)->toBeInstanceOf(ExceptionInterface::class)
        ->and($error->getPrevious())->toBeInstanceOf(ForeignDiFailure::class);
});

test('constructor failures keep their cause and recover without a completed failed instance', function (
    string $class,
    bool $lazy,
): void {
    if (!is_a($class, OwnedThrowingConstructorTarget::class, true)) {
        throw new RuntimeException('Expected an owned constructor failure fixture.');
    }
    $state = new OwnedConstructorFailureState();
    $container = (new ContainerBuilder())
        ->addService(OwnedConstructorFailureState::class, $state)
        ->build();
    $entry = null;

    $error = exceptionFrom(static function () use ($container, $class, &$entry): string {
        $entry = $container->get($class);
        if (!$entry instanceof OwnedThrowingConstructorTarget) {
            throw new RuntimeException('Expected the constructor failure target.');
        }
        return $entry->value;
    });

    $cause = $lazy ? $error : $error->getPrevious();
    if (!$cause instanceof NotFoundException) {
        throw new RuntimeException('Expected the missing dependency of the constructor.', previous: $error);
    }
    expect($cause)->toBe($state->failure)
        ->and($cause->id)->toBe('constructor-body.missing')
        ->and($cause->getPrevious())->toBeNull()
        ->and($state->attempts)->toBe(1)
        ->and($state->completed)->toBe(0);

    if ($lazy) {
        if (!$entry instanceof OwnedThrowingConstructorTarget) {
            throw new RuntimeException('The lazy object must exist before its constructor runs.');
        }
        expect(new \ReflectionClass($class)->isUninitializedLazyObject($entry))->toBeTrue()
            ->and($container->get($class))->toBe($entry);
    } else {
        expect($entry)->toBeNull()->and($error)->toBeInstanceOf(ResolutionException::class);
    }

    $state->fail = false;
    $recovered = $container->get($class);
    if (!$recovered instanceof OwnedThrowingConstructorTarget) {
        throw new RuntimeException('Expected the recovered constructor target.');
    }
    expect($recovered->value)->toBe('ready')
        ->and($state->attempts)->toBe(2)
        ->and($state->completed)->toBe(1)
        ->and($container->get($class))->toBe($recovered);
    if ($lazy) {
        expect($recovered)->toBe($entry);
    }
})->with([
    'eager' => [OwnedThrowingConstructorTarget::class, false],
    'lazy' => [LazyOwnedThrowingConstructorTarget::class, true],
]);

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

test('a selected external owner reports a retrieval failure if its get throws not found', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->addContainer(new ForeignExternalContainer(notFound: true));

    expect($container->has('foreign-missing'))->toBeTrue();
    $error = exceptionFrom(fn() => $container->get('foreign-missing'));
    if (!$error instanceof ResolutionException) {
        throw new RuntimeException('Expected a retrieval failure.');
    }

    expect($error->serviceId)->toBe('foreign-missing')
        ->and($error->getPrevious())->toBeInstanceOf(ForeignNotFoundFailure::class);
});

test('has and get preserve failures while checking external ownership', function (): void {
    $container = (new ContainerBuilder())->build();
    $container->addContainer(new ForeignExternalContainer(throwFromHas: true));

    foreach ([$container->has(...), $container->get(...)] as $lookup) {
        $error = exceptionFrom(fn() => $lookup('unstable'));
        expect($error)->toBeInstanceOf(ResolutionException::class)
            ->and($error->getPrevious())->toBeInstanceOf(ForeignDiFailure::class)
            ->and($error->getPrevious()?->getMessage())->toBe('foreign has failure');
    }
});

test('request validation failures are preserved as the cause of a parameter ResolutionException', function (): void {
    $container = (new ContainerBuilder())
        ->addService(ValidationProviderInterface::class, new ThrowingValidationProvider())
        ->build();
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['name' => 'Ada']);

    $error = exceptionFrom(fn() => $container->make(ValidationEnvelope::class, [
        ServerRequestInterface::class => $request,
    ]));
    if (!$error instanceof ResolutionException) {
        throw new RuntimeException('Expected a parameter ResolutionException.');
    }

    expect($error)->toBeInstanceOf(ResolutionException::class)
        ->and($error->parameter?->name)->toBe('dto')
        ->and($error->getPrevious())->toBeInstanceOf(SyntheticValidationFailure::class);
});

test('extension factory failures are configuration failures with the original cause', function (): void {
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

test('container factory reclassifies runtime DI extension failures as configuration failures', function (): void {
    $cause = new ResolutionException('extension resolution failure');
    $builder = (new ContainerBuilder())->addParameterResolver(
        static function (ContainerInterface $_container) use ($cause): object {
            throw $cause;
        },
        2200,
    );

    $error = exceptionFrom(fn() => $builder->build());

    expect($error)->toBeInstanceOf(InvalidConfigurationException::class)
        ->and($error->getPrevious())->toBe($cause);
});

test('attribute composition failures are a specialized configuration failure', function (): void {
    $parent = (new \ReflectionClass(AttributeCompositionException::class))->getParentClass();

    if (!$parent instanceof \ReflectionClass) {
        throw new RuntimeException('Expected AttributeCompositionException to have a parent class.');
    }

    expect($parent->getName())->toBe(InvalidConfigurationException::class);
});
