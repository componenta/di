<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Request\RequestConstructorMetadataContract;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Container;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Internal\Resolver\Entry\ConstructionParameters;
use Componenta\DI\Internal\Resolver\Entry\ConstructorInvocation;
use Componenta\DI\Internal\Resolver\Parameter\ParameterExecutionAccess;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryParametersFactoryInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Componenta\DI\Tests\Support\TestCasterProvider;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionParameter;
use RuntimeException;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Mapper extends MapQueryString {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class Observation
{
    public static bool $armed = false;
    public static int $metadataReads = 0;
    public static int $invoked = 0;
    /** @var list<string> */
    public static array $values = [];
}

final class ReportingParameter extends ReflectionParameter
{
    /**
     * @param class-string $class
     * @param Closure():void $onRead
     * @param class-string|null $diagnosticOwner
     */
    public function __construct(string $class, int $position, private readonly Closure $onRead, private readonly ?string $diagnosticOwner)
    {
        parent::__construct([$class, '__construct'], $position);
    }

    /** @return ReflectionClass<object>|null */
    public function getDeclaringClass(): ?ReflectionClass
    {
        if (Observation::$armed) {
            ++Observation::$metadataReads;
            ($this->onRead)();
            if ($this->diagnosticOwner !== null) {
                return new ReflectionClass($this->diagnosticOwner);
            }
        }
        return parent::getDeclaringClass();
    }
}

final readonly class PreparingFactory implements EntryParametersFactoryInterface
{
    /**
     * @param Closure():void $beforeConstructor
     * @param Closure():void $onRead
     * @param class-string|null $diagnosticOwner
     */
    public function __construct(
        private ParametersResolver $parameters,
        private bool $initialize,
        private int $position,
        private Closure $beforeConstructor,
        private Closure $onRead,
        private ?string $diagnosticOwner = null,
    ) {}

    public function make(string $entry, array $params = []): object
    {
        return $this->makeEntry($entry, ConstructionParameters::capture($params));
    }

    public function makeEntry(string $entry, EntryParameters $parameters): object
    {
        $provenance = ConstructionParameters::provenance($parameters);
        if (!class_exists($entry)) {
            throw new LogicException('Expected the declared request DTO.');
        }
        $class = new ReflectionClass($entry);
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            throw new LogicException('Expected the fixture constructor.');
        }
        $reflections = $constructor->getParameters();
        $reflections[$this->position] = new ReportingParameter($entry, $this->position, $this->onRead, $this->diagnosticOwner);
        $arguments = ParameterExecutionAccess::for($this->parameters);
        $plan = $arguments->prepare(array_values($reflections));
        if ($this->initialize) {
            $product = $class->newInstanceWithoutConstructor();
            ConstructorInvocation::initialize($arguments, $product, $constructor, $plan, $parameters->values, $this->beforeConstructor, $proof, $provenance);
        } else {
            $product = ConstructorInvocation::create($arguments, $class, $constructor, $plan, $parameters->values, $this->beforeConstructor, $proof, $provenance);
        }
        $proof?->assertLoadedParametersUnchanged();
        $proof?->assertParameterSourcesUnchanged();
        return $product;
    }
}

function target(string $parameters): Closure
{
    $dto = 'Dto_' . bin2hex(random_bytes(5));
    eval(sprintf(
        'namespace %s; final class %s { public function __construct(%s) { Observation::$values[] = $role; } }',
        __NAMESPACE__,
        $dto,
        $parameters,
    ));
    $target = eval(sprintf(
        'namespace %s; return static function (#[Mapper] %s $dto): string { ++Observation::$invoked; return $dto->role; };',
        __NAMESPACE__,
        $dto,
    ));
    if (!$target instanceof Closure) {
        throw new LogicException('Expected a request mapping callback.');
    }
    return $target;
}

function parameters(bool $uncached): ParametersResolver
{
    $builder = (new ContainerBuilder())
        ->addService('trusted.role', 'trusted role')
        ->addService('trusted.other', 'trusted other');
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder->assemble()[1]->parameters;
}

function container(PreparingFactory $factory): Container
{
    return (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(Mapper::class, new RequestAttributeHandler($factory, new TestCasterProvider()), [ValueProvider::class]))
        ->build();
}

beforeEach(function (): void {
    Observation::$armed = false;
    Observation::$metadataReads = Observation::$invoked = 0;
    Observation::$values = [];
});

test('successful mapped source checks do not execute diagnostic metadata callbacks', function (bool $initialize, bool $uncached, bool $throw): void {
    $source = 'Source_' . bin2hex(random_bytes(5));
    $target = target(sprintf(
        '#[%s("trusted.role")] public string $role, #[\\Componenta\\DI\\Attribute\\EntryId("trusted.other")] public string $other',
        $source,
    ));
    $container = container(new PreparingFactory(
        parameters($uncached),
        $initialize,
        1,
        static function (): void {
            Observation::$armed = true;
        },
        static function () use ($source, $throw): void {
            if ($throw) {
                throw new RuntimeException('Diagnostic metadata is unavailable.');
            }
            class_alias(EntryId::class, __NAMESPACE__ . '\\' . $source);
        },
    ));
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'mapped role']);

    expect($container->call($target, [ServerRequestInterface::class => $request]))->toBe('mapped role')
        ->and(Observation::$values)->toBe(['mapped role'])
        ->and(Observation::$invoked)->toBe(1)
        ->and(Observation::$metadataReads)->toBe(0)
        ->and(class_exists(__NAMESPACE__ . '\\' . $source, false))->toBeFalse();
})->with(['create' => false, 'initialize' => true])
    ->with(['cached plans' => false, 'non-memoized plans' => true])
    ->with(['source discovery' => false, 'metadata failure' => true]);

test('a final mapped source conflict keeps the custom declaring class diagnostic', function (bool $initialize, bool $uncached): void {
    $source = 'Conflict_' . bin2hex(random_bytes(5));
    $target = target(sprintf('#[%s("trusted.role")] public string $role', $source));
    $container = container(new PreparingFactory(
        parameters($uncached),
        $initialize,
        0,
        static function () use ($source): void {
            class_alias(EntryId::class, __NAMESPACE__ . '\\' . $source);
            Observation::$armed = true;
        },
        static function (): void {},
        \stdClass::class,
    ));
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'mapped role']);

    try {
        $container->call($target, [ServerRequestInterface::class => $request]);
        \PHPUnit\Framework\Assert::fail('The source conflict must prevent native construction.');
    } catch (RequestParameterSourceConflictException $exception) {
        expect($exception->dtoClass)->toBe(\stdClass::class)
            ->and($exception->key)->toBe('role')
            ->and($exception->parameter)->toBe('role')
            ->and($exception->source)->toBe(__NAMESPACE__ . '\\' . $source)
            ->and(Observation::$metadataReads)->toBe(1)
            ->and(Observation::$values)->toBe([])
            ->and(Observation::$invoked)->toBe(0);
    }
})->with(['create' => false, 'initialize' => true])
    ->with(['cached plans' => false, 'non-memoized plans' => true]);
