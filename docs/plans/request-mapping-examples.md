# Request mapping: existing and callback alternatives

[Русская версия](request-mapping-examples.ru.md) · [Normative contract](php85-callbacks.md#11-request-mapping-and-deferred-work)

**Status, 2026-11-17:** `MapRequest(afterMap: ...)` and `CallbackMapperInterface` are implemented on the DI 6 development branch. This guide keeps the existing solutions and adds the callback alternative. Both are supported; existing applications do not need to migrate. The examples were executed against the implementation on PHP 8.4.27 and 8.5.12.

The useful addition is a DI-aware transformation of the whole mapped array before automatic DTO creation. It saves application adapter code. It does not add raw-input repair, a validation framework or business transactions.

| Task | Existing approach, still supported | Additional callback option |
|---|---|---|
| Rename/default/exclude individual fields | Declarative `MapRequest` options | Keep the same options. |
| Combine fields without DI | Override a source mapper's `transform(array): array`. | Optional local callback; no new fundamental capability. |
| Combine fields with DI while preserving automatic DTO validation and source checks | Application parameter handler + runtime mapper delegating to the built-in handler | `MapRequest(afterMap: ...)` uses the existing callback binder. |
| Return a transformed array | `Map*` + `Transform` | Already supported; `afterMap` is unnecessary for this alone. |
| Reuse the transformation across endpoints/sources | Reuse the application mapper/handler. | Reuse one named callback; change `sources`. |

## 1. One comparable domain example

The input contains `given`, `family` and an untrusted `role`. The DTO receives the computed display name and a role from a registered service. `NameFormatter` is the replaceable formatting policy. These declarations are shared by the existing and callback examples.

```php
<?php

declare(strict_types=1);

namespace Example\Profiles;

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\RequestDataSource;

require __DIR__ . '/vendor/autoload.php';

final readonly class NameFormatter
{
    public function __construct(private bool $familyFirst = false) {}

    public function format(string $given, string $family): string
    {
        $given = trim($given);
        $family = trim($family);
        return $this->familyFirst ? "$family, $given" : "$given $family";
    }
}

final readonly class Profile
{
    public function __construct(
        public string $displayName,
        #[EntryId('profile.role')] public string $role,
    ) {}
}

final class ProfileInput
{
    /** @param array<string|int,mixed> $data @return array{displayName:string} */
    public static function normalize(array $data, NameFormatter $formatter): array
    {
        $given = $data['given'] ?? null;
        $family = $data['family'] ?? null;
        if (!is_string($given) || !is_string($family)) {
            throw new \InvalidArgumentException('Expected given/family strings.');
        }
        return ['displayName' => $formatter->format($given, $family)];
    }
}
```

The callback returns a new array containing only `displayName`; it does not mutate the readonly DTO or copy the input `role` into constructor arguments. The role service here represents an application-owned value; the DI container does not perform authorization for the application.

Use this public bootstrap for the following examples. `Nyholm\Psr7\ServerRequest` is only a request fixture; production code can pass its existing PSR-7 request. Put the snippets in the same namespace/file after the shared declarations. Run the existing and callback alternatives separately. The direct native attribute expression in §4 requires PHP 8.5; the constant-based alternative works on PHP 8.4.

```php
use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

/** @param list<mixed> $definitions @param array<string,mixed> $services */
function exampleContainer(array $definitions = [], array $services = []): Container
{
    $value = (new ContainerFactory())->create(
        new Config([], new Environment([])),
        new DependencyDefinitions([
            ConfigKey::SERVICES => $services + [
                NameFormatter::class => new NameFormatter(),
                'profile.role' => 'reader',
            ],
            ConfigKey::ATTRIBUTE_DEFINITIONS => $definitions,
        ]),
    );
    if (!$value->container instanceof Container) {
        throw new \LogicException('Expected the standard container.');
    }
    return $value->container;
}
```

## 2. Simple mapping: no callback is needed

This example works now and should remain unchanged. It maps an existing display name and removes an untrusted field before DTO source checks.

```php
function simpleProfile(
    #[MapRequest(map: ['full_name' => 'displayName'], exclude: ['role'])]
    Profile $profile,
): Profile {
    return $profile;
}

$container = exampleContainer();
$request = (new ServerRequest('POST', '/profiles'))->withParsedBody([
    'full_name' => 'Ada Lovelace',
    'role' => 'administrator',
]);
$result = $container->call(simpleProfile(...), [ServerRequestInterface::class => $request]);
// Profile(displayName: 'Ada Lovelace', role: 'reader')
```

For pure multi-field logic without DI, an existing `MapRequestPayload` subclass can override `transform()`, call `parent::transform($data)` and return the new array. No callback feature is needed to construct a readonly DTO from that result.

## 3. Existing typed DTO + DI path, still supported

A correct current solution can retain automatic DTO validation and source checks. The application service uses ordinary constructor injection. The adapter creates it inside `transform()`, which the built-in request handler reaches only after raw validation. `make()` intentionally creates a fresh mapping service here; this also makes the direct formatter replacement in the comparison observable.

```php
use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

final readonly class ProfileInputMapping
{
    public function __construct(private NameFormatter $formatter) {}

    public function map(array $data): array
    {
        return ProfileInput::normalize($data, $this->formatter);
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class MapProfile implements ParameterSourceAttributeInterface {}

// Per-attempt runtime adapter; not a cached native attribute instance.
final class ProfileRuntimeMapper extends MapRequestPayload
{
    public function __construct(private readonly FactoryInterface $factory)
    {
        parent::__construct();
    }

    public function transform(array $data): array
    {
        $mapped = parent::transform($data);
        $mapper = $this->factory->make(ProfileInputMapping::class);
        return $mapper->map($mapped);
    }
}

final readonly class MapProfileHandler implements ParameterAttributeHandlerInterface
{
    public function __construct(
        private RequestAttributeHandler $requests,
        private FactoryInterface $factory,
    ) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if ($value->resolved) {
            return $value;
        }
        return $this->requests->resolveParameter(
            new ProfileRuntimeMapper($this->factory),
            $target,
            $context,
            $plan,
            $value,
        );
    }
}

function currentDefinition(Container $container): AttributeDefinition
{
    $handler = $container->get(RequestAttributeHandler::class);
    if (!$handler instanceof RequestAttributeHandler) {
        throw new \LogicException('Expected the configured request attribute handler.');
    }
    return new AttributeDefinition(
        MapProfile::class,
        new MapProfileHandler($handler, $container),
        capabilities: [ValueProvider::class],
    );
}

function createProfileCurrent(#[MapProfile] Profile $profile): Profile
{
    return $profile;
}
```

The registration reuses the handler already configured by `ContainerFactory`. The application does not assemble internal binders or manually duplicate casting/validation providers.

```php
$container = exampleContainer(definitions: [currentDefinition(...)]);
$request = (new ServerRequest('POST', '/profiles'))->withParsedBody([
    'given' => ' Ada ', 'family' => ' Lovelace ', 'role' => 'administrator',
]);
$first = $container->call(createProfileCurrent(...), [ServerRequestInterface::class => $request]);
$container->set(NameFormatter::class, new NameFormatter(familyFirst: true));
$second = $container->call(createProfileCurrent(...), [ServerRequestInterface::class => $request]);
// $first:  Profile(displayName: 'Ada Lovelace', role: 'reader')
// $second: Profile(displayName: 'Lovelace, Ada', role: 'reader')
```

This is the equivalent current route: typed readonly DTO, dependency injection, raw-validation ordering and final source checks. `Map*` plus `Transform` on an **array** is shorter when an array is the desired result. Manually constructing a DTO afterward requires explicit application validation/source policy; array mode does not silently inherit the automatic DTO guarantees.

## 4. Additional callback path: the same normalizer, fewer adapters

The following PHP 8.4-compatible form defines a closure constant at application bootstrap, before attribute materialization. The constant is application-owned and intentionally retains its closure; avoid request captures and mutable request state in reusable callbacks.

```php
define(__NAMESPACE__ . '\PROFILE_INPUT', ProfileInput::normalize(...));

function createProfileAfter(
    #[MapRequest(afterMap: PROFILE_INPUT)] Profile $profile,
): Profile {
    return $profile;
}

function queryProfileAfter(
    #[MapRequest(sources: [RequestDataSource::Query], afterMap: PROFILE_INPUT)]
    Profile $profile,
): Profile {
    return $profile;
}
```

`ProfileInput`, `Profile` and `NameFormatter` remain the shared declarations from §1. The `ProfileInputMapping` wrapper, `MapProfile`, runtime mapper, parameter handler and custom definition registration from §3 are no longer needed for this case. One callback works for different sources and different input values:

```php
$container = exampleContainer();
$payload = (new ServerRequest('POST', '/profiles'))->withParsedBody([
    'given' => ' Ada ', 'family' => ' Lovelace ', 'role' => 'administrator',
]);
$query = (new ServerRequest('GET', '/profiles'))->withQueryParams([
    'given' => ' Grace ', 'family' => ' Hopper ', 'role' => 'administrator',
]);
$first = $container->call(createProfileAfter(...), [ServerRequestInterface::class => $payload]);
$search = $container->call(queryProfileAfter(...), [ServerRequestInterface::class => $query]);
$container->set(NameFormatter::class, new NameFormatter(familyFirst: true));
$second = $container->call(createProfileAfter(...), [ServerRequestInterface::class => $payload]);
$searchAgain = $container->call(queryProfileAfter(...), [ServerRequestInterface::class => $query]);
// $first:       Profile('Ada Lovelace', 'reader')
// $search:      Profile('Grace Hopper', 'reader')
// $second:      Profile('Lovelace, Ada', 'reader')
// $searchAgain: Profile('Hopper, Grace', 'reader')
```

On PHP 8.5 the same public static method reference can be written directly in the attribute. This syntax is a PHP 8.5 requirement, not a change to DI's PHP `^8.4` package floor. A direct inline closure must use the permitted `static function` form; a direct arrow function in an attribute is not a supported substitute. See [PHP's new features](https://www.php.net/manual/en/migration85.new-features.php) and the [constant-expression RFC](https://wiki.php.net/rfc/closures_in_const_expr).

```php
function createProfileNative(
    #[MapRequest(afterMap: ProfileInput::normalize(...))] Profile $profile,
): Profile {
    return $profile;
}
```

### Scaling this pattern

A named callback can delegate to a larger application normalizer through an ordinary DI suffix. That service can compose name/address/email normalizers and own their order and intermediate schemas. Keeping this composition in application code avoids adding callback-list ordering, partial-stage failures and retry semantics to the container API.

The replacement example above changes the **direct suffix service** `NameFormatter`. It does not promise recursive rebuilding: an already-shared `ProfileNormalizer` that captured a formatter in its constructor keeps that collaborator when only the formatter binding changes. Select the normalizer's lifetime deliberately, replace that service itself, or inject the changing policy directly. Reuse of a named function/static method also shares its native static state; a fresh closure wrapper does not reset method statics.

## 5. Optional callback for a custom source and readonly attribute

An independent mapper can opt in without inheriting mutable `RequestMapper`. Here middleware supplies `profile-input` as a request attribute. The property is a stored readonly property, satisfying the interface's get-only contract without a readonly property hook.

```php
use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Parameter\Request\CallbackMapperInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestDataExtractorInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class MapProfileAttribute implements RequestDataExtractorInterface, CallbackMapperInterface
{
    public ?Closure $afterMap;

    public function __construct()
    {
        $this->afterMap = ProfileInput::normalize(...);
    }

    public function extract(ServerRequestInterface $request): array
    {
        $data = $request->getAttribute('profile-input');
        if (!is_array($data)) {
            throw new \InvalidArgumentException('profile-input must be an array.');
        }
        return $data;
    }

    public function transform(array $data): array
    {
        return $data;
    }
}

function customDefinition(Container $container): AttributeDefinition
{
    $handler = $container->get(RequestAttributeHandler::class);
    if (!$handler instanceof RequestAttributeHandler) {
        throw new \LogicException('Expected the configured request attribute handler.');
    }
    return new AttributeDefinition(
        MapProfileAttribute::class,
        $handler,
        capabilities: [ValueProvider::class],
    );
}

function customProfile(#[MapProfileAttribute] Profile $profile): Profile
{
    return $profile;
}

$container = exampleContainer(definitions: [customDefinition(...)]);
$request = (new ServerRequest('POST', '/profiles'))->withAttribute('profile-input', [
    'given' => ' Ada ', 'family' => ' Lovelace ', 'role' => 'administrator',
]);
$result = $container->call(customProfile(...), [ServerRequestInterface::class => $request]);
// Profile(displayName: 'Ada Lovelace', role: 'reader')
```

Implementing the interface does **not** register the attribute. The existing explicit definition factory is required. This example reuses the built-in handler and its configured binder; it needs neither automatic interface scanning nor a second request-handler registry. A non-PSR-7 message/event should use its own application boundary, rather than expand this HTTP mapping feature into a universal pipeline.

## 6. Validation, source integrity and lifecycle

Raw DTO validation remains before mapper transformation and before `afterMap`. For example, a raw validator requiring `given` and `family` rejects an empty `given` before the normalizer runs. The resulting schema only contains `displayName`; that raw validator is not rerun on it. Computed-value invariants belong in the DTO/value object or an explicit application validator.

This validator can be registered through the same bootstrap for either the existing adapter or the callback alternative:

```php
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\Validator;
use Componenta\Validation\ValidatorInterface;

$validation = new class implements ValidationProviderInterface {
    public function provide(string $entryId): ?ValidatorInterface
    {
        return $entryId === Profile::class
            ? new Validator(['given' => new Required(), 'family' => new Required()])
            : null;
    }
};
$container = exampleContainer(services: [ValidationProviderInterface::class => $validation]);
// Current adapter: also pass definitions: [currentDefinition(...)].
// Valid raw input: ['given' => 'Ada', 'family' => 'Lovelace'].
// Invalid raw input: ['given' => '', 'family' => 'Lovelace'].
```

| Concrete case | Required result and reason |
|---|---|
| Input includes `role: administrator`, normalizer returns only `displayName` | DTO receives service role `reader`. Removed raw names do not become final constructor inputs. |
| Normalizer returns `displayName` **and** `role: administrator` | `RequestParameterSourceConflictException` before the initial factory call for this declared DTO: `role` has an explicit `EntryId` source. |
| A callback adds a field previously removed by `exclude` | The added field participates in final checks. `exclude` is not rerun as a post-callback allowlist. |
| Input contains a `formatter` field | It cannot replace the `NameFormatter` DI suffix. The suffix receives an empty provided-parameter bag. |
| A custom suffix resolver reads `resolved[0]` | It can see the semantic mapped array. The empty bag is not a sandbox hiding data from trusted extensions. |
| Callback returns an integer, DTO, `Traversable`, `null` or `void` | Array-result failure before the DTO factory; the callback is an input transformation, not a DTO factory. |
| A known declared constructor-source conflict exists | Reject before that initial DTO factory call. Late actual-class/setup/nested conflicts reject before the affected parameter consumes data; earlier effects are not rolled back. |

`Transform` after DTO mapping receives the object returned by the factory, not the constructor-input array. With readonly properties it cannot act as an input rewrite. For lazy objects/proxies, the object may not have run its constructor yet:

| Target and stage | Observable order |
|---|---|
| Current eager DTO with outer `Transform` | Raw validation → mapper → constructor/property initialization → outer `Transform(object)`. |
| Current lazy/proxy DTO with outer `Transform` | Raw validation → mapper → deferred object → outer `Transform(object)`; constructor/property initialization later. |
| Eager DTO with `afterMap` | Raw validation → mapper → `afterMap(array)` → source checks → constructor/property initialization. |
| Lazy/proxy DTO with `afterMap` | Raw validation → mapper → `afterMap(array)` → source checks → deferred object; initialization later, without replaying `afterMap`. |

The additional stage copies top-level array slots only when enabled. That prevents writes through top-level PHP reference cells; it does not deep-clone nested references or objects. Callback side effects are not rolled back. A failed new mapping attempt can be retried; a later lazy-initialization retry must not replay the already-completed mapping callback.

## 7. Verification and compatibility

Both the existing examples and the callback alternatives were executed against the production implementation, with source-origin checks. They cover the same payload/query callback with different input values, direct dependency replacement, readonly custom mapper registration, raw-validation failure/retry and final-source conflicts. Direct attribute method-reference syntax was checked on PHP 8.5; runtime-constant forms were checked on both PHP versions.

The regression suites cover [public API and compatibility](../../tests/Contract/RequestMappingCallbackContractTest.php), [data/source/ABI boundaries](../../tests/Contract/RequestMappingCallbackBoundariesContractTest.php), and [lifecycle/recovery/lifetime](../../tests/Contract/RequestMappingCallbackLifecycleContractTest.php). Existing request mapping and `Transform` suites are retained. The [normative acceptance](php85-callbacks.md#proof-and-implementation-sequence) remains the contract for subsequent changes.

The existing [runtime benchmark](../../benchmarks/RuntimeBench.php) has matched request-array cases with and without callbacks at 1, 16, 256 and 4096 keys, plus a direct DI suffix and DTO cases. Use `DI_BENCH_CASES` to select `request/array-16,request/callback-16,request/suffix,request/dto,request/dtoCallback`; `DI_BUILD_ITERATIONS` controls request-case iterations. Set `COMPONENTA_DI_BENCH_AUTOLOAD` and `COMPONENTA_DI_BENCH_SOURCE` as for the other benchmark cases. This measures local operation cost, not application throughput. The enabled path adds a linear shallow copy and ordinary callback/DI resolution; the disabled path does neither callback reflection nor that copy.

`afterMap` is useful for recurring **multi-field normalization with DI before automatic DTO creation**. Simple declarative mapping, custom `transform()` implementations, array plus `Transform`, and the application adapter in §3 remain valid alternatives. No path in this guide is deprecated by the new option.
