# DI 6 migration: callbacks and union autowiring

[Русский](../ru/migrations/di6-callbacks.md)

The API described here is **unreleased**. DI 6 requires PHP 8.4+; native closure expressions written directly as PHP attribute arguments require PHP 8.5.

## Transform

`#[Transform]` is a repeatable parameter/property attribute accepting one `Closure`. It transforms an already supplied value; transformations run in declaration order.

```php
use Componenta\DI\Attribute\Transform;

// Requires PHP 8.5 for these attribute expressions.
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

The first callback parameter receives the value; any remaining parameters are resolved by DI. An unresolved required parameter still needs a value source. The first callback parameter cannot be by-reference, variadic or have DI source attributes.

## SetUp and Init

`#[SetUp('initialize')]` continues to call a public instance method. In DI 6, `SetUp::$method` also accepts a `Closure` with the created object as its first argument and DI-resolved parameters after it. Setup runs after property injection and cannot replace the instance with its return value.

```php
use Componenta\DI\Attribute\SetUp;

// Requires PHP 8.5 for the inline static closure.
#[SetUp(method: static function (Worker $instance, Formatter $formatter): void {
    $instance->label = $formatter->format('ready');
})]
final class Worker
{
    public string $label;
}
```

Existing `Init` string, array and invokable callables remain valid. Subclasses redeclaring `SetUp::$method` as `string` must accommodate the `string|Closure` type.

## Union autowiring

For non-variadic simple unions, one available class/interface service is selected. If none exists, normal default/null rules apply. If multiple services are available, DI throws `ResolutionException`, even when aliases lead to the same instance. Explicit arguments or `#[EntryId]` disambiguate them. Intersections and DNF intersection arms are not implicitly selected.

## Optional request callback

`MapRequest` adds a final optional `?Closure $afterMap = null` argument. The callback receives the entire mapped array and returns an array before DTO creation; its additional parameters are resolved by DI. Existing source-specific mappers, custom `transform()` overrides, and other mapping approaches remain supported.

```php
use Componenta\DI\Attribute\MapRequest;

// Requires PHP 8.5 for this reference in an attribute.
function normalizeInput(array $data): array
{
    return ['name' => trim((string) ($data['name'] ?? ''))];
}

$handler = static fn(
    #[MapRequest(afterMap: normalizeInput(...))] CreateInput $input
): CreateInput => $input;
```

Native attribute expressions accept capture-free static closures and first-class references to declared functions/static methods, not arrow functions, captured variables or object-method references. Some older PHP 8.5 patch versions have native trait-callable scope issues; the branch's targeted platform checks cover PHP 8.5.13.

See [Attributes and request mapping](../guide/attributes.md).
