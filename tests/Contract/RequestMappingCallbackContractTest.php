<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestMappingCallback;

use Attribute;
use Closure;
use Componenta\Caster\CasterProviderAwareInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\Request\CallbackMapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\Validator;
use Componenta\Validation\ValidatorInterface;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;

final class Calls
{
    public static int $callbacks = 0;
    public static int $constructors = 0;
    /** @var list<string> */
    public static array $events = [];
}

final readonly class NameFormatter
{
    public function __construct(private bool $familyFirst = false) {}

    public function format(string $given, string $family): string
    {
        return $this->familyFirst
            ? trim($family) . ', ' . trim($given)
            : trim($given) . ' ' . trim($family);
    }
}

final readonly class Profile
{
    public function __construct(
        public string $displayName,
        #[EntryId('request.callback.role')] public string $role,
    ) {
        ++Calls::$constructors;
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LegacyQueryMapper extends MapQueryString
{
    public string $afterMap = 'application-owned setting';

    public function transform(array $data): array
    {
        return parent::transform($data) + ['legacy' => $this->afterMap];
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ObservableMapper implements RequestDataExtractorInterface, CallbackMapperInterface, CasterProviderAwareInterface
{
    public ?Closure $afterMap {
        get {
            Calls::$events[] = 'callback configuration';
            return normalize(...);
        }
    }

    public CasterProviderInterface $provider {
        set(CasterProviderInterface $value) {
            Calls::$events[] = 'caster provider';
            $this->provider = $value;
        }
    }

    public function extract(ServerRequestInterface $request): array
    {
        Calls::$events[] = 'extract';
        return $request->getQueryParams();
    }

    public function transform(array $data): array
    {
        Calls::$events[] = 'transform';
        return $data;
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ReadonlyMapper implements RequestDataExtractorInterface, CallbackMapperInterface
{
    public ?Closure $afterMap;

    public function __construct()
    {
        $this->afterMap = normalize(...);
    }

    public function extract(ServerRequestInterface $request): array
    {
        $data = $request->getAttribute('profile-input');
        if (!is_array($data)) {
            throw new LogicException('The readonly mapper fixture requires array input.');
        }
        return $data;
    }

    public function transform(array $data): array
    {
        return $data;
    }
}

final class ObservedValidation implements ValidationProviderInterface
{
    public function provide(string $entryId): ?ValidatorInterface
    {
        Calls::$events[] = 'validation';
        return $entryId === Profile::class ? new Validator(['given' => new Required()]) : null;
    }
}

/**
 * @param array<string|int,mixed> $data
 * @return array{displayName:string}
 */
function normalize(array $data, NameFormatter $formatter): array
{
    ++Calls::$callbacks;
    $given = $data['given'] ?? null;
    $family = $data['family'] ?? null;
    if (!is_string($given) || !is_string($family)) {
        throw new LogicException('The callback fixture requires two name fields.');
    }

    return ['displayName' => $formatter->format($given, $family)];
}

// Runtime constants keep the public attribute examples parseable on PHP 8.4.
define(__NAMESPACE__ . '\\PROFILE_INPUT', normalize(...));

function payloadProfile(#[MapRequest(afterMap: PROFILE_INPUT)] Profile $profile): Profile
{
    return $profile;
}

function queryProfile(
    #[MapRequest(sources: [RequestDataSource::Query], afterMap: PROFILE_INPUT)] Profile $profile,
): Profile {
    return $profile;
}

function profileBuilder(): ContainerBuilder
{
    return (new ContainerBuilder())
        ->addService(NameFormatter::class, new NameFormatter())
        ->addService('request.callback.role', 'reader');
}

/** @param class-string $attribute */
function callbackDefinition(string $attribute, Container $container): AttributeDefinition
{
    $handler = $container->get(RequestAttributeHandler::class);
    if (!$handler instanceof RequestAttributeHandler) {
        throw new LogicException('Expected the configured request attribute handler.');
    }
    return new AttributeDefinition($attribute, $handler, [ValueProvider::class]);
}

/** @param array<string|int,mixed> $provided */
function resolveWithHandler(
    RequestAttributeHandler $handler,
    object $mapper,
    array $provided,
    ?ParameterAttributeValue $value = null,
): ParameterAttributeValue {
    $parameter = new ReflectionFunction(static fn(Profile $profile): Profile => $profile)->getParameters()[0];
    return $handler->resolveParameter(
        $mapper,
        new ParameterTarget($parameter),
        new ParameterResolutionContext($provided),
        new AttributePlan($parameter, []),
        $value ?? ParameterAttributeValue::unresolved(),
    );
}

beforeEach(function (): void {
    Calls::$callbacks = 0;
    Calls::$constructors = 0;
    Calls::$events = [];
});

test('one mapping callback builds readonly DTOs from separate sources and current DI dependencies', function (): void {
    $container = profileBuilder()->build();
    $payload = (new ServerRequest('POST', '/profiles'))->withParsedBody([
        'given' => ' Ada ', 'family' => ' Lovelace ', 'role' => 'administrator',
    ]);
    $query = (new ServerRequest('GET', '/profiles'))->withQueryParams([
        'given' => ' Grace ', 'family' => ' Hopper ', 'role' => 'administrator',
    ]);

    foreach ([false, true] as $familyFirst) {
        $container->set(NameFormatter::class, new NameFormatter($familyFirst));
        $created = $container->call(payloadProfile(...), [ServerRequestInterface::class => $payload]);
        $searched = $container->call(queryProfile(...), [ServerRequestInterface::class => $query]);
        if (!$created instanceof Profile || !$searched instanceof Profile) {
            throw new LogicException('Expected mapped readonly profiles.');
        }

        expect([$created->displayName, $created->role])
            ->toBe([$familyFirst ? 'Lovelace, Ada' : 'Ada Lovelace', 'reader'])
            ->and([$searched->displayName, $searched->role])
            ->toBe([$familyFirst ? 'Hopper, Grace' : 'Grace Hopper', 'reader'])
            ->and($created)->not->toBe($searched);
    }

    expect(Calls::$callbacks)->toBe(4)
        ->and(Calls::$constructors)->toBe(4)
        ->and($payload->getParsedBody())->toBe([
            'given' => ' Ada ', 'family' => ' Lovelace ', 'role' => 'administrator',
        ])
        ->and($query->getQueryParams())->toBe([
            'given' => ' Grace ', 'family' => ' Hopper ', 'role' => 'administrator',
        ]);
});

test('standalone mapping keeps all positional options without executing the configured callback', function (): void {
    $callbackCalls = 0;
    $mapper = new MapRequest(
        [RequestDataSource::Query],
        ['raw' => 'name'],
        ['role'],
        RequestDataConflictPolicy::FirstWins,
        ['mode' => 'default'],
        ['name' => 'trim'],
        ['newest' => ['createdAt' => 'DESC']],
        ['trace'],
        [],
        afterMap: static function (array $data) use (&$callbackCalls): array {
            ++$callbackCalls;
            return ['unexpected' => $data];
        },
    );
    $mapper->provider = new TestCasterProvider();
    $request = (new ServerRequest('GET', '/profiles'))
        ->withAttribute('trace', 'attribute trace')
        ->withQueryParams([
            'raw' => ' Ada ', 'sort' => 'newest', 'role' => 'administrator', 'trace' => 'query trace',
        ]);

    expect($mapper->transform($mapper->extract($request)))->toBe([
        'trace' => 'attribute trace',
        'name' => 'Ada',
        'mode' => 'default',
        'orderBy' => ['createdAt' => 'DESC'],
    ])->and($callbackCalls)->toBe(0);
});

/** @param array<string,string> $expected */
test('existing source attributes and an application afterMap property retain their behavior', function (
    Closure $target,
    array $expected,
): void {
    $container = profileBuilder()->build();
    $request = (new ServerRequest('POST', '/profiles'))
        ->withParsedBody(['raw' => 'payload'])
        ->withQueryParams(['raw' => 'query']);

    expect($container->call($target, [ServerRequestInterface::class => $request]))->toBe($expected)
        ->and(Calls::$callbacks)->toBe(0);
})->with([
    'query source' => [
        static fn(#[MapQueryString(map: ['raw' => 'value'])] array $data): array => $data,
        ['value' => 'query'],
    ],
    'payload source' => [
        static fn(#[MapRequestPayload(map: ['raw' => 'value'])] array $data): array => $data,
        ['value' => 'payload'],
    ],
    'external subclass without the callback protocol' => [
        static fn(#[LegacyQueryMapper(map: ['raw' => 'value'])] array $data): array => $data,
        ['value' => 'query', 'legacy' => 'application-owned setting'],
    ],
]);

test('old three-argument request handlers map legacy and null-callback attributes', function (object $mapper): void {
    $handler = new RequestAttributeHandler(profileBuilder()->build(), new TestCasterProvider(), new ObservedValidation());
    $request = (new ServerRequest('GET', '/profiles'))->withQueryParams(['given' => 'Ada']);

    $result = resolveWithHandler($handler, $mapper, [ServerRequestInterface::class => $request]);
    $profile = $result->value;
    if (!$profile instanceof Profile) {
        throw new LogicException('Expected a mapped profile through the original handler API.');
    }

    expect($result->resolved)->toBeTrue()
        ->and([$profile->displayName, $profile->role])->toBe(['Ada', 'reader'])
        ->and(Calls::$events)->toBe(['validation'])
        ->and(Calls::$callbacks)->toBe(0)
        ->and(Calls::$constructors)->toBe(1);
})->with([
    'legacy source' => [new MapQueryString(['given' => 'displayName'])],
    'explicit null callback' => [new MapRequest(
        sources: [RequestDataSource::Query],
        map: ['given' => 'displayName'],
        afterMap: null,
    )],
]);

test('an enabled callback without a binder fails before mapper validation and construction side effects', function (): void {
    $handler = new RequestAttributeHandler(profileBuilder()->build(), new TestCasterProvider(), new ObservedValidation());
    $request = (new ServerRequest('GET', '/profiles'))->withQueryParams(['given' => 'Ada', 'family' => 'Lovelace']);

    expect(fn() => resolveWithHandler($handler, new ObservableMapper(), [ServerRequestInterface::class => $request]))
        ->toThrow(InvalidConfigurationException::class, 'afterMap')
        ->and(Calls::$events)->toBe(['callback configuration'])
        ->and(Calls::$callbacks)->toBe(0)
        ->and(Calls::$constructors)->toBe(0);
});

test('an already resolved request value bypasses the callback getter and binder requirement', function (): void {
    $handler = new RequestAttributeHandler(profileBuilder()->build(), new TestCasterProvider(), new ObservedValidation());
    $value = ParameterAttributeValue::resolved(new Profile('existing', 'reader'));

    expect(resolveWithHandler($handler, new ObservableMapper(), [], $value))->toBe($value)
        ->and(Calls::$events)->toBe([])
        ->and(Calls::$callbacks)->toBe(0);
});

/** @param list<string> $events */
test('custom callback mappers use the registered handler with one configuration read per attempt', function (
    Closure $definition,
    Closure $target,
    array $events,
): void {
    $container = profileBuilder()
        ->addAttributeDefinition($definition)
        ->build();

    foreach ([['Ada', 'Lovelace'], ['Grace', 'Hopper']] as [$given, $family]) {
        $data = ['given' => $given, 'family' => $family];
        $request = (new ServerRequest('GET', '/profiles'))
            ->withQueryParams($data)
            ->withAttribute('profile-input', $data);
        $profile = $container->call($target, [ServerRequestInterface::class => $request]);
        if (!$profile instanceof Profile) {
            throw new LogicException('Expected the custom mapper to create a readonly profile.');
        }
        expect([$profile->displayName, $profile->role])->toBe([$given . ' ' . $family, 'reader']);
    }

    expect(Calls::$callbacks)->toBe(2)
        ->and(Calls::$constructors)->toBe(2)
        ->and(Calls::$events)->toBe([...$events, ...$events]);
})->with([
    'observable configuration getter' => [
        static fn(Container $container): AttributeDefinition => callbackDefinition(ObservableMapper::class, $container),
        static fn(#[ObservableMapper] Profile $profile): Profile => $profile,
        ['callback configuration', 'caster provider', 'extract', 'transform'],
    ],
    'independent readonly mapper with a stored property' => [
        static fn(Container $container): AttributeDefinition => callbackDefinition(ReadonlyMapper::class, $container),
        static fn(#[ReadonlyMapper] Profile $profile): Profile => $profile,
        [],
    ],
]);
