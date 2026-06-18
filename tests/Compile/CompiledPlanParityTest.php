<?php

declare(strict_types=1);

require_once __DIR__ . '/../Fixture/container_helpers.php';

use Componenta\Caster\ConfigProvider as CasterConfigProvider;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\CQRS\Query\Context\Context;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Compile\PlanCompiler;
use Componenta\DI\Compile\PlanDispatcher;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Exception\CircularDependencyException;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Componenta\DI\Tests\Fixture\FakeUser;
use Componenta\DI\Tests\Fixture\RequestDtoTarget;
use Componenta\DI\Tests\Fixture\ServiceWithParam;
use Componenta\DI\Tests\Fixture\SimpleService;

use function Componenta\Config\config_merge;

final class DiCompiledParityConsumer
{
    public function __construct(
        public SimpleService $simple,
        #[EntryId('factory.value')]
        public ServiceWithParam $service,
    ) {}
}

final class DiCompiledParityExplicitParams
{
    public function __construct(
        public string $first,
        public string $second,
        public ServiceWithParam $service,
    ) {}
}

final class DiCompiledParityAttributeTarget
{
    public function __construct(
        #[ConfigAttribute('app.name')]
        public string $appName,
        #[\Componenta\DI\Attribute\Env('PARITY_ENV')]
        public string $envValue,
        #[Make(ServiceWithParam::class, params: ['value' => 'made'])]
        public ServiceWithParam $made,
        #[EntryId('entry.service')]
        public ServiceWithParam $entry,
        #[Cast('int')]
        public int $count,
        #[CurrentUser]
        public FakeUser $user,
    ) {}
}

final class DiCompiledParityPropertyTarget
{
    #[ConfigAttribute('app.name')]
    public string $appName;

    #[\Componenta\DI\Attribute\Env('PARITY_ENV')]
    public string $envValue;

    #[Make(ServiceWithParam::class, params: ['value' => 'made-property'])]
    public ServiceWithParam $made;

    #[EntryId('entry.service')]
    public ServiceWithParam $entry;

    #[Cast('int')]
    public int $count;

    #[CurrentUser]
    public FakeUser $user;
}

final class DiCompiledParityRequestHandler
{
    public function handle(
        #[MapQueryString]
        RequestDtoTarget $query,
        #[QueryParam('page', cast: 'int')]
        int $page,
    ): array {
        return ['q' => $query->q, 'page' => $page];
    }
}

#[Lazy]
final class DiCompiledParityLazyService
{
    public static int $constructed = 0;

    public string $value;

    public function __construct()
    {
        self::$constructed++;
        $this->value = 'lazy';
    }
}

#[Proxy]
final class DiCompiledParityProxyService
{
    public static int $constructed = 0;

    public function __construct()
    {
        self::$constructed++;
    }

    public function value(): string
    {
        return 'proxy';
    }
}

final class DiCompiledParityCycleA
{
    public function __construct(public DiCompiledParityCycleB $b) {}
}

final class DiCompiledParityCycleB
{
    public function __construct(public DiCompiledParityCycleA $a) {}
}

/**
 * @param list<class-string> $classes
 * @param array<string, mixed> $dependencies
 * @param array<string, mixed> $config
 * @param array<string, string> $env
 * @return array{0: Container, 1: Container}
 */
function diCompiledParityContainers(
    array $classes,
    array $dependencies = [],
    array $config = [],
    array $env = [],
): array {
    $base = config_merge((new CasterConfigProvider())(), (new \Componenta\DI\ConfigProvider())());
    $data = config_merge($base, [
        ConfigKey::DEPENDENCIES => $dependencies,
        ...$config,
    ]);

    $runtimeConfig = new Config($data, new Environment($env));
    $runtimeBuilder = ContainerBuilder::configure($runtimeConfig);
    $plans = $runtimeBuilder->compilePlans($classes);
    $matchers = $runtimeBuilder->getPlanCompilers();

    $compiledData = $runtimeConfig->toArray();
    $compiledData[ConfigKey::DEPENDENCIES][PlanCompiler::CONFIG_KEY] = $plans;
    $compiledData[ConfigKey::DEPENDENCIES][PlanDispatcher::CONFIG_KEY] = PlanDispatcher::kindMap(
        $matchers['param'],
        $matchers['prop'],
    );

    return [
        ContainerBuilder::configure($runtimeConfig)->build(),
        ContainerBuilder::configure(new Config($compiledData, $runtimeConfig->environment))->build(),
    ];
}

function diCompiledParityLogin(Container $container, FakeUser $user): void
{
    $container->get(CurrentUserProviderInterface::class)->setUser($user);
}

describe('Compile\\CompiledPlanParity', function () {
    it('keeps get, factories, invokables, autowire, aliases, delegators and set behavior identical', function () {
        [$runtime, $compiled] = diCompiledParityContainers(
            [DiCompiledParityConsumer::class],
            [
                ConfigKey::FACTORIES => [
                    'factory.value' => static fn (): ServiceWithParam => new ServiceWithParam('factory'),
                ],
                ConfigKey::INVOKABLES => [
                    'simple.alias' => SimpleService::class,
                ],
                ConfigKey::AUTOWIRES => [
                    DiCompiledParityConsumer::class,
                ],
                ConfigKey::ALIASES => [
                    'consumer.alias' => DiCompiledParityConsumer::class,
                    'factory.alias' => 'factory.value',
                ],
                ConfigKey::DELEGATORS => [
                    'factory.value' => [
                        static fn (ServiceWithParam $service): ServiceWithParam
                            => new ServiceWithParam($service->value . '-decorated'),
                    ],
                ],
            ],
        );

        expect($compiled->get('factory.value')->value)->toBe($runtime->get('factory.value')->value)
            ->and($compiled->get('factory.alias')->value)->toBe($runtime->get('factory.alias')->value)
            ->and($compiled->get('simple.alias'))->toBeInstanceOf(SimpleService::class)
            ->and($compiled->get(DiCompiledParityConsumer::class)->service->value)
            ->toBe($runtime->get(DiCompiledParityConsumer::class)->service->value)
            ->and($compiled->get('consumer.alias')->service->value)
            ->toBe($runtime->get('consumer.alias')->service->value);

        $runtime->set('runtime.value', new ServiceWithParam('set'));
        $compiled->set('runtime.value', new ServiceWithParam('set'));

        expect($compiled->get('runtime.value')->value)->toBe($runtime->get('runtime.value')->value);
    });

    it('keeps make and call explicit parameter precedence identical', function () {
        [$runtime, $compiled] = diCompiledParityContainers(
            [DiCompiledParityExplicitParams::class],
            [ConfigKey::AUTOWIRES => [DiCompiledParityExplicitParams::class]],
        );
        $provided = new ServiceWithParam('provided');
        $params = [
            'first' => 'by-name',
            1 => 'by-position',
            ServiceWithParam::class => $provided,
        ];

        $runtimeMade = $runtime->make(DiCompiledParityExplicitParams::class, $params);
        $compiledMade = $compiled->make(DiCompiledParityExplicitParams::class, $params);

        expect($compiledMade->first)->toBe($runtimeMade->first)
            ->and($compiledMade->second)->toBe($runtimeMade->second)
            ->and($compiledMade->service)->toBe($runtimeMade->service)
            ->toBe($provided);

        $callable = static fn (ServiceWithParam $service, string $first, string $second): string
            => "{$first}:{$second}:{$service->value}";

        $callParams = [
            'first' => 'by-name',
            'second' => 'by-call-name',
            ServiceWithParam::class => $provided,
        ];

        expect($compiled->call($callable, $callParams))->toBe($runtime->call($callable, $callParams));
    });

    it('keeps compiled attribute resolution identical for parameters and properties', function () {
        [$runtime, $compiled] = diCompiledParityContainers(
            [DiCompiledParityAttributeTarget::class, DiCompiledParityPropertyTarget::class],
            [
                ConfigKey::AUTOWIRES => [
                    DiCompiledParityAttributeTarget::class,
                    DiCompiledParityPropertyTarget::class,
                    ServiceWithParam::class,
                ],
                ConfigKey::SERVICES => [
                    'entry.service' => new ServiceWithParam('entry'),
                ],
            ],
            ['app.name' => 'Ophire'],
            ['PARITY_ENV' => 'env-value'],
        );
        $user = new FakeUser('Ada');

        diCompiledParityLogin($runtime, $user);
        diCompiledParityLogin($compiled, $user);

        $runtimeTarget = $runtime->make(DiCompiledParityAttributeTarget::class, ['count' => '42']);
        $compiledTarget = $compiled->make(DiCompiledParityAttributeTarget::class, ['count' => '42']);

        expect([
            $compiledTarget->appName,
            $compiledTarget->envValue,
            $compiledTarget->made->value,
            $compiledTarget->entry->value,
            $compiledTarget->count,
            $compiledTarget->user,
        ])->toBe([
            $runtimeTarget->appName,
            $runtimeTarget->envValue,
            $runtimeTarget->made->value,
            $runtimeTarget->entry->value,
            $runtimeTarget->count,
            $runtimeTarget->user,
        ]);

        $runtimePropertyTarget = $runtime->make(DiCompiledParityPropertyTarget::class, ['count' => '27']);
        $compiledPropertyTarget = $compiled->make(DiCompiledParityPropertyTarget::class, ['count' => '27']);

        expect([
            $compiledPropertyTarget->appName,
            $compiledPropertyTarget->envValue,
            $compiledPropertyTarget->made->value,
            $compiledPropertyTarget->entry->value,
            $compiledPropertyTarget->count,
            $compiledPropertyTarget->user,
        ])->toBe([
            $runtimePropertyTarget->appName,
            $runtimePropertyTarget->envValue,
            $runtimePropertyTarget->made->value,
            $runtimePropertyTarget->entry->value,
            $runtimePropertyTarget->count,
            $runtimePropertyTarget->user,
        ]);
    });

    it('keeps request mapper behavior identical', function () {
        [$runtime, $compiled] = diCompiledParityContainers([DiCompiledParityRequestHandler::class]);
        $request = (new FakeServerRequest('GET', '/?q=search&page=3'))
            ->withQueryParams(['q' => 'search', 'page' => '3']);
        $handler = new DiCompiledParityRequestHandler();
        $params = RequestParameter::with([], $request);

        expect($compiled->call([$handler, 'handle'], $params))
            ->toBe($runtime->call([$handler, 'handle'], $params))
            ->toBe(['q' => 'search', 'page' => 3]);
    });

    it('keeps lazy and proxy entry behavior identical', function () {
        DiCompiledParityLazyService::$constructed = 0;
        DiCompiledParityProxyService::$constructed = 0;

        [$runtime, $compiled] = diCompiledParityContainers(
            [DiCompiledParityLazyService::class, DiCompiledParityProxyService::class],
            [
                ConfigKey::INVOKABLES => [
                    DiCompiledParityLazyService::class,
                    DiCompiledParityProxyService::class,
                ],
            ],
        );

        $runtimeLazy = $runtime->get(DiCompiledParityLazyService::class);
        $compiledLazy = $compiled->get(DiCompiledParityLazyService::class);
        $runtimeProxy = $runtime->get(DiCompiledParityProxyService::class);
        $compiledProxy = $compiled->get(DiCompiledParityProxyService::class);

        expect($runtimeLazy)->toBeInstanceOf(DiCompiledParityLazyService::class)
            ->and($compiledLazy)->toBeInstanceOf(DiCompiledParityLazyService::class)
            ->and($runtimeProxy)->toBeInstanceOf(DiCompiledParityProxyService::class)
            ->and($compiledProxy)->toBeInstanceOf(DiCompiledParityProxyService::class)
            ->and($compiledLazy->value)->toBe($runtimeLazy->value)
            ->and($compiledProxy->value())->toBe($runtimeProxy->value());
    });

    it('keeps circular dependency errors identical', function () {
        [$runtime, $compiled] = diCompiledParityContainers(
            [DiCompiledParityCycleA::class, DiCompiledParityCycleB::class],
            [
                ConfigKey::AUTOWIRES => [
                    DiCompiledParityCycleA::class,
                    DiCompiledParityCycleB::class,
                ],
            ],
        );

        expect(fn () => $runtime->get(DiCompiledParityCycleA::class))
            ->toThrow(CircularDependencyException::class)
            ->and(fn () => $compiled->get(DiCompiledParityCycleA::class))
            ->toThrow(CircularDependencyException::class);
    });
});
