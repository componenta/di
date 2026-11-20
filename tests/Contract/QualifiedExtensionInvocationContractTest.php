<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\QualifiedExtensionInvocation;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Container;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ErrorException;
use LogicException;
use Psr\Container\ContainerInterface;
use Throwable;
use WeakReference;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class SelectedValue {}

final readonly class SelectedResolver implements ParameterResolverInterface
{
    public function __construct(private string $label) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'selected';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        return [$target->position, $this->label];
    }
}

final readonly class SelectedHandler implements ParameterAttributeHandlerInterface
{
    public function __construct(private string $label) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        return ParameterAttributeValue::resolved($this->label);
    }
}

function extension(bool $attribute, string $label): ParameterResolverInterface|AttributeDefinition
{
    return $attribute
        ? new AttributeDefinition(SelectedValue::class, new SelectedHandler($label), [ValueProvider::class])
        : new SelectedResolver($label);
}

class Ancestor
{
    public int $calls = 0;
    public ?Throwable $failure = null;
    public ?ContainerInterface $received = null;

    public function __construct(private readonly bool $attribute) {}

    public function build(ContainerInterface &$container): ParameterResolverInterface|AttributeDefinition
    {
        ++$this->calls;
        $this->received = $container;
        if ($this->failure !== null) {
            throw $this->failure;
        }
        $label = $container->get('extension.label');
        if (!is_string($label)) {
            throw new LogicException('Expected the configured extension label.');
        }
        return extension($this->attribute, 'ancestor:' . $label);
    }

    public static function staticBuild(ContainerInterface $container): ParameterResolverInterface|AttributeDefinition
    {
        $attribute = $container->get('extension.attribute');
        if (!is_bool($attribute)) {
            throw new LogicException('Expected the configured extension kind.');
        }
        return extension($attribute, static::class);
    }
}

final class Child extends Ancestor
{
    public function build(ContainerInterface &$container): ParameterResolverInterface|AttributeDefinition
    {
        throw new LogicException('The qualified ancestor route must not dispatch the child override.');
    }
}

function allowingQualifiedCallable(Closure $operation): mixed
{
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_DEPRECATED && str_starts_with($message, 'Callables of the form ')) {
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        return $operation();
    } finally {
        restore_error_handler();
    }
}

function nativeCallable(mixed $callable): callable
{
    if (!is_callable($callable)) {
        throw new LogicException('The qualified extension factory must be a native callable.');
    }
    return $callable;
}

function builder(bool $attribute, mixed $specification): ContainerBuilder
{
    $builder = (new ContainerBuilder())->addServices([
        'extension.label' => 'configured',
        'extension.attribute' => $attribute,
    ]);
    return $attribute
        ? $builder->addAttributeDefinition($specification)
        : $builder->addParameterResolver($specification, 1500);
}

test('extension factories invoke the selected qualified ancestor with the bootstrap container', function (bool $attribute, bool $service): void {
    allowingQualifiedCallable(static function () use ($attribute, $service): void {
        $owner = new Child($attribute);
        $method = Ancestor::class . '::build';
        $nativeContainer = (new ContainerBuilder())->addService('extension.label', 'native')->build();
        $native = Closure::fromCallable(nativeCallable([$owner, $method]));
        expect($native($nativeContainer))->toBeInstanceOf($attribute ? AttributeDefinition::class : ParameterResolverInterface::class)
            ->and($owner->received)->toBe($nativeContainer);
        $owner->calls = 0;
        $loads = 0;
        $specification = [$service ? 'extension.owner' : $owner, $method];
        $container = builder($attribute, $specification)
            ->addFactory('extension.owner', static function () use ($owner, &$loads): Child {
                ++$loads;
                return $owner;
            })
            ->build();

        foreach ([1, 2] as $_attempt) {
            expect($container->call(static fn(#[SelectedValue] string $selected): string => $selected))
                ->toBe('ancestor:configured');
        }
        expect($owner->received)->toBe($container)
            ->and($owner->calls)->toBe(1)
            ->and($loads)->toBe($service ? 1 : 0);
    });
})->with(['parameter resolver' => false, 'attribute definition' => true])
    ->with(['object pair' => false, 'service pair' => true]);

test('qualified static extension factories preserve native called class and static precedence', function (bool $attribute, bool $object): void {
    allowingQualifiedCallable(static function () use ($attribute, $object): void {
        $callable = nativeCallable([$object ? new Child($attribute) : Child::class, Ancestor::class . '::staticBuild']);
        $nativeContainer = (new ContainerBuilder())->addService('extension.attribute', $attribute)->build();
        $native = call_user_func_array($callable, [$nativeContainer]);
        $nativeBuilder = new ContainerBuilder();
        $nativeContainer = ($attribute
            ? $nativeBuilder->addAttributeDefinition($native)
            : $nativeBuilder->addParameterResolver($native, 1500))->build();
        $consume = static fn(#[SelectedValue] string $selected): string => $selected;
        $expected = $object ? Child::class : Ancestor::class;
        expect($nativeContainer->call($consume))->toBe($expected);
        $shadow = new Child($attribute);
        $container = builder($attribute, $callable)->addService(Child::class, $shadow)->build();

        expect($container->call($consume))->toBe($expected)
            ->and($container->get(Child::class))->toBe($shadow)
            ->and($shadow->calls)->toBe(0);
    });
})->with(['parameter resolver' => false, 'attribute definition' => true])
    ->with(['class receiver' => false, 'object receiver' => true]);

test('qualified extension factories preserve bootstrap failure ownership without replay', function (bool $attribute, bool $service, bool $owned): void {
    allowingQualifiedCallable(static function () use ($attribute, $service, $owned): void {
        $owner = new Child($attribute);
        $cause = $owned ? new InvalidConfigurationException('body failed') : new LogicException('body failed');
        $owner->failure = $cause;
        $specification = [$service ? 'extension.owner' : $owner, Ancestor::class . '::build'];
        $caught = null;
        try {
            builder($attribute, $specification)->addService('extension.owner', $owner)->build();
        } catch (Throwable $error) {
            $caught = $error;
        }

        expect($caught)->toBeInstanceOf(InvalidConfigurationException::class)
            ->and($owner->calls)->toBe(1);
        if ($owned) {
            expect($caught)->toBe($cause)->and($caught?->getPrevious())->toBeNull();
        } else {
            expect($caught?->getPrevious())->toBe($cause);
        }
    });
})->with(['parameter resolver' => false, 'attribute definition' => true])
    ->with(['object pair' => false, 'service pair' => true])
    ->with(['foreign failure' => false, 'owned failure' => true]);

#[Attribute(Attribute::TARGET_CLASS)]
final class Preparation {}

final class LifetimeState
{
    /** @var WeakReference<TemporaryReceiver>|null */
    public static ?WeakReference $owner = null;
    /** @var list<bool> */
    public static array $aliveDuringCall = [];
    public static int $released = 0;
}

class TemporaryAncestor
{
    public static function build(ContainerInterface $container): ParameterResolverInterface|AttributeDefinition
    {
        LifetimeState::$aliveDuringCall[] = LifetimeState::$owner?->get() !== null;
        $attribute = $container->get('extension.attribute');
        if (!is_bool($attribute)) {
            throw new LogicException('Expected the extension kind.');
        }
        return extension($attribute, 'temporary');
    }
}

final class TemporaryReceiver extends TemporaryAncestor
{
    public function __destruct()
    {
        ++LifetimeState::$released;
    }
}

final class TemporaryProvider implements ContainerInterface
{
    public function has(string $id): bool
    {
        return $id === 'extension.temporary';
    }

    public function get(string $id): TemporaryReceiver
    {
        if (!$this->has($id)) {
            throw new LogicException('Unexpected external owner lookup.');
        }
        $owner = new TemporaryReceiver();
        LifetimeState::$owner = WeakReference::create($owner);
        return $owner;
    }
}

test('qualified static extension factories retain a temporary receiver through execution', function (bool $attribute): void {
    allowingQualifiedCallable(static function () use ($attribute): void {
        LifetimeState::$aliveDuringCall = [];
        LifetimeState::$released = 0;
        $provider = new TemporaryProvider();
        $nativeContainer = (new ContainerBuilder())->addService('extension.attribute', $attribute)->build();
        $method = TemporaryAncestor::class . '::build';
        $native = nativeCallable([$provider->get('extension.temporary'), $method]);
        expect(call_user_func_array($native, [$nativeContainer]))
            ->toBeInstanceOf($attribute ? AttributeDefinition::class : ParameterResolverInterface::class)
            ->and(LifetimeState::$aliveDuringCall)->toBe([true])
            ->and(LifetimeState::$released)->toBe(0);
        unset($native);
        expect(LifetimeState::$released)->toBe(1);
        LifetimeState::$aliveDuringCall = [];
        LifetimeState::$released = 0;

        $builder = (new ContainerBuilder())
            ->addService('extension.attribute', $attribute)
            ->addAttributeDefinition(static function (Container $container) use ($provider): AttributeDefinition {
                $container->addContainer($provider);
                return new AttributeDefinition(Preparation::class);
            });
        $specification = ['extension.temporary', $method];
        $container = ($attribute
            ? $builder->addAttributeDefinition($specification)
            : $builder->addParameterResolver($specification, 1500))->build();

        expect($container->call(static fn(#[SelectedValue] string $selected): string => $selected))->toBe('temporary')
            ->and(LifetimeState::$aliveDuringCall)->toBe([true])
            ->and(LifetimeState::$released)->toBe(1)
            ->and(LifetimeState::$owner?->get())->toBeNull();
    });
})->with(['parameter resolver' => false, 'attribute definition' => true]);
