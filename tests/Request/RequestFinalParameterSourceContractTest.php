<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestFinalParameterSourceContract;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ServerRequestInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class TrustedValue implements ParameterSourceAttributeInterface
{
    public function __construct(public string $value) {}
}

final class TrustedValueResolver implements ParameterResolverInterface
{
    public function supports(ParameterTarget $target): bool
    {
        return $target->hasAttribute(TrustedValue::class);
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        $source = $target->firstAttribute(TrustedValue::class);
        if ($source === null) {
            throw new \LogicException('Expected the declared trusted-value source.');
        }
        return [$target->position, $source->value];
    }
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class Observation
{
    /** @var list<string> */
    public static array $values = [];
    public static int $invoked = 0;

    public static function reset(): void
    {
        self::$values = [];
        self::$invoked = 0;
    }
}

function target(string $source, bool $callback): Closure
{
    $dto = 'Dto_' . bin2hex(random_bytes(5));
    $parameters = sprintf('#[%s("trusted value")] string $role', $source);
    if ($callback) {
        $setup = 'SETUP_' . $dto;
        define(__NAMESPACE__ . '\\' . $setup, eval(sprintf(
            'namespace %s; return static function (object $dto, %s): void { Observation::$values[] = $role; };',
            __NAMESPACE__,
            $parameters,
        )));
        $method = '';
    } else {
        $setup = '"configure"';
        $method = sprintf('public function configure(%s): void { Observation::$values[] = $role; }', $parameters);
    }
    eval(sprintf(
        'namespace %s; #[\\Componenta\\DI\\Attribute\\SetUp(%s)] final class %s { %s }',
        __NAMESPACE__,
        $setup,
        $dto,
        $method,
    ));
    $target = eval(sprintf(
        'namespace %s; return static function (#[\\Componenta\\DI\\Attribute\\MapRequest(sources: [\\Componenta\\DI\\Attribute\\RequestDataSource::Query])] %s $dto): void { ++Observation::$invoked; };',
        __NAMESPACE__,
        $dto,
    ));
    if (!$target instanceof Closure) {
        throw new \LogicException('Expected the public request-mapping target.');
    }
    return $target;
}

test('final parameter preparation cannot hide a newly available custom source from setup invocation', function (
    bool $callback,
    bool $uncached,
): void {
    $blocked = 0;
    $recovered = false;
    $delays = [PHP_INT_MAX];
    for ($index = 0; $index < count($delays); ++$index) {
        $delay = $delays[$index];
        $source = 'Source_' . bin2hex(random_bytes(5));
        $target = target($source, $callback);
        Observation::reset();
        $attempts = 0;
        $beforeConsumption = 0;
        $loadedBeforeConsumption = false;
        $loader = static function (string $class) use ($source, $delay, &$attempts, &$beforeConsumption, &$loadedBeforeConsumption): void {
            if ($class !== __NAMESPACE__ . '\\' . $source) {
                return;
            }
            if (Observation::$values === []) {
                ++$beforeConsumption;
            }
            if (++$attempts === $delay) {
                $loadedBeforeConsumption = Observation::$values === [];
                class_alias(TrustedValue::class, $class);
            }
        };
        spl_autoload_register($loader);
        try {
            $builder = (new ContainerBuilder())->addParameterResolver(new TrustedValueResolver(), 2000);
            if ($uncached) {
                $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
            }
            $container = $builder->build();
            $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);
            $failure = null;
            try {
                $container->call($target, [ServerRequestInterface::class => $request]);
            } catch (RequestParameterSourceConflictException $error) {
                $failure = $error;
            }

            if ($index === 0) {
                expect($failure)->toBeNull()
                    ->and(Observation::$values)->toBe(['untrusted'])
                    ->and(Observation::$invoked)->toBe(1)
                    ->and($beforeConsumption)->toBeGreaterThan(0);
                // Calibrate with the same public operation and an unavailable
                // source. No production poll count or private phase is assumed.
                $delays = [PHP_INT_MAX, ...range(1, $beforeConsumption)];
                continue;
            }
            if (!$loadedBeforeConsumption) {
                continue;
            }
            ++$blocked;
            expect(Observation::$values)->toBe([], 'source availability delay: ' . $delay)
                ->and(Observation::$invoked)->toBe(0)
                ->and($failure)->toBeInstanceOf(RequestParameterSourceConflictException::class);

            if (!$recovered) {
                Observation::reset();
                $container->call($target, [ServerRequestInterface::class => $request->withQueryParams([])]);
                expect(Observation::$values)->toBe(['trusted value'])
                    ->and(Observation::$invoked)->toBe(1);
                $recovered = true;
            }
        } finally {
            spl_autoload_unregister($loader);
        }
    }
    expect($blocked)->toBeGreaterThan(0)->and($recovered)->toBeTrue();
})->with(['setup method' => false, 'setup callback' => true])
    ->with(['cached plans' => false, 'non-memoized plans' => true]);

abstract class ConfigurableLocale
{
    /** @var list<string> */
    public array $settings = [];

    public function __construct(public string $locale) {}

    public function configure(
        #[EntryId('configured.locale')] string $locale,
        #[EntryId('configured.region')] string $region,
    ): void {
        $this->settings = [$locale, $region];
    }
}

define(__NAMESPACE__ . '\\CONFIGURE_LOCALE', static function (
    ConfigurableLocale $entry,
    #[EntryId('configured.locale')] string $locale,
    #[EntryId('configured.region')] string $region,
): void {
    $entry->configure($locale, $region);
});

#[SetUp('configure', ['locale' => 'setup locale'])]
final class ConfiguredByMethod extends ConfigurableLocale {}

#[SetUp(CONFIGURE_LOCALE, ['locale' => 'setup locale'])]
final class ConfiguredByCallback extends ConfigurableLocale {}

test('explicit setup arguments replace mapped values while unrelated mapped keys still undergo source checks', function (bool $callback): void {
    $container = (new ContainerBuilder())->addServices([
        'configured.locale' => 'service locale',
        'configured.region' => 'service region',
    ])->build();
    $target = $callback
        ? static fn(#[MapQueryString] ConfiguredByCallback $dto): ConfigurableLocale => $dto
        : static fn(#[MapQueryString] ConfiguredByMethod $dto): ConfigurableLocale => $dto;
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['locale' => 'request locale']);

    $dto = $container->call($target, [ServerRequestInterface::class => $request]);

    Assert::assertInstanceOf(ConfigurableLocale::class, $dto);
    expect($dto->locale)->toBe('request locale')
        ->and($dto->settings)->toBe(['setup locale', 'service region']);

    $conflict = $request->withQueryParams(['locale' => 'request locale', 'region' => 'request region']);
    expect(fn() => $container->call($target, [ServerRequestInterface::class => $conflict]))
        ->toThrow(RequestParameterSourceConflictException::class, 'Mapped request data key "region"');
})->with(['setup method' => false, 'setup callback' => true]);
