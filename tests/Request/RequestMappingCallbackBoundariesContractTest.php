<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestMappingCallbackBoundariesContract;

use Attribute;
use Closure;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Parameter\Request\CallbackMapperInterface;
use Componenta\DI\Resolver\Parameter\Request\MapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Componenta\Validation\Exception\ValidationException;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\Validator;
use Componenta\Validation\ValidatorInterface;
use Nyholm\Psr7\ServerRequest;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Input implements ParameterSourceAttributeInterface {}

final readonly class InputHandler implements ParameterAttributeHandlerInterface
{
    public function __construct(
        private RequestDataExtractorInterface&MapperInterface $mapper,
        private ParameterAttributeHandlerInterface $handler,
    ) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        return $this->handler->resolveParameter($this->mapper, $target, $context, $plan, $value);
    }
}

function builder(RequestDataExtractorInterface&MapperInterface $mapper): ContainerBuilder
{
    return (new ContainerBuilder())->addAttributeDefinition(
        static function (ContainerInterface $container) use ($mapper): AttributeDefinition {
            $handler = $container->get(RequestAttributeHandler::class);
            if (!$handler instanceof ParameterAttributeHandlerInterface) {
                throw new \LogicException('Expected the built-in request handler.');
            }

            return new AttributeDefinition(Input::class, new InputHandler($mapper, $handler), [ValueProvider::class]);
        },
    );
}

final class Journal
{
    /** @var list<string> */
    public array $events = [];
}

final readonly class DirectMapper implements CallbackMapperInterface, RequestDataExtractorInterface
{
    public function __construct(public ?Closure $afterMap, private ?Journal $journal = null) {}

    public function extract(ServerRequestInterface $request): array
    {
        if ($this->journal !== null) {
            $this->journal->events[] = 'extract';
        }
        return $request->getQueryParams();
    }

    public function transform(array $data): array
    {
        if ($this->journal !== null) {
            $this->journal->events[] = 'transform';
        }
        return $data;
    }
}

final readonly class Data
{
    public function __construct(public string $value) {}
}

final readonly class Policy
{
    public function __construct(public string $name) {}
}

interface DeclaredProfile {}

final class ProtectedData implements DeclaredProfile
{
    public static int $constructed = 0;

    public function __construct(
        public string $value,
        #[EntryId('trusted-role')] public string $role,
    ) {
        ++self::$constructed;
    }
}

#[SetUp('configure')]
final class ConfiguredData
{
    public static int $constructed = 0;
    public static int $configured = 0;

    public function __construct(public string $value)
    {
        ++self::$constructed;
    }

    public function configure(#[EntryId('trusted-role')] string $role): void
    {
        ++self::$configured;
    }
}

final readonly class NestedData
{
    public function __construct(
        #[MapRequest(sources: [RequestDataSource::Query])] public ProtectedData $child,
    ) {}
}

final class LateConstruction
{
    public static int $calls = 0;
}

test('afterMap receives the complete declarative result and its output is not mapped again', function (): void {
    $seen = [];
    $returned = ['raw' => 'new', 'value' => ' callback ', 'drop' => 'returned', 'sort' => 'again'];
    $mapper = new MapRequest(
        sources: [RequestDataSource::Query],
        map: ['raw' => 'value'],
        exclude: ['drop'],
        defaults: ['fallback' => '  uncast default  '],
        cast: ['value' => 'trim', 'fallback' => 'trim'],
        sortMap: ['name' => ['name' => 'ASC']],
        afterMap: static function (array $data) use (&$seen, $returned): array {
            $seen[] = $data;
            return $returned;
        },
    );
    $container = builder($mapper)
        ->addService(CasterProviderInterface::class, new TestCasterProvider())
        ->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['raw' => ' mapped ', 'drop' => 'removed', 'sort' => 'name']);

    expect($container->call(static fn(#[Input] array $data): array => $data, [ServerRequestInterface::class => $request]))
        ->toBe($returned)
        ->and($seen)->toBe([['value' => 'mapped', 'fallback' => '  uncast default  ', 'orderBy' => ['name' => 'ASC']]]);
});

test('afterMap follows raw validation and the raw validator is not reused for the final schema', function (): void {
    $journal = new Journal();
    $mapper = new DirectMapper(static function (array $data) use ($journal): array {
        $journal->events[] = 'callback';
        return ['value' => $data['raw']];
    }, $journal);
    $validation = new class ($journal) implements ValidationProviderInterface {
        public function __construct(private Journal $journal) {}

        public function provide(string $entryId): ?ValidatorInterface
        {
            if ($entryId !== Data::class) {
                return null;
            }
            $this->journal->events[] = 'validate';
            return new Validator(['raw' => new Required()]);
        }
    };
    $container = builder($mapper)->addService(ValidationProviderInterface::class, $validation)
        ->addFactory(Data::class, static function (ContainerInterface $container, array $params) use ($journal): Data {
            $journal->events[] = 'factory';
            if (!is_string($params['value'] ?? null)) {
                throw new \LogicException('Expected the final DTO value.');
            }
            return new Data($params['value']);
        })->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['raw' => '']);
    $target = static fn(#[Input] Data $data): Data => $data;

    try {
        $container->call($target, [ServerRequestInterface::class => $request]);
        \PHPUnit\Framework\Assert::fail('Raw validation must prevent normalization.');
    } catch (ResolutionException $error) {
        expect($error->getPrevious())->toBeInstanceOf(ValidationException::class)
            ->and($journal->events)->toBe(['extract', 'validate']);
    }

    expect($container->call($target, [ServerRequestInterface::class => $request->withQueryParams(['raw' => 'valid'])]))
        ->toEqual(new Data('valid'))
        ->and($journal->events)->toBe(['extract', 'validate', 'extract', 'validate', 'transform', 'callback', 'factory']);
});

/** @param Closure(Closure():void):Closure $createCallback */
test('afterMap applies the common semantic-argument ABI before entering the callback', function (Closure $createCallback, string $exception, string $reason): void {
    $callbackCalls = 0;
    $factoryCalls = 0;
    $callback = $createCallback(static function () use (&$callbackCalls): void {
        ++$callbackCalls;
    });
    if (!$callback instanceof Closure) {
        throw new \LogicException('Expected the dataset to supply a callback.');
    }
    $container = builder(new MapRequest(sources: [RequestDataSource::Query], afterMap: $callback))
        ->addFactory(Data::class, static function () use (&$factoryCalls): Data {
            ++$factoryCalls;
            return new Data('unexpected');
        })->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => 'input']);

    expect(fn() => $container->call(static fn(#[Input] Data $data): Data => $data, [ServerRequestInterface::class => $request]))
        ->toThrow($exception, $reason)
        ->and($callbackCalls)->toBe(0)
        ->and($factoryCalls)->toBe(0);
})->with([
    'first by reference' => [static fn(Closure $enter): Closure => static function (array &$data) use ($enter): array {
        $enter();
        return $data;
    }, InvalidConfigurationException::class, 'must not be passed by reference or variadic'],
    'first variadic' => [static fn(Closure $enter): Closure => static function (array ...$data) use ($enter): array {
        $enter();
        return $data;
    }, InvalidConfigurationException::class, 'must not be passed by reference or variadic'],
    'first DI source' => [static fn(Closure $enter): Closure => static function (#[EntryId('reserved')] array $data) use ($enter): array {
        $enter();
        return $data;
    }, InvalidConfigurationException::class, 'must not declare DI attributes'],
    'incompatible first type' => [static fn(Closure $enter): Closure => static function (string $data) use ($enter): array {
        $enter();
        return [$data];
    }, ResolutionException::class, 'semantic callback argument does not satisfy'],
    'suffix by reference' => [static fn(Closure $enter): Closure => static function (array $data, string &$suffix = 'fallback') use ($enter): array {
        $enter();
        return $data;
    }, ResolutionException::class, 'By-reference parameters are not supported'],
]);

test('an empty afterMap signature receives no native arguments', function (): void {
    $counts = [];
    $container = builder(new MapRequest(afterMap: static function () use (&$counts): array {
        $counts[] = func_num_args();
        return ['fixed'];
    }))->build();

    expect($container->call(static fn(#[Input] array $data): array => $data, [ServerRequestInterface::class => new ServerRequest('GET', '/')]))
        ->toBe(['fixed'])
        ->and($counts)->toBe([0]);
});

test('afterMap suffix resolution receives an empty supplied bag and the complete semantic array', function (): void {
    $observer = new class () implements ParameterResolverInterface {
        /** @var list<array{provided:array<array-key,mixed>,resolved:array<int,mixed>}> */
        public array $seen = [];

        public function supports(ParameterTarget $target): bool
        {
            return $target->name === 'probe';
        }

        public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
        {
            $this->seen[] = ['provided' => $context->provided, 'resolved' => $context->resolved];
            return [$target->position, 'observed'];
        }
    };
    $data = ['probe' => 'spoofed', 'marker' => 'spoofed', Policy::class => new Policy('spoofed')];
    $container = builder(new MapRequest(
        sources: [RequestDataSource::Query],
        afterMap: static fn(array $whole, string $probe, Policy $policy, string $marker = 'fallback'): array => [$whole, $probe, $policy->name, $marker],
    ))->addParameterResolver($observer, 1500)->addService(Policy::class, new Policy('trusted'))->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams($data);

    expect($container->call(static fn(#[Input] array $data): array => $data, [ServerRequestInterface::class => $request]))
        ->toBe([$data, 'observed', 'trusted', 'fallback'])
        ->and($observer->seen)->toBe([['provided' => [], 'resolved' => [$data]]]);
});

test('afterMap does not implicitly transport the current request into suffix attributes', function (): void {
    $called = false;
    $container = builder(new MapRequest(afterMap: static function (array $data, #[CurrentRequest] ServerRequestInterface $request) use (&$called): array {
        $called = true;
        return $data;
    }))->build();

    expect(fn() => $container->call(static fn(#[Input] array $data): array => $data, [ServerRequestInterface::class => new ServerRequest('GET', '/')]))
        ->toThrow(ResolutionException::class, 'PSR-7 request is required')
        ->and($called)->toBeFalse();
});

test('afterMap rejects non-array output before calling the DTO factory', function (Closure $callback): void {
    $factoryCalls = 0;
    $container = builder(new MapRequest(afterMap: $callback))
        ->addFactory(Data::class, static function () use (&$factoryCalls): Data {
            ++$factoryCalls;
            return new Data('unexpected');
        })->build();

    expect(fn() => $container->call(static fn(#[Input] Data $data): Data => $data, [ServerRequestInterface::class => new ServerRequest('GET', '/')]))
        ->toThrow(ResolutionException::class, 'afterMap must return an array')
        ->and($factoryCalls)->toBe(0);
})->with([
    'scalar' => [static fn(array $data): int => 1],
    'DTO' => [static fn(array $data): Data => new Data('callback object')],
    'traversable' => [static fn(array $data): \ArrayIterator => new \ArrayIterator(['value' => 'input'])],
    'null' => [static fn(array $data): null => null],
    'void' => [static function (array $data): void {}],
]);

test('afterMap preserves the distinct integer-key contracts of DTO and array targets', function (string $scenario): void {
    $callbackCalls = 0;
    $factoryCalls = 0;
    $raw = $scenario === 'final DTO' ? ['value' => 'raw'] : [7 => 'raw'];
    $final = $scenario === 'raw DTO' ? ['value' => 'final'] : [9 => 'final'];
    $container = builder(new MapRequest(
        sources: [RequestDataSource::Query],
        afterMap: static function (array $data) use (&$callbackCalls, $final): array {
            ++$callbackCalls;
            return $final;
        },
    ))->addFactory(Data::class, static function () use (&$factoryCalls): Data {
        ++$factoryCalls;
        return new Data('unexpected');
    })->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams($raw);
    if ($scenario === 'array') {
        expect($container->call(static fn(#[Input] array $data): array => $data, [ServerRequestInterface::class => $request]))
            ->toBe($final);
    } else {
        expect(fn() => $container->call(static fn(#[Input] Data $data): Data => $data, [ServerRequestInterface::class => $request]))
            ->toThrow(ResolutionException::class, 'HTTP DTO mapping accepts only named string keys');
    }

    expect($callbackCalls)->toBe($scenario === 'raw DTO' ? 0 : 1)
        ->and($factoryCalls)->toBe(0);
})->with(['raw DTO', 'final DTO', 'array']);

test('afterMap detaches top-level references while preserving the append key and nested identity', function (): void {
    $top = 'original';
    $nested = 'nested original';
    $object = (object) ['value' => 'object original'];
    $query = [
        'top' => &$top,
        'nested' => ['value' => &$nested],
        'object' => $object,
        20 => 'removed',
    ];
    unset($query[20]);
    $request = (new ServerRequest('GET', '/'))->withQueryParams($query);
    $container = builder(new DirectMapper(static function (array $data): array {
        /** @var array{top:string,nested:array{value:string},object:\stdClass} $data */
        $data['top'] = 'changed';
        $data['nested']['value'] = 'nested changed';
        $data['object']->value = 'object changed';
        $data[] = 'next';
        return $data;
    }))->build();
    $result = $container->call(static fn(#[Input] array $data): array => $data, [ServerRequestInterface::class => $request]);

    expect($result)->toBe(['top' => 'changed', 'nested' => ['value' => 'nested changed'], 'object' => $object, 21 => 'next'])
        ->and($top)->toBe('original')
        ->and($request->getQueryParams()['top'])->toBe('original')
        ->and($nested)->toBe('nested changed')
        ->and($object->value)->toBe('object changed');
});

test('DTO metadata does not overwrite caller reference slots returned by a mapper or callback', function (bool $callback, bool $fail, string $key): void {
    $original = 'caller value';
    $untouched = 'untouched';
    $nested = 'nested';
    $object = new \stdClass();
    $mapped = ['value' => 'mapped', $key => &$original, 'untouched' => &$untouched, 'nested' => ['value' => &$nested], 'object' => $object];
    $request = (new ServerRequest('GET', '/'))->withQueryParams($mapped);
    $mapper = $callback
        ? new MapRequest(sources: [RequestDataSource::Query], afterMap: static fn(array $data): array => $mapped)
        : new DirectMapper(null);
    $failure = $fail ? new ResolutionException('Chosen DTO factory failure.') : null;
    $seen = [];
    $container = builder($mapper)->addFactory(
        Data::class,
        static function (ContainerInterface $container, array $params) use (&$seen, $failure): Data {
            $seen = [array_keys($params), $params[ServerRequestInterface::class], $params['object']];
            $params['untouched'] = 'factory effect';
            if (!is_array($params['nested'])) {
                throw new \LogicException('Expected nested mapped data.');
            }
            $params['nested']['value'] = 'nested effect';
            if ($failure !== null) {
                throw $failure;
            }
            return new Data('mapped');
        },
    )->build();
    $target = static fn(#[Input] Data $data): Data => $data;

    if ($failure !== null) {
        try {
            $container->call($target, [ServerRequestInterface::class => $request]);
            \PHPUnit\Framework\Assert::fail('The DTO factory must propagate its failure.');
        } catch (ResolutionException $error) {
            expect($error)->toBe($failure);
        }
    } else {
        expect($container->call($target, [ServerRequestInterface::class => $request]))->toEqual(new Data('mapped'));
    }

    $keys = $key === ServerRequestInterface::class
        ? ['value', ServerRequestInterface::class, 'untouched', 'nested', 'object']
        : ['value', 'untouched', 'nested', 'object', ServerRequestInterface::class];
    expect($original)->toBe('caller value')
        ->and($request->getQueryParams()[$key])->toBe('caller value')
        ->and($seen)->toBe([$keys, $request, $object])
        ->and($untouched)->toBe('factory effect')
        ->and($nested)->toBe('nested effect');
})->with(['custom mapper' => [false], 'afterMap output' => [true]])
    ->with(['factory success' => [false], 'factory failure' => [true]])
    ->with(['request transport' => [ServerRequestInterface::class], 'private provenance' => ["\0componenta.di.mapped-request"]]);

test('afterMap can remove a raw source-shaped key without replacing the trusted source', function (): void {
    ProtectedData::$constructed = 0;
    $container = builder(new MapRequest(sources: [RequestDataSource::Query], afterMap: static function (array $data): array {
        unset($data['role']);
        return $data;
    }))->addService('trusted-role', 'reader')->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => 'input', 'role' => 'administrator']);
    $result = $container->call(static fn(#[Input] ProtectedData $data): ProtectedData => $data, [ServerRequestInterface::class => $request]);

    expect($result)->toBeInstanceOf(ProtectedData::class);
    if (!$result instanceof ProtectedData) {
        throw new \LogicException('Expected the source-protected DTO.');
    }
    expect([$result->value, $result->role])->toBe(['input', 'reader'])
        ->and(ProtectedData::$constructed)->toBe(1)
        ->and($request->getQueryParams()['role'])->toBe('administrator');
});

test('afterMap cannot supply a new final source-shaped key to a known DTO constructor', function (): void {
    $factoryCalls = 0;
    $container = builder(new MapRequest(afterMap: static fn(array $data): array => ['value' => 'input', 'role' => 'administrator']))
        ->addFactory(ProtectedData::class, static function () use (&$factoryCalls): ProtectedData {
            ++$factoryCalls;
            return new ProtectedData('unexpected', 'reader');
        })->build();

    expect(fn() => $container->call(static fn(#[Input] ProtectedData $data): ProtectedData => $data, [ServerRequestInterface::class => new ServerRequest('GET', '/')]))
        ->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and($factoryCalls)->toBe(0);
});

test('afterMap output remains guarded when an interface selects an actual class with parameter sources', function (): void {
    ProtectedData::$constructed = 0;
    $container = builder(new MapRequest(afterMap: static fn(array $data): array => ['value' => 'input', 'role' => 'administrator']))
        ->addAlias(DeclaredProfile::class, ProtectedData::class)->addService('trusted-role', 'reader')->build();

    expect(fn() => $container->call(static fn(#[Input] DeclaredProfile $data): DeclaredProfile => $data, [ServerRequestInterface::class => new ServerRequest('GET', '/')]))
        ->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and(ProtectedData::$constructed)->toBe(0);
});

test('afterMap provenance reaches setup without promising rollback of the completed constructor', function (): void {
    ConfiguredData::$constructed = 0;
    ConfiguredData::$configured = 0;
    $container = builder(new MapRequest(afterMap: static fn(array $data): array => ['value' => 'input', 'role' => 'administrator']))
        ->addService('trusted-role', 'reader')->build();

    expect(fn() => $container->call(static fn(#[Input] ConfiguredData $data): ConfiguredData => $data, [ServerRequestInterface::class => new ServerRequest('GET', '/')]))
        ->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and(ConfiguredData::$constructed)->toBe(1)
        ->and(ConfiguredData::$configured)->toBe(0);
});

test('nested mapping checks its own original request after the outer callback removes a key', function (): void {
    ProtectedData::$constructed = 0;
    $callbackCalls = 0;
    $container = builder(new MapRequest(sources: [RequestDataSource::Query], afterMap: static function (array $data) use (&$callbackCalls): array {
        ++$callbackCalls;
        unset($data['role']);
        return $data;
    }))->addService('trusted-role', 'reader')->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['value' => 'input', 'role' => 'administrator']);

    expect(fn() => $container->call(static fn(#[Input] NestedData $data): NestedData => $data, [ServerRequestInterface::class => $request]))
        ->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and(ProtectedData::$constructed)->toBe(0)
        ->and($callbackCalls)->toBe(1)
        ->and($request->getQueryParams()['role'])->toBe('administrator');
});

test('a warmed mapped DTO observes source metadata made available inside afterMap', function (): void {
    LateConstruction::$calls = 0;
    $suffix = bin2hex(random_bytes(5));
    $source = 'LateSource_' . $suffix;
    $dto = 'LateDto_' . $suffix;
    $function = 'lateMapping_' . $suffix;
    eval(sprintf(
        'namespace %s; final class %s { public function __construct(#[%s("trusted-role")] public string $role) { ++LateConstruction::$calls; } } function %s(#[Input] %s $data): %s { return $data; }',
        __NAMESPACE__,
        $dto,
        $source,
        $function,
        $dto,
        $dto,
    ));
    $state = new class () {
        public bool $reveal = false;
        public int $callbackCalls = 0;
    };
    $container = builder(new MapRequest(afterMap: static function (array $data) use ($state, $source): array {
        ++$state->callbackCalls;
        if ($state->reveal) {
            class_alias(EntryId::class, __NAMESPACE__ . '\\' . $source);
        }
        return ['role' => 'request'];
    }))->addService('trusted-role', 'reader')->build();
    $callable = __NAMESPACE__ . '\\' . $function;
    $dtoClass = __NAMESPACE__ . '\\' . $dto;
    if (!class_exists($dtoClass)) {
        throw new \LogicException('Expected the generated DTO class.');
    }
    $params = [ServerRequestInterface::class => new ServerRequest('GET', '/')];
    $result = $container->call($callable, $params);
    expect($result)->toBeInstanceOf($dtoClass)
        ->and(LateConstruction::$calls)->toBe(1);

    $state->reveal = true;
    expect(fn() => $container->call($callable, $params))
        ->toThrow(RequestParameterSourceConflictException::class, 'role')
        ->and(LateConstruction::$calls)->toBe(1)
        ->and($state->callbackCalls)->toBe(2);
});
