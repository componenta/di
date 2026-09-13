<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestProviderRecovery;

use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\Validator;
use Componenta\Validation\ValidatorInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

final class ScalarConsumer
{
    public int $calls = 0;

    public function __invoke(#[QueryParam('value', cast: 'int')] int $value): int
    {
        ++$this->calls;
        return $value;
    }
}

final class Input
{
    public static int $constructed = 0;

    public function __construct(public string $name)
    {
        ++self::$constructed;
    }
}

final class MappingConsumer
{
    public int $calls = 0;

    public function __invoke(#[MapQueryString] Input $input): string
    {
        ++$this->calls;
        return $input->name;
    }
}

final class InputValidationProvider implements ValidationProviderInterface
{
    public function provide(string $entryId): ?ValidatorInterface
    {
        return $entryId === Input::class ? new Validator(['name' => new Required()]) : null;
    }
}

function failure(callable $operation): Throwable
{
    try {
        $operation();
    } catch (Throwable $error) {
        return $error;
    }
    throw new \LogicException('Expected the provider failure to prevent invocation.');
}

test('request casting observes provider failures and recovers after replacement on a warmed callable', function (bool $throws): void {
    $container = (new ContainerBuilder())->addService(CasterProviderInterface::class, new TestCasterProvider())->build();
    $consumer = new ScalarConsumer();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => '12']);
    $parameters = [ServerRequestInterface::class => $request];
    expect($container->call($consumer, $parameters))->toBe(12);

    $cause = new \RuntimeException('Caster provider unavailable.');
    $broken = $throws ? new class ($cause) implements CasterProviderInterface {
        public function __construct(private readonly Throwable $cause) {}

        public function provide(string $name): ?CasterInterface
        {
            throw $this->cause;
        }
    } : new \stdClass();
    $container->set(CasterProviderInterface::class, $broken);
    $error = failure(fn() => $container->call($consumer, $parameters));
    if ($throws) {
        expect($error)->toBeInstanceOf(ResolutionException::class)
            ->and($error->getPrevious())->toBe($cause);
    } else {
        expect($error)->toBeInstanceOf(InvalidConfigurationException::class)
            ->and($error->getMessage())->toContain(CasterProviderInterface::class, 'stdClass');
    }
    expect($consumer->calls)->toBe(1);

    $container->set(CasterProviderInterface::class, new TestCasterProvider());
    expect($container->call($consumer, [ServerRequestInterface::class => $request->withQueryParams(['value' => '23'])]))->toBe(23)
        ->and($consumer->calls)->toBe(2);
})->with(['wrong service type' => false, 'throwing provider' => true]);

test('request mapping observes validation provider failures before construction and recovers on a warmed callable', function (bool $throws): void {
    Input::$constructed = 0;
    try {
        $container = (new ContainerBuilder())->addService(ValidationProviderInterface::class, new InputValidationProvider())->build();
        $consumer = new MappingConsumer();
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['name' => 'first']);
        $parameters = [ServerRequestInterface::class => $request];
        expect($container->call($consumer, $parameters))->toBe('first');

        $cause = new \RuntimeException('Validation provider unavailable.');
        $broken = $throws ? new class ($cause) implements ValidationProviderInterface {
            public function __construct(private readonly Throwable $cause) {}

            public function provide(string $entryId): ?ValidatorInterface
            {
                throw $this->cause;
            }
        } : new \stdClass();
        $container->set(ValidationProviderInterface::class, $broken);
        $error = failure(fn() => $container->call($consumer, $parameters));
        if ($throws) {
            expect($error)->toBeInstanceOf(ResolutionException::class)
                ->and($error->getPrevious())->toBe($cause);
        } else {
            expect($error)->toBeInstanceOf(InvalidConfigurationException::class)
                ->and($error->getMessage())->toContain(ValidationProviderInterface::class, 'stdClass');
        }
        expect($consumer->calls)->toBe(1)
            ->and(Input::$constructed)->toBe(1);

        $container->set(ValidationProviderInterface::class, new InputValidationProvider());
        expect($container->call($consumer, [ServerRequestInterface::class => $request->withQueryParams(['name' => 'second'])]))->toBe('second')
            ->and($consumer->calls)->toBe(2)
            ->and(Input::$constructed)->toBe(2);
    } finally {
        Input::$constructed = 0;
    }
})->with(['wrong service type' => false, 'throwing provider' => true]);
