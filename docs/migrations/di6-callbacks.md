# Migrating callbacks and union autowiring from version 5

[Русская версия](../ru/migrations/di6-callbacks.md)

Version 6 requires PHP 8.5.13 or later within PHP 8.x. Native static closures and first-class callable expressions may be written directly in attribute arguments. Runtime callables supported by earlier versions remain available.

## Value transformation with Transform

`#[Transform]` is a repeatable parameter/property attribute whose constructor accepts exactly one `Closure`.

```php
use Componenta\DI\Attribute\Transform;

// Native callback expressions in attributes require PHP 8.5.
final class EmailInput
{
    public function __construct(
        #[Transform(trim(...))]
        #[Transform(static function (string $value): string {
            return strtolower($value);
        })]
        public string $email,
    ) {}
}

$input = $container->make(EmailInput::class, ['email' => ' Alice@Example.COM ']);
// $input->email === 'alice@example.com'
```

`Transform` runs after a value source has supplied its value. It does not implicitly obtain the transformed parameter from the container. Repeated transformations run in declaration order; `#[Cast]` and `#[Transform]` follow their relative declaration order unless composition rules change it.

The first callback parameter receives the current value, including `null` or a whole array. Remaining parameters are resolved through DI. The first parameter cannot be by-reference, variadic or decorated with registered DI parameter attributes. An unresolved required parameter still needs a value source; a default or nullable declaration can supply a value according to normal rules.

## SetUp callbacks and Init compatibility

`#[SetUp('initialize')]` continues to call a public instance method. `SetUp::$method` also accepts `Closure`; its first parameter receives the constructed object, and subsequent parameters may be resolved through DI or supplied with `params:`.

```php
use Componenta\DI\Attribute\SetUp;

// This attribute expression requires PHP 8.5.
#[SetUp(method: static function (Worker $instance, Formatter $formatter): void {
    $instance->label = $formatter->format('ready');
})]
final class Worker
{
    public string $label;
}
```

Setup callbacks run after property injection. Their return value is ignored; they cannot replace the constructed object. Repeated hooks preserve declaration order. Subclasses redeclaring `SetUp::$method` with type `string` must accommodate `string|Closure`.

`#[Init]` still accepts the existing string, array and invokable callable forms. It retains DI-aware invocation through the standard container and supports explicit `params`. Native callback expressions are an additional way to declare the callable.

## Union-type autowiring

For a non-variadic simple union of named types, only class/interface arms can provide automatically resolved services:

| Available class/interface service IDs | Behavior |
| --- | --- |
| None | Use normal default, nullable or missing-argument handling. |
| Exactly one | Retrieve that service and verify the full declared type. |
| More than one | Throw `ResolutionException` for ambiguity. |

Two aliases resolving to the same object remain two available identifiers. The resolver does not instantiate candidates to compare identity. Disambiguate with an explicit argument or a source attribute such as `#[EntryId('primary.clock')]`. Intersections and unions containing intersection arms are not implicitly discovered.

## Callback after request mapping

`MapRequest` adds an optional last constructor argument, `?Closure $afterMap = null`. The callback receives the entire mapped array as its first parameter; remaining parameters can be resolved by DI. It must return an array before DTO construction. Raw validation precedes the callback, and subsequent source-policy checks remain in effect.

```php
use Componenta\DI\Attribute\MapRequest;

// The callable reference inside the attribute requires PHP 8.5.
function normalizeInput(array $data): array
{
    return ['name' => trim((string) ($data['name'] ?? ''))];
}

$handler = static fn(
    #[MapRequest(afterMap: normalizeInput(...))] CreateInput $input
): CreateInput => $input;
```

Existing `MapRequest` arguments, source-specific mapping attributes, custom `transform()` implementations and manual application adapters remain supported.

## PHP expression restrictions

PHP 8.5 constant expressions in attributes accept capture-free static closures and first-class references to declared functions or static methods. Arrow functions, closure variable capture, dynamic callable expressions and object-method references cannot be used in this syntax. These restrictions do not remove ordinary runtime callables.

The minimum PHP version is 8.5.13 because earlier PHP 8.5 releases can incorrectly resolve scope and visibility for first-class callable references in trait attributes. The package preserves PHP's native callable scope.

See [Attributes and request mapping](../guide/attributes.md) for request-source configuration.
