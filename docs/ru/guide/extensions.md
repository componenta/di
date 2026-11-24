# Расширение контейнера

Пользовательские резолверы и обработчики атрибутов регистрируются через провайдеры конфигурации **до** вызова `ContainerFactory::create()`. После сборки цепочки резолверов параметров и атрибутов не изменяются.

## Резолверы параметров

Реализуйте `ParameterResolverInterface`:

- `supports(ParameterTarget $target): bool` определяет применимость резолвера.
- `resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): ?array` возвращает `[position, value]` либо `null`, чтобы передать обработку следующему резолверу.

Для собственного атрибута, который только поставляет значение, можно использовать `AttributeMatchingParameterResolver`:

```php
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class TenantId {}

$resolver = new AttributeMatchingParameterResolver(
    TenantId::class,
    static fn($target, $context): array => [$target->position, 'tenant-1'],
);
```

Зарегистрируйте `$resolver` с целочисленным приоритетом через `getParameterResolvers()` провайдера. Метод `supports()` не должен изменять цепочку резолверов.

## Обработчики атрибутов

Семантика пользовательского атрибута регистрируется через `AttributeDefinition`. Capabilities, селекторы `requires`, `forbids`, `before` и `after` определяют совместимость и порядок исполнения. `CapabilityPolicy` ограничивает число атрибутов с определённой capability на одной цели.

Необходимые определения атрибутов передаются до сборки контейнера. Фабрики расширений могут разрешать зависимости через контейнер, но вновь обнаруженное применимое расширение после завершённой операции может быть отклонено, чтобы не менять её семантику задним числом.

## Определения и внешние контейнеры

Стратегии явных определений реализуют `DefinitionResolverInterface`, fallback-резолверы — `EntryResolverInterface` с `EntryParameters`. Перед обновлением реализаций см. [миграцию DI 6](../migrations/di6-definitions.md).

`Container::addContainer()` добавляет внешний PSR-11 контейнер. Защищённые записи ядра DI нельзя переопределить; внешний провайдер сам управляет сроком жизни возвращаемых значений. См. [Поведение](runtime.md).
