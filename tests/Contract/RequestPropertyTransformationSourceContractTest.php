<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestPropertyTransformationSource;

use Attribute;
use Closure;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class State
{
    public static string $source;
    public static bool $reveal = false;
    public static int $prepared = 0;
    public static int $constructed = 0;
    public static int $written = 0;
    public static int $invoked = 0;
    /** @var list<mixed> */
    public static array $values = [];

    public static function prepare(): void
    {
        ++self::$prepared;
        if (self::$reveal) {
            class_alias(EntryId::class, self::$source);
        }
    }
}

final class Dependency {}

final class Provider implements CasterProviderInterface, CasterInterface
{
    public string $name = 'role';

    public function provide(string $name): CasterInterface
    {
        State::prepare();
        return $this;
    }

    public function cast(mixed $value): mixed
    {
        State::$values[] = $value;
        return $value;
    }
}

function transform(string $value, Dependency $dependency): string
{
    State::$values[] = $value;
    return $value;
}

define(__NAMESPACE__ . '\\TRANSFORM', transform(...));

function builder(bool $uncached): ContainerBuilder
{
    $builder = (new ContainerBuilder())
        ->addService('trusted', 'trusted value')
        ->addService(CasterProviderInterface::class, new Provider())
        ->addFactory(Dependency::class, static function (): Dependency {
            State::prepare();
            return new Dependency();
        });
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
}

beforeEach(function (): void {
    State::$source = __NAMESPACE__ . '\\Source_' . bin2hex(random_bytes(5));
    State::$reveal = false;
    State::$prepared = State::$constructed = State::$written = State::$invoked = 0;
    State::$values = [];
});

test('property transformations recheck mapped sources after preparing their dependencies', function (
    bool $transform,
    bool $noConstructor,
    bool $uncached,
    string $discovery,
): void {
    $dto = 'Dto_' . bin2hex(random_bytes(5));
    $attribute = $transform ? '\\Componenta\\DI\\Attribute\\Transform(TRANSFORM)' : '\\Componenta\\DI\\Attribute\\Cast("role")';
    eval(sprintf(
        'namespace %s; %s final class %s {
            #[%s] public string $role = "default" {
                set { ++State::$written; $this->role = $value; }
            }
            public function __construct(#[\\%s("trusted")] string $role) { ++State::$constructed; }
        }',
        __NAMESPACE__,
        $noConstructor ? '#[\\Componenta\\DI\\Attribute\\NoConstructor]' : '',
        $dto,
        $attribute,
        State::$source,
    ));
    $target = eval(sprintf(
        'namespace %s; return static function (#[\\Componenta\\DI\\Attribute\\MapQueryString] %s $dto): string { ++State::$invoked; return $dto->role; };',
        __NAMESPACE__,
        $dto,
    ));
    if (!$target instanceof Closure) {
        throw new \LogicException('Expected the public request mapping callable.');
    }
    State::$reveal = $discovery === 'during preparation';
    if ($discovery === 'already available') {
        class_alias(EntryId::class, State::$source);
    }
    $container = builder($uncached)->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'mapped value']);

    if ($discovery === 'unavailable') {
        expect($container->call($target, [ServerRequestInterface::class => $request]))->toBe('mapped value')
            ->and(State::$values)->toBe(['mapped value'])
            ->and(State::$written)->toBe(1)
            ->and(State::$invoked)->toBe(1);
    } else {
        try {
            $container->call($target, [ServerRequestInterface::class => $request]);
            \PHPUnit\Framework\Assert::fail('A conflicting mapped value must not reach the property transformation.');
        } catch (RequestParameterSourceConflictException $exception) {
            expect($exception->dtoClass)->toBe(__NAMESPACE__ . '\\' . $dto)
                ->and($exception->parameter)->toBe('role')
                ->and($exception->key)->toBe('role')
                ->and($exception->source)->toBe(State::$source)
                ->and($exception->getPrevious())->toBeNull();
        }
        expect(State::$values)->toBe([])
            ->and(State::$written)->toBe(0)
            ->and(State::$invoked)->toBe(0);
    }

    expect(State::$prepared)->toBe($discovery === 'already available' ? 0 : 1)
        ->and(State::$constructed)->toBe($noConstructor || $discovery === 'already available' ? 0 : 1);
})->with(['Cast' => false, 'Transform' => true])
    ->with(['constructor' => false, 'NoConstructor' => true])
    ->with(['cached plans' => false, 'non-memoized plans' => true])
    ->with(['unavailable', 'during preparation', 'already available']);

test('transforming a provider-owned property does not materialize unused context parameters', function (
    bool $transform,
    bool $uncached,
): void {
    $dto = 'ProviderDto_' . bin2hex(random_bytes(5));
    $attribute = $transform ? '\\Componenta\\DI\\Attribute\\Transform(TRANSFORM)' : '\\Componenta\\DI\\Attribute\\Cast("role")';
    eval(sprintf(
        'namespace %s; #[\\Componenta\\DI\\Attribute\\NoConstructor] final class %s {
            #[\\Componenta\\DI\\Attribute\\EntryId("trusted"), %s] public string $role;
        }',
        __NAMESPACE__,
        $dto,
        $attribute,
    ));
    $class = __NAMESPACE__ . '\\' . $dto;
    if (!class_exists($class)) {
        throw new \LogicException('Expected the declared property fixture.');
    }
    $container = builder($uncached)->build();

    $entry = $container->getAs(ObjectPipeline::class, ObjectPipeline::class)->create($class, resolveParameters: static function (): never {
        throw new \LogicException('The provider supplies the value without reading the public parameter bag.');
    });

    expect(get_object_vars($entry))->toBe(['role' => 'trusted value'])
        ->and(State::$values)->toBe(['trusted value'])
        ->and(State::$prepared)->toBe(1);
})->with(['Cast' => false, 'Transform' => true])
    ->with(['cached plans' => false, 'non-memoized plans' => true]);
