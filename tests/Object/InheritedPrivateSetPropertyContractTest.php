<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Object\InheritedPrivateSetPropertyContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use RuntimeException;
use Throwable;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class UncachedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final readonly class Token {}

final class State
{
    /** @var list<string> */
    public static array $events = [];
    public static ?Throwable $failure = null;
}

class Product
{
    #[EntryId('private-set.label')]
    public private(set) string $plain;

    #[EntryId('private-set.label')]
    protected private(set) string $protected;

    #[EntryId('private-set.label'), UncachedMarker]
    public private(set) string $hooked {
        set {
            State::$events[] = 'hook:' . $value;
            if (State::$failure !== null) {
                throw State::$failure;
            }
            $this->hooked = strtoupper($value);
        }
    }

    private string $virtualStorage = 'unconfigured';

    #[EntryId('private-set.label')]
    public private(set) string $virtual {
        get => $this->virtualStorage;
        set {
            State::$events[] = 'virtual:' . $value;
            $this->virtualStorage = $value;
        }
    }

    public function __construct()
    {
        State::$events[] = 'constructor';
    }

    public function protectedValue(): string
    {
        return $this->protected;
    }

    public function configured(): void
    {
        State::$events[] = 'setup:' . $this->hooked;
    }
}

#[SetUp('configured')]
final class EagerProduct extends Product {}

#[Lazy, SetUp('configured')]
final class GhostProduct extends Product {}

#[Proxy, SetUp('configured')]
final class ProxyProduct extends Product {}

class ReadonlyProduct extends Product
{
    public function __construct(
        #[EntryId('private-set.token')]
        public private(set) readonly Token $token,
    ) {
        parent::__construct();
    }
}

#[NoConstructor, Lazy, SetUp('configured')]
final class ReadonlyGhostProduct extends ReadonlyProduct {}

#[NoConstructor, Proxy, SetUp('configured')]
final class ReadonlyProxyProduct extends ReadonlyProduct {}

function builder(bool $uncached, Token $token): ContainerBuilder
{
    $builder = (new ContainerBuilder())
        ->addService('private-set.token', $token)
        ->addService('private-set.label', 'injected');
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
}

beforeEach(function (): void {
    State::$events = [];
    State::$failure = null;
});

afterEach(function (): void {
    State::$events = [];
    State::$failure = null;
});

test('inherited private setters retain property injection and native hooks through every creation strategy', function (string $class, bool $deferred, bool $uncached): void {
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a private-set property fixture.');
    }
    $token = new Token();
    $container = builder($uncached, $token)->build();

    foreach ([1, 2] as $_attempt) {
        State::$events = [];
        $product = $container->make($class);
        Assert::assertInstanceOf($class, $product);
        expect(State::$events)->toBe($deferred ? [] : ['constructor', 'hook:injected', 'virtual:injected', 'setup:INJECTED'])
            ->and($product->plain)->toBe('injected')
            ->and($product->protectedValue())->toBe('injected')
            ->and($product->hooked)->toBe('INJECTED')
            ->and($product->virtual)->toBe('injected')
            ->and(State::$events)->toBe(['constructor', 'hook:injected', 'virtual:injected', 'setup:INJECTED']);
    }
})->with([
    'eager' => [EagerProduct::class, false],
    'ghost' => [GhostProduct::class, true],
    'proxy' => [ProxyProduct::class, true],
])->with(['cached plans' => false, 'uncached plans' => true]);

test('failed inherited private setter initialization retries readonly injection and preserves the hook cause', function (string $class, bool $uncached): void {
    if (!is_a($class, ReadonlyProduct::class, true)) {
        throw new LogicException('Expected a deferred private-set property fixture.');
    }
    $first = new Token();
    $second = new Token();
    $container = builder($uncached, $first)->build();
    $cause = new RuntimeException('inherited hook unavailable');
    State::$failure = $cause;
    $product = $container->make($class);
    Assert::assertInstanceOf($class, $product);
    $reflection = new ReflectionClass($class);
    $failure = null;
    try {
        $reflection->initializeLazyObject($product);
    } catch (Throwable $exception) {
        $failure = $exception;
    }
    expect($failure)->toBeInstanceOf(ResolutionException::class);
    if (!$failure instanceof ResolutionException) {
        throw new LogicException('Expected the inherited setter failure.');
    }
    expect($failure->getPrevious())->toBe($cause)
        ->and($failure->propertyClass)->toBe(Product::class)
        ->and($failure->propertyName)->toBe('hooked')
        ->and($reflection->isUninitializedLazyObject($product))->toBeTrue()
        ->and(State::$events)->toBe(['hook:injected']);

    State::$failure = null;
    $container->set('private-set.token', $second);
    $container->set('private-set.label', 'recovered');

    expect($product->hooked)->toBe('RECOVERED')
        ->and($product->token)->toBe($second)
        ->and($product->plain)->toBe('recovered')
        ->and($product->protectedValue())->toBe('recovered')
        ->and($product->virtual)->toBe('recovered')
        ->and(State::$events)->toBe(['hook:injected', 'hook:recovered', 'virtual:recovered', 'setup:RECOVERED']);
})->with([ReadonlyGhostProduct::class, ReadonlyProxyProduct::class])
    ->with(['cached plans' => false, 'uncached plans' => true]);
