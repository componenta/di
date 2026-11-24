# Исключения и сведения об ошибках

Классы исключений находятся в пространстве имён `Componenta\DI\Exception`. `ExceptionInterface` расширяет `Throwable` и `ContainerExceptionInterface` стандарта PSR-11. При преобразовании чужого исключения исходная причина доступна через `getPrevious()`. После начала выполнения явно вызванной функции её собственные исключения передаются без изменения.

| Тип | Причина и открытые данные |
| --- | --- |
| `ExceptionInterface` | Общий интерфейс исключений библиотеки. |
| `NotFoundException` | Отсутствие запрошенной записи; свойство `$id`, метод `forService(string $id, ?Throwable $previous = null)`. Реализует `NotFoundExceptionInterface` стандарта PSR-11. |
| `ResolutionException` | Ошибка создания службы, разрешения параметра или свойства; методы `forService()`, `forMissingService()`, `forNonObject()`, `forParameter()`, `forProperty()`. |
| `CircularDependencyException` | Цикл между службами или псевдонимами; свойство `$chain`, методы `forService(array $chain)`, `forAlias(array $chain)`. |
| `ConcurrentResolutionException` | Несовместимое одновременное создание общего значения; `forService(string $id)`. |
| `InvalidConfigurationException` | Недопустимое определение или конфигурация; `forSelfReferencingAlias()`, `forInvalidDefinition()`, `forUnsupportedDefinition()`. |
| `AttributeCompositionException` | Несовместимое сочетание атрибутов; наследует `InvalidConfigurationException`. |
| `DelegatorException` | Ошибка обработчика результата; свойство `$entryId`, метод `forEntry(string $id, Throwable $previous)`. |
| `InvalidCallableException` | Неправильное описание функции или метода; свойства `$callableType`, `$callableDescription`; методы `forValue()`, `forMethod()`, `forNonInvokable()`, `forMissingService()`. |
| `MutationRetirementException` | Изменение уже применено, но освобождение заменённых значений не удалось; свойства `$id`, `$causes`. Наследует `InvalidConfigurationException`. |
| `RequestDataConflictException` | Разные значения одного поля из различных источников запроса; `$key`, `$existingSource`, `$incomingSource`. |
| `RequestParameterSourceConflictException` | Поле HTTP-запроса конфликтует с явно указанным источником параметра объекта; `$dtoClass`, `$key`, `$source`, `$parameter`. |
| `ParameterDiagnostic` | Неизменяемые данные о параметре: `$name`, `$position`, `$type`, `$context`. |
| `PropertyDiagnostic` | Неизменяемые данные о свойстве: `$name`, `$class`, `$type`. |

## Данные ResolutionException

Конструктор принимает сообщение `$message`, необязательные `ParameterDiagnostic $parameter`, `PropertyDiagnostic $property` и исходное исключение `$previous`. Кроме того, доступны скопированные сведения: `parameterName`, `parameterPosition`, `parameterType`, `parameterContext`, `propertyName`, `propertyClass`, `propertyType`, `serviceId`, `providedParameterTypes`, `resolvedParameterTypes`, `actualType`. Для их хранения не требуется сохранять исходный запрос или создаваемый объект.

```php
use Componenta\DI\Exception\{MutationRetirementException, ResolutionException};

try {
    $container->make(Service::class);
} catch (ResolutionException $error) {
    // Проверьте $error->serviceId, $error->parameter либо getPrevious().
}

try {
    $container->set('service', new Service());
} catch (MutationRetirementException $error) {
    // Изменение применено; изучите $error->id и $error->causes.
}
```

`MutationRetirementException` следует перехватывать раньше общего `InvalidConfigurationException`. Ошибка проверки до применения изменений сохраняет предыдущую привязку. Независимо завершённые вложенные изменения не отменяются.

См. [Поведение контейнера](../guide/runtime.md) и [Обработка HTTP-запросов](../guide/request-mapping.md).
