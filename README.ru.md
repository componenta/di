# Componenta DI

PSR-11 контейнер зависимостей для PHP 8.4+ с фабриками, автоматическим созданием объектов, атрибутами внедрения, вызовом PHP callable через DI, ленивыми объектами, виртуальными прокси и DI-планами.

**[Документация на английском](README.md)**

## Установка

```bash
composer require componenta/di
```

## Зависимости

Версия PHP:

| Требование | Версия |
|---|---|
| PHP | `^8.4` |

Пакеты:

| Пакет | Назначение |
|---|---|
| `psr/container` | Стандартный интерфейс контейнера: `get()` и `has()`. |
| `psr/http-message` | PSR-7 запросы для сопоставления HTTP-данных с параметрами и DTO. |
| `componenta/array` | Утилиты для работы с массивами внутри конфигурации и резолверов. |
| `componenta/caster` | Приведение значений для `#[Cast]` и HTTP-атрибутов с параметром `cast`. |
| `componenta/config` | `Config`, `Environment`, `ConfigProvider` и общая секция `dependencies`. |
| `componenta/priority-list` | Упорядочивание цепочек резолверов по приоритетам. |
| `componenta/reflection` | Кешированная работа с рефлексией и атрибутами. |
| `componenta/validation` | Валидация данных запроса перед созданием DTO. |
| `componenta/var-export` | Генерация исполняемого PHP-кеша зависимостей и DI-планов. |

## Интерфейсы контейнера

`Componenta\DI\Container` реализует несколько интерфейсов. Выбирайте тот, который соответствует нужной операции.

| Интерфейс | Методы | Когда использовать |
|---|---|---|
| `Psr\Container\ContainerInterface` | `get()`, `has()` | Получить сервис или значение по идентификатору. |
| `Componenta\DI\FactoryInterface` | `make()` | Создать новый объект через DI. Метод всегда создает новый объект и не берет результат из кеша контейнера. |
| `Componenta\DI\CallableInvokerInterface` | `call()` | Вызвать функцию, метод или контроллер с автоматическим разрешением параметров. |
| `Componenta\DI\CallableResolverInterface` | `resolve()` | Преобразовать описание вызываемого объекта в PHP callable. |
| `Componenta\DI\CallableExecutorInterface` | `resolve()`, `call()` | Преобразовать PHP callable и сразу выполнить его. |
| `Componenta\DI\LazyObjectFactoryInterface` | `makeLazy()` | Создать только ленивый объект PHP 8.4. |
| `Componenta\DI\VirtualProxyFactoryInterface` | `makeProxy()` | Создать только виртуальный прокси PHP 8.4. |
| `Componenta\DI\ProxyFactoryInterface` | `makeLazy()`, `makeProxy()` | Используйте, когда код выбирает между ленивым объектом и виртуальным прокси PHP 8.4. |
| `Componenta\DI\AliasResolverInterface` | `resolve()`, `set()`, `has()` | Разрешать и регистрировать псевдонимы сервисов. Доступен как сервис контейнера. |

Методы сборки контейнера:

| Метод | Назначение |
|---|---|
| `set(string $id, mixed $entry)` | Зарегистрировать значение или `DefinitionInterface` после создания контейнера. |
| `alias(string $alias, string $target)` | Добавить или заменить псевдоним. |
| <code>delegator(string $id, callable&#124;string&#124;array $delegator)</code> | Добавить декоратор результата `get($id)`. Делегатор получает текущее значение сервиса и контейнер, возвращает итоговое значение. |
| `addContainer(ContainerInterface $container)` | Подключить внешний PSR-11 контейнер как источник сервисов. |

Эти методы используются при сборке контейнера в стартовом коде, фабриках приложения или адаптерах.

## Быстрый старт

```php
use App\Logging\FileLogger;
use App\Logging\LoggerInterface;
use App\Service\UserService;
use Componenta\DI\ContainerBuilder;

$container = (new ContainerBuilder())
    ->addService(LoggerInterface::class, new FileLogger('/var/log/app.log'))
    ->addAlias('logger', LoggerInterface::class)
    ->build();

$logger = $container->get('logger');
$service = $container->make(UserService::class, ['userId' => 7]);
```

`get()` возвращает общий сервис или значение и кеширует результат. Поэтому сервисы, полученные через `get()`, по умолчанию ведут себя как синглтон внутри одного экземпляра контейнера: повторный `get($id)` вернет тот же объект, пока сервис не будет заменен через `set()`. `make()` всегда создает новый объект и не использует кеш общих сервисов.

## Получение и создание сервисов

`Container::get($id)` возвращает общий сервис или значение. При первом обращении контейнер разрешает псевдоним, проверяет внешние PSR-11 контейнеры, создает значение через резолверы, применяет делегаторы и кеширует итоговый результат.

```php
$logger = $container->get(LoggerInterface::class);
$sameLogger = $container->get(LoggerInterface::class);
```

`Container::make($entry, $params)` создает новый объект:

- учитывает псевдонимы;
- передает `$params` в контекст создания объекта;
- не читает и не заполняет кеш общих сервисов;
- не обращается к внешним контейнерам;
- не применяет делегаторы;
- требует, чтобы результат был объектом.

```php
$first = $container->make(ReportBuilder::class, ['limit' => 100]);
$second = $container->make(ReportBuilder::class, ['limit' => 100]);
```

Кратко:

| Метод | Что возвращает | Кеш | Делегаторы | Внешние контейнеры |
|---|---|---|---|---|
| `get($id)` | Общий сервис или значение по идентификатору. | Использует и заполняет кеш. | Применяет. | Проверяет. |
| `make($entry, $params)` | Всегда новый объект по классу или псевдониму. | Не использует. | Не применяет. | Не проверяет. |

Используйте `get()` для сервисов и значений, которые должны быть общими в рамках контейнера. Используйте `make()`, когда нужно создать новый объект с параметрами конкретного вызова. Метод всегда создает новый экземпляр и не берет результат из кеша.

### Делегаторы

Делегатор декорирует результат `get($id)`. Он получает уже созданный сервис и контейнер, возвращает новое значение, а это значение передается следующему делегатору.

```php
$container->delegator(
    LoggerInterface::class,
    static fn (LoggerInterface $logger, ContainerInterface $container): LoggerInterface => new MaskingLogger($logger),
);
```

Поведение:

| Правило | Что происходит |
|---|---|
| Выполняются только для `get()` | `make()` делегаторы не применяет. |
| Запускаются после получения базового значения | Если итоговое значение уже есть в кеше, `get()` вернет его сразу. Иначе контейнер получает базовое значение из кеша базовых сервисов, внешнего контейнера, фабрики или резолвера; потом применяет делегаторы. |
| Выполняются в порядке регистрации | Первый делегатор получает базовый сервис, второй получает результат первого и так далее. |
| Получают два аргумента | Первый аргумент - текущий сервис, второй - контейнер. |
| Кешируется итоговый результат | Повторный `get($id)` вернет уже декорированный сервис. |
| Добавление делегатора инвалидирует кеш | После `delegator($id, ...)` следующий `get($id)` пересоберет итоговое значение. |

Пример порядка выполнения:

```php
$container->set('counter', 1);

$container->delegator('counter', static fn (int $value): int => $value + 10);
$container->delegator('counter', static fn (int $value): int => $value * 2);

$container->get('counter'); // 22
```

Формы делегатора:

| Форма | Как обрабатывается |
|---|---|
| `Closure` | Используется напрямую. |
| Любой PHP callable | Используется напрямую. |
| Строковый идентификатор сервиса | Разрешается через `CallableResolverInterface`. |
| Массивный callable | Если это не готовый PHP callable, разрешается через `CallableResolverInterface`. |

В конфигурации `ConfigKey::DELEGATORS` массив под идентификатором сервиса считается списком делегаторов. Если сам делегатор задан массивным callable, например `[LoggerDelegator::class, 'decorate']`, заверните его во внешний список:

```php
ConfigKey::DELEGATORS => [
    \App\Logging\LoggerInterface::class => [[\App\Logging\LoggerDelegator::class, 'decorate']],
],
```

Делегатор регистрируется на тот идентификатор, с которым вызывается `get()`. Если сервис доступен и по основному идентификатору, и по псевдониму, регистрируйте делегатор на тот идентификатор, через который сервис будут получать.

```php
$container->alias('logger', LoggerInterface::class);

$container->delegator('logger', static fn (LoggerInterface $logger): LoggerInterface => new MaskingLogger($logger));

$container->get('logger'); // применит делегатор
$container->get(LoggerInterface::class); // не применит делегатор, если он зарегистрирован только на 'logger'
```

Исключения уровня контейнера, брошенные делегатором, проходят без обертки. Остальные ошибки оборачиваются в `DelegatorException`, чтобы было видно, какой идентификатор сломался при декорировании.

`Container::call($callable, $params)` вызывает функцию, метод, массивный callable, строку вида `Class::method` или вызываемый объект. Недостающие параметры разрешаются через цепочку резолверов параметров.

```php
$response = $container->call([PostController::class, 'show'], ['id' => $postId]);
```

Формы callable для `call()` и `CallableResolverInterface::resolve()`:

| Форма | Как разрешается |
|---|---|
| `Closure` или готовый PHP callable | Используется напрямую. |
| Имя функции | Используется как глобальная функция, если такая функция существует. |
| Строковый идентификатор сервиса | Контейнер получает сервис; сервис должен быть вызываемым объектом. |
| `Class::staticMethod` | Вызывается как статический метод. |
| `Class::method` | Контейнер получает объект класса и вызывает метод. |
| `[object, 'method']` | Метод вызывается у переданного объекта. |
| `[ClassName::class, 'method']` | Для статического метода вызывается класс напрямую; для обычного метода объект берется из контейнера. |

## Регистрация сервисов

`ContainerBuilder` настраивает контейнер до вызова `build()`.

```php
use Componenta\DI\ContainerBuilder;

$container = (new ContainerBuilder())
    ->addFactory(MailerInterface::class, static fn ($c, array $context) => new SmtpMailer(
        $c->get(SmtpConfig::class),
    ))
    ->addInvokable(HealthCheck::class)
    ->addAlias('mailer', MailerInterface::class)
    ->addDelegator(MailerInterface::class, static fn ($mailer) => new TraceableMailer($mailer))
    ->addService('app.name', 'Ophire')
    ->build();
```

| Метод | Что делает |
|---|---|
| `addFactory(string $id, callable $factory)` | Регистрирует фабрику сервиса. Фабрика получает `ContainerValue $container` и `array $context`. |
| `addFactories(array $factories)` | Массово регистрирует фабрики. |
| `addInvokable(string $classOrAlias, ?string $class = null)` | Регистрирует класс, создаваемый без обязательных аргументов конструктора. Если передан псевдоним, добавляет его для класса. |
| `addInvokables(array $invokables)` | Массово регистрирует простые классы без обязательных аргументов конструктора. |
| `addAutowire(string $class)` | Добавляет класс в список `autowires`, который попадает в экспортируемую конфигурацию. |
| `addAutowires(array $classes)` | Массово добавляет классы в список `autowires`. |
| `addAlias(string $alias, string $target)` | Добавляет псевдоним. |
| `addAliases(array $aliases)` | Массово добавляет псевдонимы. |
| <code>addDelegator(string $id, callable&#124;string&#124;array $delegator)</code> | Добавляет декоратор сервиса. |
| `addDelegators(array $delegators)` | Массово добавляет делегаторы. |
| `addService(string $id, mixed $service)` | Регистрирует готовое значение. |
| `addServices(array $services)` | Массово регистрирует готовые значения. |
| `compilePlans(iterable $classes, string $mode = PlanCompiler::MODE_SPARSE)` | Собирает DI-планы для известных классов. |
| `toArray()` | Возвращает нормализованную конфигурацию билдера. |

Фабрики вызываются с `Componenta\Config\ContainerValue`. Этот объект реализует `Psr\Container\ContainerInterface`, поэтому фабрики с типом `ContainerInterface` продолжают работать. Если фабрике нужен доступ к конфигурации приложения, typed lookup или optional fallback, типизируйте аргумент как `ContainerValue`.

```php
use App\Mail\MailerInterface;
use App\Mail\SmtpMailer;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerValue;
use Psr\Log\LoggerInterface;

use function Componenta\Config\entry;

$builder->addFactory(
    MailerInterface::class,
    static fn (ContainerValue $container, array $context): MailerInterface => new SmtpMailer(
        logger: $container->find('mail.logger', entry(LoggerInterface::class, LoggerInterface::class)),
        host: $container->config->string(new ConfigPath('mail.host'), 'localhost'),
    ),
);
```

`ContainerValue::get($id, Type::class)` проверяет тип найденного сервиса. `ContainerValue::find($id, $default)` возвращает запись из контейнера, если она есть, или резолвит explicit default: `entry(...)` из контейнера, `config_entry(...)` из `Config`, `lazy(...)` с текущим `ContainerValue`. Обычный callable default не выполняется и возвращается как значение.

Стандартный `ReflectionResolver` не ограничивается списком `autowires`: он может создать любой класс, который можно инстанцировать, если параметры конструктора можно разрешить. Список `autowires` остается частью конфигурации и кеша зависимостей.

### Дефиниции

Дефиниция описывает правило создания сервиса. Это не сам сервис, а объект-инструкция для контейнера: вызвать фабрику, создать конкретный класс, передать явные аргументы конструктора или взять другой сервис по ссылке.

Обычные методы билдера подходят для простых случаев:

```php
$builder
    ->addFactory(MailerInterface::class, static fn ($c, array $context) => new SmtpMailer())
    ->addInvokable(HealthCheck::class);
```

Дефиниция нужна, когда правило создания нужно передать в контейнер как объект: ее можно положить в `ConfigKey::FACTORIES` или передать в `Container::set()`. Для ссылок на другие сервисы внутри `ClassDefinition` используется отдельная дефиниция `Definition::reference()`.

```php
use Componenta\DI\Definition\Definition;

$container->set(
    ReportService::class,
    Definition::autowire(ReportService::class)
        ->constructor([
            'limit' => 100,
            'logger' => Definition::reference(LoggerInterface::class),
        ])
        ->method('boot'),
);
```

Фабричные методы `Definition`:

| Метод | Возвращает | Когда использовать |
|---|---|---|
| `Definition::factory(callable $factory)` | `FactoryDefinition` | Сервис полностью создается фабрикой. Фабрика получает `ContainerValue $container` и `array $context`. |
| `Definition::autowire(string $className)` | `ClassDefinition` | Нужно создать класс через `new` и явно указать аргументы конструктора или вызовы методов. |
| `Definition::reference(string $entryId)` | `ReferenceDefinition` | Нужно сослаться на другой сервис внутри `ClassDefinition`. |
| `Definition::invokable(string $className)` | `InvokableDefinition` | Нужно зарегистрировать простой класс, который создается без обязательных аргументов конструктора. |

`ClassDefinition` неизменяемый: методы настройки возвращают новый объект. Используйте возвращаемое значение или цепочку вызовов; вызов `constructor()` или `method()` без присваивания не меняет исходную дефиницию.

| Метод | Что делает |
|---|---|
| `ClassDefinition::create(string $className)` | Создает `ClassDefinition` напрямую, без фасада `Definition`. |
| `constructor(array $params)` | Возвращает новую дефиницию с заданными аргументами конструктора. Ключи массива сохраняются: строковые ключи работают как именованные аргументы, числовые как позиционные аргументы. |
| `method(string $method, array $params = [])` | Возвращает новую дефиницию с вызовом метода после создания объекта. Повторный вызов для того же имени метода заменяет параметры этого метода. Параметры передаются по тем же правилам, что и параметры конструктора. |

`ReferenceDefinition` разворачивается только внутри `ClassDefinition`: если параметр конструктора или метода содержит `Definition::reference(LoggerInterface::class)`, контейнер вызовет `get(LoggerInterface::class)` и передаст полученное значение. Стандартные резолверы не поддерживают `ReferenceDefinition` как самостоятельное описание сервиса.

Поддержка дефиниций зависит от резолвера:

| Дефиниция | Кто обрабатывает |
|---|---|
| `FactoryDefinition` | `FactoryResolver` |
| `ClassDefinition` | `FactoryResolver` |
| `InvokableDefinition` | `InvokableResolver` |
| `ReferenceDefinition` | Используется внутри `ClassDefinition` для параметров конструктора и методов. |

Несмотря на имя `Definition::autowire()`, `ClassDefinition` не запускает полную цепочку автоматического разрешения для своих аргументов. Он использует только значения, которые вы явно передали в `constructor()` и `method()`. Если нужен сервис из контейнера, используйте `Definition::reference()`.

Важно: фабрики и дефиниции не проходят через полную цепочку `ReflectionResolver`.

| Способ создания | Что выполняется |
|---|---|
| `ReflectionResolver` | Конструктор с DI-параметрами, `#[Inject]`, `#[Init]`, `#[SetUp]`, `#[NoConstructor]`, `#[Lazy]` и `#[Proxy]` на уровне класса. |
| `ConfigKey::FACTORIES`, `addFactory()`, `FactoryDefinition` | Только вызов фабрики. `#[Inject]`, `#[SetUp]`, `#[Lazy]` и `#[Proxy]` на уровне класса у возвращенного объекта не читаются. Фабрика сама отвечает за готовое состояние сервиса. |
| `ClassDefinition` | `new $class(...$constructorParams)` и явно указанные `method()` вызовы. Атрибуты объекта не обрабатываются. |
| `InvokableDefinition`, `addInvokable()` | Создание класса без обязательных аргументов конструктора. `#[Lazy]` и `#[Proxy]` на классе учитываются, но `#[Inject]` и `#[SetUp]` не выполняются. |

Если сервис создается фабрикой и ему нужны зависимости в свойствах, внедрите их внутри фабрики:

```php
$builder->addFactory(ReportService::class, static function (ContainerInterface $container): ReportService {
    return new ReportService(
        logger: $container->get(LoggerInterface::class),
    );
});
```

Также доступны статические фабрики:

| Метод | Когда использовать |
|---|---|
| `ContainerBuilder::configure(Config $config)` | Когда вся конфигурация уже собрана в `Componenta\Config\Config`. |
| `ContainerBuilder::configureWithDependencies(Config $config, array $dependencies)` | Когда секция `dependencies` уже загружена отдельно, например из кеша для боевого окружения. |
| `ContainerBuilder::configureFromCache(Config $config, array $cache, ?string $baseDir = null)` | Когда зависимости и DI-планы восстановлены из сгенерированного кеша. |

## Конфигурация

`Container::create()` и `ContainerBuilder::configure()` читают секцию `ConfigKey::DEPENDENCIES`.

Подробное описание загрузки конфигурации и провайдеров конфигурации находится в документации пакета [componenta/config](../config/README.ru.md#configprovider).

```php
use Componenta\Config\Config;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;

$config = new Config([
    ConfigKey::DEPENDENCIES => [
        ConfigKey::FACTORIES => [
            MailerInterface::class => static fn ($c, array $context) => new SmtpMailer(),
        ],
        ConfigKey::ALIASES => [
            'mailer' => MailerInterface::class,
        ],
    ],
]);

$container = Container::create($config);
```

Ключи секции `dependencies`:

| Ключ | Форма | Эффект |
|---|---|---|
| `ConfigKey::FACTORIES` | <code>array&lt;string, callable&#124;class-string&#124;array&#124;FactoryDefinition&#124;ClassDefinition&gt;</code> | Фабрики сервисов и дефиниции, которые обрабатывает `FactoryResolver`. |
| `ConfigKey::INVOKABLES` | `list<class-string>` или `array<string, class-string>` | Классы без обязательных аргументов. Ассоциативный ключ регистрирует псевдоним. |
| `ConfigKey::ALIASES` | `array<string, string>` | Псевдонимы сервисов. |
| `ConfigKey::AUTOWIRES` | `list<class-string>` | Список классов для экспортируемой конфигурации зависимостей. Стандартный `ReflectionResolver` не ограничивает автосоздание этим списком. |
| `ConfigKey::DELEGATORS` | <code>array&lt;string, callable&#124;string&#124;array&#124;list&lt;callable&#124;string&#124;array&gt;&gt;</code> | Один декоратор или список декораторов, применяемых после создания базового значения. |
| `ConfigKey::SERVICES` | `array<string, mixed>` | Уже созданные значения. |
| `ConfigKey::PARAMETER_RESOLVERS` | <code>array&lt;int, class-string&#124;callable&#124;ParameterResolverInterface&gt;</code> | Дополнительные резолверы параметров с приоритетами. |
| `ConfigKey::PROPERTY_RESOLVERS` | <code>array&lt;int, class-string&#124;callable&#124;PropertyResolverInterface&gt;</code> | Дополнительные резолверы свойств с приоритетами. |
| `ConfigKey::PARAMETER_RESOLVERS_REPLACE` | `bool` | Если `true`, стандартная цепочка параметров не устанавливается. |
| `ConfigKey::PROPERTY_RESOLVERS_REPLACE` | `bool` | Если `true`, стандартная цепочка свойств не устанавливается. |
| `ConfigKey::DI_PLANS_MODE` | `sparse` или `complete` | Режим сборки DI-планов для инструментов приложения. Контейнер во время выполнения сам планы не собирает. |

Для `ConfigKey::DELEGATORS` массив под идентификатором сервиса считается списком делегаторов. Поэтому массивный callable нужно передавать как элемент списка: `\App\Logging\LoggerInterface::class => [[\App\Logging\LoggerDelegator::class, 'decorate']]`.

Дополнительные ключи кеша DI-планов находятся в `Componenta\DI\Compile\PlanCompiler` и `Componenta\DI\Compile\PlanDispatcher`:

| Ключ | Назначение |
|---|---|
| `PlanCompiler::CONFIG_KEY` (`di_plans`) | Готовые планы параметров и свойств прямо в конфигурации. |
| `PlanCompiler::FILE_CONFIG_KEY` (`di_plans_file`) | Путь к файлу с DI-планами. |
| `PlanDispatcher::CONFIG_KEY` (`di_plan_dispatcher`) | Карта `kind -> resolver class` для быстрого выбора компилирующего резолвера. |

Формы фабрик в `ConfigKey::FACTORIES`:

| Форма | Как обрабатывается |
|---|---|
| PHP callable | Вызывается как фабрика и получает `ContainerValue $container` и `array $context`. |
| Строковый идентификатор сервиса | Контейнер получает сервис по этому идентификатору; результат должен быть вызываемым объектом. |
| Массив `[serviceId, method]` | Первый элемент разрешается через контейнер, затем вызывается указанный метод. |
| `FactoryDefinition` | Разворачивается в callable и получает `ContainerValue $container` и `array $context`. |
| `ClassDefinition` | Создает класс через `new`, подставляет явные параметры конструктора и выполняет явно указанные методы. |

## ConfigProvider

Базовый `ContainerBuilder` уже умеет создавать сервисы, вызывать методы, внедрять `#[Config]`, `#[Env]`, `#[EntryId]`, `#[Make]`, `#[Inject]`, `#[Init]`, значения по умолчанию и параметры с типом, допускающим `null`.

`Componenta\DI\ConfigProvider` расширяет стандартную цепочку резолверами для приведения типов, текущего пользователя и HTTP-данных:

| Интеграция | Что добавляется |
|---|---|
| `#[Cast]` | `CastableResolver`, использующий `componenta/caster`. |
| `#[CurrentUser]` | `CurrentUserResolver` и базовый `CurrentUserProviderInterface`. |
| Сопоставление HTTP-данных | `RequestResolver` для `#[QueryParam]`, `#[PayloadParam]`, `#[Header]`, `Map*` и других атрибутов запроса. |

Типичная цепочка провайдеров:

```php
return [
    new \Componenta\Caster\ConfigProvider(),
    new \Componenta\Validation\ConfigProvider(),
    new \Componenta\DI\ConfigProvider(),
];
```

Если эти резолверы не зарегистрированы через `Componenta\DI\ConfigProvider` или вручную, `#[Cast]`, `#[CurrentUser]` и HTTP-атрибуты не участвуют в разрешении параметров и свойств.

Провайдер регистрирует пустой `CurrentUserProviderInterface`. В приложении с аутентификацией замените его своей реализацией, которая возвращает текущего пользователя.

## Резолверы

Контейнер разделяет разрешение на три уровня: сервисы, параметры вызываемых объектов и свойства созданных объектов.

### Резолверы сервисов

Резолверы сервисов создают значение по идентификатору сервиса.

Минимальный интерфейс:

```php
use Componenta\DI\Resolver\Entry\EntryResolverInterface;

interface EntryResolverInterface
{
    public function can(string $id): bool;

    public function resolve(string $id, array $context = []): mixed;
}
```

`can($id)` отвечает только на вопрос, может ли резолвер обработать этот идентификатор. `resolve($id, $context)` возвращает готовое значение или бросает исключение. `$context` используется теми резолверами, которые создают объект через DI: например, `ReflectionResolver` передает эти значения в разрешение параметров конструктора и свойств. Фабрики из `ConfigKey::FACTORIES` получают контейнер и сами решают, какие значения использовать.

| Резолвер | Что делает |
|---|---|
| `FactoryResolver` | Создает сервисы из фабрик и `FactoryDefinition`. |
| `InvokableResolver` | Создает простые классы и `InvokableDefinition`. |
| `ReflectionResolver` | Создает объекты через рефлексию, вызывает конструктор, внедряет свойства и запускает `#[SetUp]`. |
| `CompositeResolver` | Обходит несколько резолверов сервисов и выбирает первый подходящий. |

Если резолвер поддерживает определения, он дополнительно реализует `DefinitionAwareResolverInterface`:

```php
use Componenta\DI\Definition\DefinitionInterface;

interface DefinitionAwareResolverInterface extends EntryResolverInterface
{
    public function setDefinition(string $id, DefinitionInterface $definition): void;

    public function supportsDefinition(DefinitionInterface $definition): bool;
}
```

`Container::set($id, DefinitionInterface $definition)` передает определение первому резолверу, который поддерживает этот тип определения. Резолверы с собственными правилами создания могут реализовать только `EntryResolverInterface`.

`Container::set()` также может принимать определения:

```php
use Componenta\DI\Definition\Definition;

$container->set(
    ReportService::class,
    Definition::autowire(ReportService::class)
        ->constructor(['limit' => 100])
        ->method('boot'),
);

$container->set('logger.file', Definition::factory(
    static fn ($c, array $context) => new FileLogger('/var/log/app.log'),
));
```

Подробное описание `FactoryDefinition`, `ClassDefinition`, `ReferenceDefinition` и `InvokableDefinition` находится в разделе “Дефиниции”.

### Резолверы параметров

Резолверы параметров используются в конструкторах, `make()` и `call()`.

Контракт параметрного резолвера:

```php
use ReflectionParameter;

interface ParameterResolverInterface
{
    /**
     * @param array<string|int, mixed> $providedParameters
     * @param array<int, mixed> $resolvedParameters
     * @return array{0: int, 1: mixed}|null
     */
    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array;
}
```

Параметры метода:

| Параметр | Что передается |
|---|---|
| `$parameter` | Рефлексия текущего параметра конструктора, метода или callable. |
| `$providedParameters` | Значения, которые явно передал вызывающий код в `make($class, $params)` или `call($callable, $params)`. |
| `$resolvedParameters` | Значения предыдущих параметров этого же вызова, уже найденные цепочкой резолверов. |

Возврат:

| Возврат | Значение |
|---|---|
| `[position, value]` | Резолвер нашел значение. `position` должен быть `$parameter->getPosition()`. |
| `null` | Резолвер не подходит для этого параметра, цепочка должна продолжить работу. |
| `ResolutionException` | Резолвер понял, что параметр относится к нему, но значение нельзя получить или оно не проходит тип. |

Стандартная цепочка билдера:

| Приоритет | Резолвер | Что делает |
|---:|---|---|
| `1100` | `Parameter\ArrayResolver` | Берет явно переданное значение из `$params`. |
| `1000` | `ArrayTypedResolver` | Берет явно переданное значение с учетом типа. |
| `700` | `MakeAttributeResolver` | Обрабатывает `#[Make]`. |
| `600` | `EnvResolver` | Обрабатывает `#[Env]`. |
| `500` | `EntryIdResolver` | Обрабатывает `#[EntryId]`. |
| `400` | `ConfigAttributeResolver` | Обрабатывает `#[Config]`. |
| `300` | `AutowireByTypeResolver` | Разрешает объектные типы из контейнера. |
| `200` | `DefaultValueResolver` | Использует значение по умолчанию параметра. |
| `100` | `NullableResolver` | Возвращает `null` для параметров с типом, допускающим `null`. |

`Componenta\DI\ConfigProvider` добавляет:

| Приоритет | Резолвер | Что делает |
|---:|---|---|
| `1200` | `CastableResolver` | Обрабатывает `#[Cast]`. |
| `900` | `CurrentUserResolver` | Обрабатывает `#[CurrentUser]`. |
| `800` | `RequestResolver` | Обрабатывает HTTP-атрибуты и DTO из PSR-7 запроса. |

Явно переданные параметры обрабатываются в начале стандартной цепочки. Их можно передавать по имени, позиции, имени типа или просто объектом подходящего типа. Если подключен `ConfigProvider` и параметр помечен `#[Cast]`, `CastableResolver` может взять явно переданное значение раньше стандартного `Parameter\ArrayResolver` и привести его к нужному виду.

```php
final readonly class CreateReportHandler
{
    public function __construct(
        private LoggerInterface $logger,
        private string $tenantId,
        private int $limit = 50,
    ) {}
}

$handler = $container->make(CreateReportHandler::class, [
    LoggerInterface::class => $logger,
    'tenantId' => 'main',
    'limit' => 100,
]);
```

В этом примере `ArrayTypedResolver` возьмет `$logger` по ключу `LoggerInterface::class`, `Parameter\ArrayResolver` возьмет `$tenantId` и `$limit` по имени, а остальные резолверы не будут вызваны для этих параметров.

### Резолверы свойств

Резолверы свойств запускаются после создания объекта и вызова конструктора. Они записывают только те свойства, для которых есть явное правило: значение в контексте или атрибут на свойстве. В цепочке свойств нет запасных резолверов для значения по умолчанию или `null`, потому что такая подстановка могла бы перезаписать значение, уже заданное в объявлении свойства или внутри конструктора.

Внедрение свойств не записывает статические свойства, свойства, объявленные прямо в параметрах конструктора, и уже инициализированные `readonly`-свойства.

Контракт резолвера свойств:

```php
use ReflectionProperty;

interface PropertyResolverInterface
{
    /**
     * @param array<string, mixed> $context
     * @return array{0: ReflectionProperty, 1: mixed}|null
     */
    public function resolveProperty(
        ReflectionProperty $property,
        array $context = [],
    ): ?array;
}
```

Параметры метода:

| Параметр | Что передается |
|---|---|
| `$property` | Рефлексия свойства, которое сейчас проверяет цепочка. |
| `$context` | Значения, переданные в создание объекта. Стандартный `Property\ArrayResolver` ищет значение по имени свойства. |

Возврат такой же по смыслу, как у параметров: `[property, value]` значит “значение найдено”, `null` значит “пропустить свойство и продолжить цепочку”. Если ни один резолвер не нашел значение для свойства, это не ошибка: свойство просто остается как есть.

Стандартная цепочка билдера:

| Приоритет | Резолвер | Что делает |
|---:|---|---|
| `800` | `Property\ArrayResolver` | Берет явно переданное значение из контекста. |
| `600` | `InitResolver` | Обрабатывает `#[Init]`. |
| `500` | `MakeAttributeResolver` | Обрабатывает `#[Make]`. |
| `400` | `EnvResolver` | Обрабатывает `#[Env]`. |
| `300` | `EntryIdResolver` | Обрабатывает `#[EntryId]`. |
| `200` | `InjectResolver` | Обрабатывает `#[Inject]`. |
| `100` | `ConfigAttributeResolver` | Обрабатывает `#[Config]`. |

`Componenta\DI\ConfigProvider` добавляет:

| Приоритет | Резолвер | Что делает |
|---:|---|---|
| `900` | `CastableResolver` | Обрабатывает `#[Cast]`. |
| `700` | `CurrentUserResolver` | Обрабатывает `#[CurrentUser]`. |

Пример контекста для свойств:

```php
final class ReportJob
{
    public string $tenantId;
}

$job = $container->make(ReportJob::class, [
    'tenantId' => 'main',
]);
```

После создания объекта `Property\ArrayResolver` получит контекст `['tenantId' => 'main']` и присвоит значение одноименному свойству.

### Свои резолверы

Свой резолвер параметров реализует `ParameterResolverInterface`. В примере ниже резолвер подставляет `tenantId` из отдельного контекста приложения. Если вызывающий код явно передал `tenantId` в `make()` или `call()`, стандартный `Parameter\ArrayResolver` сработает раньше и значение из `TenantContext` не понадобится.

```php
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use ReflectionNamedType;
use ReflectionParameter;

final class TenantResolver implements ParameterResolverInterface
{
    public function __construct(
        private TenantContext $tenant,
    ) {}

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $type = $parameter->getType();

        if ($parameter->getName() !== 'tenantId'
            || !$type instanceof ReflectionNamedType
            || $type->getName() !== 'string'
        ) {
            return null;
        }

        return [$parameter->getPosition(), $this->tenant->id()];
    }
}
```

Регистрация через конфиг:

```php
use Componenta\DI\ConfigKey;

return [
    ConfigKey::DEPENDENCIES => [
        ConfigKey::PARAMETER_RESOLVERS => [
            950 => TenantResolver::class,
        ],
    ],
];
```

В `ConfigKey::PARAMETER_RESOLVERS` и `ConfigKey::PROPERTY_RESOLVERS` можно передать готовый объект резолвера, замыкание, callable или строковый идентификатор сервиса. Если передана строка, контейнер получает резолвер через `get($id)`.

Свои резолверы свойств регистрируются через `ConfigKey::PROPERTY_RESOLVERS` и реализуют `PropertyResolverInterface`.

```php
use Componenta\DI\Resolver\Property\PropertyResolverInterface;
use App\Clock\ClockInterface;
use DateTimeImmutable;
use ReflectionNamedType;
use ReflectionProperty;

final class CreatedAtResolver implements PropertyResolverInterface
{
    public function __construct(
        private ClockInterface $clock,
    ) {}

    public function resolveProperty(ReflectionProperty $property, array $context = []): ?array
    {
        $type = $property->getType();

        if ($property->getName() !== 'createdAt'
            || !$type instanceof ReflectionNamedType
            || $type->getName() !== DateTimeImmutable::class
        ) {
            return null;
        }

        return [$property, $this->clock->now()];
    }
}
```

Регистрация:

```php
return [
    ConfigKey::DEPENDENCIES => [
        ConfigKey::PROPERTY_RESOLVERS => [
            750 => CreatedAtResolver::class,
        ],
    ],
];
```

Приоритет выбирается относительно стандартной цепочки. Например, `750` для свойства выполнится после `Property\ArrayResolver` с приоритетом `800`, но до атрибутных резолверов. Это сохраняет правило: явно переданный контекст выигрывает у автоматического значения.

Если нужен полный контроль над цепочкой, включите замену стандартных резолверов:

```php
return [
    ConfigKey::DEPENDENCIES => [
        ConfigKey::PARAMETER_RESOLVERS_REPLACE => true,
        ConfigKey::PARAMETER_RESOLVERS => [
            100 => TenantResolver::class,
        ],
    ],
];
```

Если резолвер должен участвовать в DI-планах, он дополнительно реализует один или несколько интерфейсов из `Componenta\DI\Compile`:

| Интерфейс | Когда нужен |
|---|---|
| `ParameterPlanResolverInterface` | Быстро разрешать параметр по заранее собранному плану. |
| `PropertyPlanResolverInterface` | Быстро разрешать свойство по заранее собранному плану. |
| `CompilesPlanPayloadInterface` | Сохранить неизменяемые данные резолвера в кеш плана, чтобы во время выполнения не перечитывать атрибуты. |
| `AttributeDrivenResolverInterface` | Пометить резолвер, который имеет смысл вызывать только для параметров или свойств с атрибутами. |

Для обычного пользовательского резолвера эти интерфейсы не обязательны. Начинайте с `ParameterResolverInterface` или `PropertyResolverInterface`; DI-план добавляйте только когда резолвер часто вызывается и его решение можно заранее вычислить по рефлексии.

## Атрибуты

Атрибуты сами не создают зависимости. Они только описывают, что должен сделать соответствующий резолвер.

### Внедрение и значения

| Атрибут | Где используется | Конструктор | Поведение |
|---|---|---|---|
| `#[Inject]` | свойство | без аргументов | Берет значение из контейнера по типу свойства. |
| `#[EntryId]` | параметр, свойство | `string $value` | Берет конкретный сервис из контейнера. |
| `#[Make]` | параметр, свойство | `?string $entry = null, array $params = []` | Создает новый объект через `FactoryInterface::make()` по классу или идентификатору сервиса. |
| `#[Config]` | параметр, свойство | <code>string&#124;Path&#124;null $path = null, mixed $default = DefaultValue::None</code> | Читает значение из `Componenta\Config\Config`. |
| `#[Env]` | параметр, свойство | `?string $name = null, mixed $default = DefaultValue::None` | Читает значение из `Environment`. Если имя не задано, имя цели переводится в `UPPER_SNAKE_CASE`. Значение приводится по объявленному типу цели. |
| `#[Cast]` | параметр, свойство | `string $name, mixed $default = DefaultValue::None` | Берет значение из переданных параметров или контекста по имени цели и преобразует его через `CasterProviderInterface`. |
| `#[CurrentUser]` | параметр, свойство | `?class-string $type = null` | Берет текущего пользователя из `CurrentUserProviderInterface`, проверяет тип из атрибута и объявленный тип цели. Для цели с типом, допускающим `null`, возвращает `null`, если пользователя нет. |
| `#[Init]` | свойство | `mixed $callable, array $params = []` | Вычисляет значение свойства вызовом вызываемого объекта. |

Для `#[Make]` идентификатор создаваемого объекта выбирается в таком порядке: явно переданный `entry`, затем класс или интерфейс из типа параметра/свойства, затем имя параметра или свойства. `params` передается в `FactoryInterface::make()` как параметры конкретного создания.

```php
use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Inject;

final class ReportService
{
    #[Inject]
    private LoggerInterface $logger;

    public function __construct(
        #[EntryId('mailer.transactional')]
        private MailerInterface $mailer,

        #[Config(new ConfigPath('reports.limit'), default: 100)]
        private int $limit,

        #[Env('REPORTS_QUEUE', default: 'default')]
        private string $queue,
    ) {}
}
```

`EntryId` работает как атрибут параметра или свойства:

```php
use Componenta\DI\Attribute\EntryId;

final readonly class UserData
{
    public function __construct(
        #[EntryId('user.name')]
        public string $name,
    ) {}
}

$container->set('user.name', 'Alice');

$user = $container->make(UserData::class);

// $user->name === 'Alice'
```

### Инициализация объекта

| Атрибут | Где используется | Конструктор | Поведение |
|---|---|---|---|
| `#[SetUp]` | класс, повторяемый | `string $method, array $params = []` | Вызывает метод после создания объекта и внедрения свойств. |
| `#[NoConstructor]` | класс | без аргументов | Создает объект через `ReflectionClass::newInstanceWithoutConstructor()`. Конструктор не вызывается; после создания стандартно выполняются внедрение свойств и `#[SetUp]`, если они настроены. |

Явные параметры `#[SetUp('method', params: [...])]` разворачиваются до вызова метода. Поддерживаются старые DI-метаданные `EntryId`, `Config`, `Env`, а также value objects из `componenta/config`: `ContainerEntry`, `ConfigEntry` и `LazyValue`. `ContainerEntry` получает сервис из контейнера, `ConfigEntry` читает значение из `Config`, `LazyValue` выполняется с текущим `ContainerValue`. Используйте `LazyValue` в программной конфигурации setup; closure нельзя напрямую передать в PHP-атрибут как аргумент.

```php
use Componenta\Config\ConfigEntry;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerEntry;
use Componenta\DI\Attribute\SetUp;
use Psr\Log\LoggerInterface;

#[SetUp('boot', params: [
    'logger' => new ContainerEntry(LoggerInterface::class, LoggerInterface::class),
    'name' => new ConfigEntry(new ConfigPath('app.name')),
])]
final class Worker
{
    public function boot(LoggerInterface $logger, string $name): void
    {
        $logger->info($name . ' worker booted');
    }
}
```

### Ленивые объекты и прокси

| Атрибут | Где используется | Поведение |
|---|---|---|
| `#[Lazy]` | класс | Создает ленивый объект самого класса через `ReflectionClass::newLazyGhost()`. `get_class()` и `instanceof` видят исходный класс. |
| `#[Proxy]` | класс, параметр, свойство | Создает виртуальный прокси через `ReflectionClass::newLazyProxy()`. `instanceof` работает, но `get_class()` вернет сгенерированный класс прокси. |

`#[Lazy]` и `#[Proxy]` на уровне класса учитываются для классов, которые создают `InvokableResolver` или `ReflectionResolver`. Эти механизмы завязаны на нативные ленивые объекты PHP 8.4: [`ReflectionClass::newLazyGhost()`](https://www.php.net/manual/en/reflectionclass.newlazyghost.php) и [`ReflectionClass::newLazyProxy()`](https://www.php.net/manual/en/reflectionclass.newlazyproxy.php). Общая страница PHP по этой теме: [Lazy Objects](https://www.php.net/manual/en/language.oop5.lazy-objects.php).

Виртуальный прокси создается для конкретного класса. Если параметр типизирован интерфейсом, укажите конкретный класс через `#[Make(ConcreteService::class)]` вместе с `#[Proxy]` или создайте прокси внутри фабрики, которая знает конкретный класс.

Фабрики из `ConfigKey::FACTORIES` сами управляют созданием сервиса: если фабрике нужна ленивость, она должна реализовать `LazyServiceFactoryInterface` или вернуть нужный прокси самостоятельно.

```php
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;

#[Lazy]
final class HeavyReportBuilder {}

final class ReportController
{
    public function __construct(
        #[Proxy]
        private HeavyReportBuilder $builder,
    ) {}
}
```

`LazyServiceFactoryInterface` нужен для фабрик, которые сами управляют ленивым созданием сервиса через нативные ленивые объекты и виртуальные прокси PHP. Это отдельный механизм для `ConfigKey::FACTORIES`: `FactoryResolver` не читает `#[Lazy]` и `#[Proxy]` у класса, который вернула фабрика. Если зарегистрированная фабрика реализует `LazyServiceFactoryInterface`, контейнер вызывает `lazy($container, $proxyFactory)`. Обычный `__invoke()` в этом случае не вызывается.

Интерфейс:

```php
use Componenta\DI\ProxyFactoryInterface;
use Psr\Container\ContainerInterface;

interface LazyServiceFactoryInterface
{
    public function lazy(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        array $context = [],
    ): object;
}
```

Когда использовать:

| Ситуация | Почему нужен интерфейс |
|---|---|
| Сервис создается фабрикой, а не `ReflectionResolver` или `InvokableResolver`. | `#[Lazy]` и `#[Proxy]` на классе для фабрик не применяются. |
| Сервис зарегистрирован под интерфейсом, но фабрика знает конкретный класс. | Прокси создается для конкретного класса, а наружу может быть возвращен как интерфейс сервиса. |
| Нужен выбор между `makeLazy()` и `makeProxy()`. | `ProxyFactoryInterface` дает обе операции, а фабрика выбирает подходящий способ. |

Пример с виртуальным прокси для сервиса, который создается через сторонний провайдер:

```php
use Componenta\DI\LazyServiceFactoryInterface;
use Componenta\DI\ProxyFactoryInterface;
use Psr\Container\ContainerInterface;

final class DatabaseFactory implements LazyServiceFactoryInterface
{
    public function __invoke(ContainerInterface $container): DatabaseConnection
    {
        return $container->get(DatabaseProviderInterface::class)->connection();
    }

    public function lazy(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        array $context = [],
    ): object {
        return $proxyFactory->makeProxy(
            DatabaseConnection::class,
            fn (object $proxy): DatabaseConnection => $this->__invoke($container),
        );
    }
}
```

Регистрация:

```php
return [
    ConfigKey::DEPENDENCIES => [
        ConfigKey::FACTORIES => [
            DatabaseInterface::class => DatabaseFactory::class,
        ],
    ],
];
```

Что произойдет при `get(DatabaseInterface::class)`:

1. `FactoryResolver` получит `DatabaseFactory` из контейнера.
2. Так как фабрика реализует `LazyServiceFactoryInterface`, будет вызван `lazy()`.
3. `lazy()` вернет виртуальный прокси.
4. Реальный `DatabaseConnection`, зарегистрированный как `DatabaseInterface`, будет создан только при первом обращении к прокси.

Если фабрика возвращает известный конкретный класс, можно использовать ленивый объект:

```php
final class ReportBuilderFactory implements LazyServiceFactoryInterface
{
    public function __invoke(ContainerInterface $container): ReportBuilder
    {
        return new ReportBuilder(
            $container->get(ConnectionInterface::class),
        );
    }

    public function lazy(
        ContainerInterface $container,
        ProxyFactoryInterface $proxyFactory,
        array $context = [],
    ): object {
        return $proxyFactory->makeLazy(
            ReportBuilder::class,
            function (ReportBuilder $builder) use ($container): void {
                $builder->__construct(
                    $container->get(ConnectionInterface::class),
                );
            },
        );
    }
}
```

Для простых классов без фабрики обычно достаточно `#[Lazy]` на классе. `LazyServiceFactoryInterface` нужен именно тогда, когда создание сервиса уже вынесено в фабрику и ленивую оболочку должна собрать сама фабрика.

### HTTP-атрибуты

HTTP-атрибуты работают, когда в цепочке параметров есть `RequestResolver`. Обычно его добавляет `Componenta\DI\ConfigProvider`.

| Атрибут | Где берет данные | Конструктор |
|---|---|---|
| `#[QueryParam]` | `$request->getQueryParams()` | `?string $name = null, mixed $default = DefaultValue::None, ?string $cast = null` |
| `#[PayloadParam]` | разобранное тело запроса | <code>string&#124;Path&#124;null $name = null, mixed $default = DefaultValue::None, ?string $cast = null</code> |
| `#[Header]` | заголовки | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` |
| `#[Cookie]` | куки | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` |
| `#[ServerParam]` | параметры сервера | `string $name, mixed $default = DefaultValue::None, ?string $cast = null` |
| `#[RequestAttribute]` | атрибуты запроса | `?string $name = null, mixed $default = DefaultValue::None, ?string $cast = null` |
| `#[UploadedFile]` | загруженные файлы | `string $name` |

Атрибуты `Map*` описывают, из какого источника собрать массив данных:

| Атрибут | Источник |
|---|---|
| `#[MapQueryString]` | параметры строки запроса |
| `#[MapRequestPayload]` | разобранное тело запроса |
| `#[MapHeaders]` | заголовки, значения одного заголовка объединяются через `, ` |
| `#[MapCookies]` | куки |
| `#[MapServerParams]` | параметры сервера |
| `#[MapRequestAttributes]` | атрибуты запроса |
| `#[MapUploadedFiles]` | загруженные файлы |

Все `Map*` атрибуты наследуют `RequestMapper` и принимают `array $map = []`. Карта задает соответствие `ключ во входных данных -> имя параметра DTO`. Например, `['q' => 'search']` возьмет поле `q`, создаст поле `search` и удалит исходное `q`.

Преобразование выполняется в таком порядке:

| Шаг | Что делает |
|---|---|
| `map` | Переименовывает ключи. Обязательный источник без префикса должен быть во входных данных. |
| `cast` | Приводит уже переименованные поля через `componenta/caster`. Запускается только для существующих ключей. |
| `defaults` | Добавляет значения для отсутствующих ключей. Значения должны быть уже в финальном типе. |
| `sortMap` | Превращает `sort` в `orderBy`, а `sort` и `order` удаляет из результата. |
| `exclude` | Удаляет перечисленные поля из финального массива. |

Если источник в `map` начинается с `?`, поле считается опциональным:

```php
#[MapQueryString([
    'q' => 'search',
    '?page' => 'page',
])]
array $filters
```

Для строки запроса `?q=php` результат после шага `map` будет:

```php
[
    'search' => 'php',
]
```

Для строки запроса `?q=php&page=2` результат будет:

```php
[
    'page' => '2',
    'search' => 'php',
]
```

Если обязательного `q` нет, сопоставление бросит `InvalidArgumentException` с сообщением `Required key "q" is missing`.

Дополнительные правила задаются в наследнике через защищенные свойства `cast`, `defaults`, `sortMap` и `exclude`. Через свойства `attributes` и `files` наследник может дополнительно включить атрибуты запроса и загруженные файлы в исходный массив. Значение `['*']` означает “взять все”. Если карта задается свойством наследника, объявляйте ее как `protected(set) array $map`, потому что так свойство определено в базовом `RequestMapper`.

```php
use Componenta\DI\Attribute\MapQueryString;

final class MapPostListQuery extends MapQueryString
{
    protected(set) array $map = [
        'q' => 'search',
        '?page' => 'page',
    ];

    protected array $cast = [
        'page' => 'int',
        'limit' => 'int',
        'offset' => 'int',
    ];

    protected array $defaults = [
        'limit' => 20,
        'offset' => 0,
    ];

    protected array $sortMap = [
        'newest' => ['createdAt' => 'desc'],
        'popular' => ['views' => 'desc'],
    ];

    protected array $exclude = ['debug'];
}
```

Для строки запроса:

```text
?q=php&page=2&limit=10&sort=newest&order=asc&debug=1
```

исходный массив из `MapQueryString`:

```php
[
    'q' => 'php',
    'page' => '2',
    'limit' => '10',
    'sort' => 'newest',
    'order' => 'asc',
    'debug' => '1',
]
```

после `map`:

```php
[
    'page' => '2',
    'limit' => '10',
    'sort' => 'newest',
    'order' => 'asc',
    'debug' => '1',
    'search' => 'php',
]
```

после `cast` и `defaults`:

```php
[
    'page' => 2,
    'limit' => 10,
    'sort' => 'newest',
    'order' => 'asc',
    'debug' => '1',
    'search' => 'php',
    'offset' => 0,
]
```

после `sortMap` и `exclude` финальный результат:

```php
[
    'page' => 2,
    'limit' => 10,
    'search' => 'php',
    'offset' => 0,
    'orderBy' => ['createdAt' => 'desc'],
]
```

Если параметр контроллера имеет тип `array`, `RequestResolver` вернет этот массив:

```php
public function index(
    #[MapPostListQuery]
    array $filters,
): ResponseInterface {
    // $filters === [
    //     'page' => 2,
    //     'limit' => 10,
    //     'search' => 'php',
    //     'offset' => 0,
    //     'orderBy' => ['createdAt' => 'desc'],
    // ]
}
```

Если параметр типизирован DTO, контейнер создаст DTO через `FactoryInterface::make()` и передаст финальный массив как параметры конструктора:

```php
final readonly class PostListQuery
{
    public function __construct(
        public ?string $search,
        public int $page,
        public int $limit,
        public int $offset,
        public ?array $orderBy,
    ) {}
}

public function index(
    #[MapPostListQuery]
    PostListQuery $query,
): ResponseInterface {
    // $query->search === 'php'
    // $query->page === 2
    // $query->limit === 10
    // $query->offset === 0
    // $query->orderBy === ['createdAt' => 'desc']
}
```

Если подключен `componenta/validation` и для DTO есть правила, `RequestResolver` сначала валидирует сырые данные из запроса, затем запускает преобразование и только после этого создает DTO.

Пример включения атрибутов запроса и файлов:

```php
use Componenta\DI\Attribute\MapRequestPayload;

final class MapUploadPayload extends MapRequestPayload
{
    protected array $attributes = ['postId'];
    protected array $files = ['cover'];

    protected(set) array $map = [
        'postId' => 'postId',
        'title' => 'title',
        'cover' => 'cover',
    ];
}
```

Если тело запроса содержит `['title' => 'Cover']`, атрибут запроса `postId` равен `42`, а загруженный файл доступен под ключом `cover`, финальный массив будет:

```php
[
    'postId' => 42,
    'title' => 'Cover',
    'cover' => $uploadedFile,
]
```

## DTO из PSR-7 запроса

PSR-7 запрос передается в `call()` или `make()` через `RequestParameter::with()`.

```php
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Psr\Http\Message\ResponseInterface;

final readonly class CreatePostInput
{
    public function __construct(
        public string $title,
        public string $content,
    ) {}
}

final class PostController
{
    public function create(
        #[MapRequestPayload]
        CreatePostInput $input,

        #[QueryParam('preview', default: false, cast: 'bool')]
        bool $preview,
    ): ResponseInterface {
        // ...
    }
}

$response = $container->call(
    [PostController::class, 'create'],
    RequestParameter::with([], $request),
);
```

Если для DTO есть правила `componenta/validation`, `RequestResolver` валидирует сырые данные запроса до преобразования и до создания DTO. Ошибки валидации приходят из пакета `componenta/validation`.

## Кеш и DI-планы

Контейнер использует два уровня кеша:

| Кеш | Что хранит |
|---|---|
| Базовый кеш | Значения до делегаторов. |
| Итоговый кеш | Значения после делегаторов. |

`get()` использует кеш. `make()` кеш не использует и не сохраняет результат.

DI-планы нужны, чтобы заранее разобрать атрибуты параметров и свойств известных классов. Это уменьшает объем рефлексии во время выполнения. Планы собирает `PlanCompiler`, а подключает `ContainerBuilder` через секцию `dependencies`.

Режимы:

| Режим | Поведение |
|---|---|
| `PlanCompiler::MODE_SPARSE` (`sparse`) | Сохраняет каждый параметр или свойство, для которого найден компилируемый резолвер. |
| `PlanCompiler::MODE_COMPLETE` (`complete`) | Сохраняет план метода только когда найден резолвер для каждого параметра этого метода. Свойства компилируются по тем же правилам, что и в `sparse`. |

`DiCacheGeneratorInterface::generate(array $config, string $path)` записывает массив конфигурации зависимостей в PHP-файл, который возвращает тот же массив. Стандартный `DiCacheGenerator` создает промежуточные директории, пишет файл атомарно через временный файл и инвалидирует OPcache для целевого пути.

Готовые DI-планы обычно собираются на уровне приложения и передаются контейнеру через `PlanCompiler::CONFIG_KEY` или `PlanCompiler::FILE_CONFIG_KEY`. В `componenta/app` описаны обнаружение классов, режимы кеша и подключение сгенерированных файлов в точках входа.

## Ошибки

| Исключение | Когда возникает |
|---|---|
| `NotFoundException` | Сервис не найден и ни один резолвер сервисов не может его создать. |
| `CircularDependencyException` | Обнаружен цикл зависимостей. |
| `ResolutionException` | Ошибка создания объекта, параметра, свойства или некорректный результат `make()`. |
| `InvalidConfigurationException` | Некорректная конфигурация или неподдерживаемая дефиниция. |
| `InvalidCallableException` | Невозможно нормализовать или вызвать переданный callable. |
| `DelegatorException` | Делегатор сервиса завершился ошибкой. |

`has($id)` возвращает `false` только для ошибок уровня контейнера. Ошибки программирования внутри резолверов не маскируются.

## Рекомендации

- В сервисы передавайте не конкретный `Container`, а интерфейс под нужную операцию: `ContainerInterface` для `get()`, `FactoryInterface` для `make()`, `CallableInvokerInterface` для `call()`.
- Методы `set()`, `alias()`, `delegator()` и `addContainer()` держите в стартовом коде, фабриках приложения или адаптерах.
- Для сложных объектов предпочитайте явные фабрики. Автоматическое создание через рефлексию хорошо подходит для простых сервисов с понятными зависимостями.
- HTTP-атрибуты держите на DTO, контроллерах и обработчиках входного слоя. Доменный код остается независимым от PSR-7 запроса.
- `make()` используйте, когда нужен новый объект при каждом вызове. `get()` используйте для общих сервисов.

## Связанные пакеты

| Пакет | Что читать |
|---|---|
| [`componenta/config`](https://github.com/componenta/config/blob/main/README.ru.md) | Загрузка конфигурации, `ConfigProvider`, окружение и пути конфигурации. |
| [`componenta/caster`](https://github.com/componenta/caster/blob/main/README.ru.md) | Именованные преобразователи значений для `#[Cast]` и HTTP-атрибутов с параметром `cast`. |
| [`componenta/validation`](https://github.com/componenta/validation/blob/main/README.ru.md) | Правила валидации DTO, которые `RequestResolver` может проверить перед созданием объекта. |
| [`componenta/app-http`](https://github.com/componenta/app-http/blob/main/README.ru.md) | HTTP-слой приложения, который обычно передаёт PSR-7 запросы в контейнер через `RequestParameter::with()`. |
| [`componenta/app`](https://github.com/componenta/app/blob/main/README.ru.md) | Создание контейнера приложения, обнаружение классов, кеш конфигурации и подключение DI-планов в точках входа. |
