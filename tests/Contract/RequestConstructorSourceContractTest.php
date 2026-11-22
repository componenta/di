<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestConstructorSource;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapQueryString;
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
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ConstructorMapper extends MapQueryString {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Metadata
{
    public function __construct(public string $value) {}
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
    public static int $callbacks = 0;
    public static int $invoked = 0;
    public static int $autoloads = 0;
    public static int $autoloadsBeforeConstructor = 0;
    /** @var list<int> */
    public static array $autoloadsAtConstructor = [];
}

final readonly class PreparingFactory implements EntryParametersFactoryInterface
{
    /** @param Closure():void $beforeConstructor */
    public function __construct(
        private ParametersResolver $parameters,
        private bool $initialize,
        private Closure $beforeConstructor,
    ) {}

    public function make(string $entry, array $params = []): object
    {
        return $this->makeEntry($entry, ConstructionParameters::capture($params));
    }

    public function makeEntry(string $entry, EntryParameters $parameters): object
    {
        $provenance = ConstructionParameters::provenance($parameters);
        if (!class_exists($entry)) {
            throw new LogicException('Expected a declared request DTO.');
        }
        $class = new ReflectionClass($entry);
        $constructor = $class->getConstructor();
        if ($constructor === null) {
            throw new LogicException('Expected the fixture constructor.');
        }
        $arguments = ParameterExecutionAccess::for($this->parameters);
        $plan = $arguments->prepare($constructor->getParameters());
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

function target(string $source, string $unavailable): Closure
{
    $dto = 'Dto_' . bin2hex(random_bytes(5));
    eval(sprintf(
        'namespace %s; final class %s { public function __construct(#[%s("trusted"), %s] public string $role) { Observation::$values[] = $role; Observation::$autoloadsAtConstructor[] = Observation::$autoloads; } }',
        __NAMESPACE__,
        $dto,
        $source,
        $unavailable,
    ));
    $target = eval(sprintf(
        'namespace %s; return static function (#[ConstructorMapper] %s $dto): object { ++Observation::$invoked; return $dto; };',
        __NAMESPACE__,
        $dto,
    ));
    if (!$target instanceof Closure) {
        throw new LogicException('Expected a public request-mapping callable.');
    }
    return $target;
}

test('request sources revealed by the final constructor callback are checked before native construction', function (
    bool $initialize,
    bool $uncached,
    bool $conflict,
): void {
    Observation::$values = [];
    Observation::$callbacks = Observation::$invoked = Observation::$autoloads = 0;
    Observation::$autoloadsBeforeConstructor = 0;
    Observation::$autoloadsAtConstructor = [];
    $source = 'Source_' . bin2hex(random_bytes(5));
    $unavailable = 'Unavailable_' . bin2hex(random_bytes(5));
    $target = target($source, $unavailable);
    $builder = (new ContainerBuilder())->addService('trusted', 'trusted value');
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    $parameters = $builder->assemble()[1]->parameters;
    Assert::assertInstanceOf(ParametersResolver::class, $parameters);
    $factory = new PreparingFactory(
        $parameters,
        $initialize,
        static function () use ($source, $conflict): void {
            ++Observation::$callbacks;
            if (!class_exists(__NAMESPACE__ . '\\' . $source, false)) {
                class_alias($conflict ? EntryId::class : Metadata::class, __NAMESPACE__ . '\\' . $source);
            }
            Observation::$autoloadsBeforeConstructor = Observation::$autoloads;
        },
    );
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            ConstructorMapper::class,
            new RequestAttributeHandler($factory, new TestCasterProvider()),
            [ValueProvider::class],
        ))
        ->build();
    $loader = static function (string $class) use ($unavailable): void {
        if ($class === __NAMESPACE__ . '\\' . $unavailable) {
            ++Observation::$autoloads;
        }
    };
    spl_autoload_register($loader);

    try {
        $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'untrusted']);
        if ($conflict) {
            expect(fn() => $container->call($target, [ServerRequestInterface::class => $request]))
                ->toThrow(RequestParameterSourceConflictException::class, 'role')
                ->and(Observation::$callbacks)->toBe(1)
                ->and(Observation::$values)->toBe([])
                ->and(Observation::$invoked)->toBe(0)
                ->and(Observation::$autoloads)->toBe(Observation::$autoloadsBeforeConstructor);

            $container->call($target, [ServerRequestInterface::class => $request->withQueryParams([])]);
            expect(Observation::$values)->toBe(['trusted value'])
                ->and(Observation::$callbacks)->toBe(2)
                ->and(Observation::$invoked)->toBe(1);
        } else {
            $container->call($target, [ServerRequestInterface::class => $request]);
            expect(Observation::$values)->toBe(['untrusted'])
                ->and(Observation::$callbacks)->toBe(1)
                ->and(Observation::$invoked)->toBe(1);
        }
        expect(Observation::$autoloadsAtConstructor)->toBe([Observation::$autoloadsBeforeConstructor])
            ->and(Observation::$autoloadsBeforeConstructor)->toBeGreaterThan(0);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['create' => false, 'initialize' => true])
    ->with(['cached plans' => false, 'non-memoized plans' => true])
    ->with(['source' => true, 'unrelated metadata' => false]);
