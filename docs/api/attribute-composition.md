# Attribute composition API

The extension types in this section belong to `Componenta\DI\Attribute\Composition`, except for `AttributePhase` and `AttributeProcessor` in `Componenta\DI\Resolver\Attribute`. They are intended for custom attribute handlers and composition policies.

## Definition and registration

| Type | Public methods and properties | Role |
| --- | --- | --- |
| `AttributeDefinition` | Constructor below; readable `attribute`, `handler`, `capabilities`, `requires`, `forbids`, `before`, `after`, `rules`, `phase` | Immutable semantic description of one attribute class and its handler. |
| `AttributeDefinitionRegistry` | `__construct(bool $targetedDiscovery = false)`, `register(AttributeDefinition $definition): void`, `defineCapability(CapabilityPolicy $policy): void`, `seal(): void`, `definition(string $attribute): ?AttributeDefinition`, `policy(string $capability): CapabilityPolicy`, `definitions(): array`, `policies(): array` | Collect and then seal attribute semantics. |
| `CapabilityPolicy` | `__construct(string $capability, ?int $maxPerTarget = null)` | Limit how many attributes share one capability on a target; `null` means no limit. |
| `AttributeCapabilityInterface` | Marker, no methods | Type for a semantic capability. |
| `AttributeCompositionRuleInterface` | `validate(AttributeUsage $attribute, AttributeSet $set): void` | Check a custom composition rule and throw on an invalid combination. |

```php
new AttributeDefinition(
    attribute: TenantId::class,
    handler: $handler,
    capabilities: [ValueProvider::class],
    requires: [],
    forbids: [],
    before: [],
    after: [],
    rules: [],
    phase: AttributePhase::AfterInstantiation,
);
```

The `handler` implements `AttributeHandlerInterface` for object targets, `ParameterAttributeHandlerInterface` for parameter targets, or both. Selectors in `requires`, `forbids`, `before` and `after` may name attribute classes or capability interfaces. The definition snapshots its arrays; handler and rule objects keep their identity.

`AttributePhase` is an integer-backed enum with `BeforeInstantiation = 100`, `AfterInstantiation = 200`, `Both = 300`. A parameter handler has a separate parameter-execution contract and is not interchangeable with an object lifecycle handler.

## Plans and target views

| Type | Public methods and properties | Role |
| --- | --- | --- |
| `AttributePlanBuilder` | `__construct(AttributeDefinitionRegistry $registry)`, `build(Closure\|ReflectionClass\|ReflectionMethod\|ReflectionParameter\|ReflectionProperty $target): AttributePlan` | Compose declarations for a target. |
| `AttributePlan` | `__construct($target, array $usages)`, `all(string $capability): array`, `one(string $capability): ?AttributeUsage`, `has(string $capability): bool`, `attributes(string $attributeClass): array`; `$usages`, `$target` | Retrieve composed attributes by capability or class in plan order. |
| `AttributeSet` | `__construct(array $attributes)`, `all(string $selector): array`, `one(string $selector): ?AttributeUsage`, `has(string $selector): bool`; `$attributes` | Read-only composition-rule view. |
| `AttributeUsage` | `__construct(ReflectionAttribute $reflection, AttributeDefinition $definition, $target, int $declarationOrder)`, `newInstance(): object`, `is(string $attribute): bool`, `hasCapability(string $capability): bool`, `matches(string $selector): bool`; `$reflection`, `$attributeClass`, `$target`, `$arguments`, `$definition`, `$declarationOrder` | One declared attribute and its semantic metadata. |
| `AttributeProcessor` | `__construct(AttributePlanBuilder $plans)`, `prepare(ReflectionClass $class): void`, `hasHandlers(ReflectionClass $class): bool` | Prepare and inspect object attribute handling. |

The `AttributeUsage::$arguments` getter evaluates declaration arguments when accessed; object-valued expressions need not be stable across reads. `newInstance()` creates an attribute instance for the current execution. Composition rules receive metadata rather than a cached mutable runtime attribute.

## Built-in capabilities and rule

These public marker interfaces live under `Attribute\Composition\Capability`:

| Interface | Meaning |
| --- | --- |
| `ValueProvider` | Provides a parameter/property value. |
| `AuthoritativeValueProvider` | A source that reserves its target's value. |
| `InvocationOnlyValueProvider` | A source valid only during callable invocation. |
| `ValueTransformer` | Transforms an already provided value. |
| `ConstructorPolicy` | Changes constructor invocation policy. |
| `CreationStrategy` | Selects an object creation strategy. |
| `LifecycleHook` | Supplies object initialization work. |

`Attribute\Composition\Rule\ProxyCompositionRule` is a built-in `AttributeCompositionRuleInterface` with `validate(AttributeUsage $attribute, AttributeSet $set): void` that checks proxy composition requirements.

## Extension timing

Register definitions through `ConfigProvider::getAttributeDefinitions()` and capability policies through `getAttributeCapabilities()` before container assembly. Completed object/parameter phases must not be silently replayed when newly loaded attribute classes become available; incompatible late discovery can raise `AttributeCompositionException`.

See [Extension contracts](../guide/extensions.md), [All built-in attributes](../guide/attribute-reference.md), and [Composition hooks](attribute-handlers.md).
