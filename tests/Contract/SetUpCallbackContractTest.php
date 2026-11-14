<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\SetUpCallback;

use Attribute;
use Closure;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\AttributeCallbackInvoker;
use Componenta\DI\Internal\Resolver\Entry\ObjectResolutionParameterStore;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Entry\SetUp\SetUpValueUnwrapperInterface;
use Componenta\DI\Resolver\Entry\SetUpRunner;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionParameter;

#[Attribute]
final class UnusedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

function setupBuilder(bool $uncached): ContainerBuilder
{
    $builder = new ContainerBuilder();
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UnusedMarker::class, rules: [new NoOpRule()]));
    }
    return $builder;
}

final readonly class Dependency
{
    public function __construct(public string $value = 'dependency') {}
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
readonly class CallbackHook extends SetUp
{
    public function __construct(string $step)
    {
        parent::__construct(static function (HookTarget $instance, Dependency $dependency) use ($step): object {
            $instance->steps[] = $step . ':' . $instance->injected->value . ':' . $dependency->value;
            return new \stdClass();
        });
    }
}

#[CallbackHook('first'), SetUp('middle'), CallbackHook('last')]
class HookTarget
{
    /** @var list<string> */
    public array $steps = [];

    #[Inject]
    public Dependency $injected;

    public function middle(): void
    {
        $this->steps[] = 'middle:' . $this->injected->value;
    }
}

#[Lazy, CallbackHook('lazy')]
final class LazyHookTarget extends HookTarget {}

test('callback setup runs after committed properties in declaration order and ignores returned objects', function (bool $uncached): void {
    $container = setupBuilder($uncached)->build();
    $entry = $container->get(HookTarget::class);

    expect($entry)->toBeInstanceOf(HookTarget::class)
        ->and($entry->steps)->toBe(['first:dependency:dependency', 'middle:dependency', 'last:dependency:dependency'])
        ->and($container->get(HookTarget::class))->toBe($entry)
        ->and($container->make(HookTarget::class))->not->toBe($entry);
})->with(['cached' => false, 'non-memoized' => true]);

test('callback setup respects lazy object initialization', function (): void {
    $container = (new ContainerBuilder())->build();
    $entry = $container->make(LazyHookTarget::class);

    expect(new ReflectionClass($entry)->isUninitializedLazyObject($entry))->toBeTrue()
        ->and($entry->steps)->toBe(['lazy:dependency:dependency']);
});

final readonly class Descriptor
{
    public function __construct(public mixed $value) {}
}

final class ObservingUnwrapper implements SetUpValueUnwrapperInterface
{
    /** @var list<array{string,?string,?int}> */
    public array $observed = [];

    public function supports(mixed $value): bool
    {
        return $value instanceof Descriptor;
    }

    public function unwrap(mixed $value, string $key, ?ReflectionParameter $parameter = null): mixed
    {
        if (!$value instanceof Descriptor) {
            throw new \LogicException('Expected a descriptor.');
        }
        $this->observed[] = [$key, $parameter?->getName(), $parameter?->getPosition()];
        return $value->value;
    }
}

final class DirectTarget
{
    /** @var list<mixed> */
    public array $seen = [];
}

/**
 * @param array<string|int,mixed> $parameters
 * @return array{SetUpRunner,ObjectCreationContext,ObservingUnwrapper,DirectTarget}
 */
function directRunner(array $parameters = []): array
{
    $container = (new ContainerBuilder())->build();
    $store = new ObjectResolutionParameterStore();
    $unwrapper = new ObservingUnwrapper();
    $entry = new DirectTarget();
    $context = setupContext($entry, $parameters);
    $store->attach($context, $parameters);
    $runner = new SetUpRunner($container, $store, new AttributeCallbackInvoker($container->get(ParametersResolver::class), $container), $unwrapper);
    return [$runner, $context, $unwrapper, $entry];
}

/** @param array<string|int,mixed> $parameters */
function setupContext(object $entry, array $parameters): ObjectCreationContext
{
    $context = new ObjectCreationContext(new ReflectionClass($entry), $parameters);
    $context->initialize($entry);
    return $context;
}

test('callback setup protects its object and preserves ambient suffix positions and typed descriptors', function (): void {
    [$runner, $context, $unwrapper, $entry] = directRunner([0 => 'wrong object', 'semantic' => 'wrong object', 1 => 'ambient position']);
    $dependency = new Dependency('type-key');
    $callback = static function (DirectTarget $semantic, string $label, Dependency $dependency): void {
        $semantic->seen = [$semantic, $label, $dependency->value, func_num_args()];
    };
    $attribute = new SetUp($callback, [
        'unused' => new Descriptor('unused'),
        Dependency::class => new Descriptor($dependency),
    ]);

    $runner->handle($attribute, $context->class, $context);

    expect($entry->seen)->toBe([$entry, 'ambient position', 'type-key', 3])
        ->and($unwrapper->observed)->toBe([['unused', null, null], [Dependency::class, null, null]]);
});

test('callback setup passes actual suffix reflection metadata to matching named unwrappers', function (): void {
    [$runner, $context, $unwrapper, $entry] = directRunner(['label' => 'ambient']);
    $attribute = new SetUp(static function (DirectTarget $instance, string $label): void {
        $instance->seen = [$label];
    }, ['label' => new Descriptor('explicit')]);

    $runner->handle($attribute, $context->class, $context);

    expect($entry->seen)->toBe(['explicit'])
        ->and($unwrapper->observed)->toBe([['label', 'label', 1]]);
});

test('callback setup rejects reserved or integer explicit keys before any unwrapping', function (string|int $key): void {
    [$runner, $context, $unwrapper, $entry] = directRunner();
    $callback = static function (DirectTarget $semantic, string $label = 'default'): void {
        $semantic->seen[] = $label;
    };
    $attribute = new ReflectionClass(SetUp::class)->newInstance($callback, [
        'label' => new Descriptor('must not unwrap'),
        $key => new Descriptor('invalid'),
    ]);

    expect(fn() => $runner->handle($attribute, $context->class, $context))
        ->toThrow(InvalidConfigurationException::class)
        ->and($unwrapper->observed)->toBe([])
        ->and($entry->seen)->toBe([]);
})->with([0, 1, -1, 'semantic']);

test('zero-declaration callback setup still validates and unwraps named values before an empty native call', function (): void {
    [$runner, $context, $unwrapper, $entry] = directRunner();
    $attribute = new SetUp(static function () use ($entry): void {
        $entry->seen = [func_num_args()];
    }, [
        'unused' => new Descriptor('resolved but unconsumed'),
    ]);

    $runner->handle($attribute, $context->class, $context);

    expect($entry->seen)->toBe([0])
        ->and($unwrapper->observed)->toBe([['unused', null, null]]);
});

test('zero-declaration callback setup rejects integer keys before unwrapping', function (): void {
    [$runner, $context, $unwrapper] = directRunner();
    $attribute = new ReflectionClass(SetUp::class)->newInstance(static function (): void {}, [
        'unused' => new Descriptor('must not unwrap'),
        0 => new Descriptor('invalid'),
    ]);

    expect(fn() => $runner->handle($attribute, $context->class, $context))
        ->toThrow(InvalidConfigurationException::class)
        ->and($unwrapper->observed)->toBe([]);
});

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ClasslessHook extends SetUp
{
    public function __construct(string $kind)
    {
        parent::__construct($kind === 'entry' ? setupEntry(...) : setupEnvironment(...));
    }
}

final class RequestLog
{
    public static int $calls = 0;
}

function setupEntry(object $instance, #[EntryId('trusted.dependency')] Dependency $dependency): void
{
    ++RequestLog::$calls;
}

function setupEnvironment(object $instance, #[Env('CALLBACK_LABEL')] string $label): void
{
    ++RequestLog::$calls;
}

#[ClasslessHook('entry')]
final class EntryRequestTarget {}

#[ClasslessHook('environment')]
final class EnvironmentRequestTarget {}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FunctionHook extends SetUp
{
    /** @param callable-string $function */
    public function __construct(string $function)
    {
        parent::__construct(Closure::fromCallable($function));
    }
}

final class LoadingDependency {}

test('classless setup callbacks keep source provenance after a dependency loads a suffix source', function (bool $uncached, string $key): void {
    $suffix = bin2hex(random_bytes(5));
    $source = 'LateSource_' . $suffix;
    $function = 'lateSetup_' . $suffix;
    $class = 'LateRequestTarget_' . $suffix;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        function %s(object $instance, LoadingDependency $trigger, #[%s('trusted.dependency')] Dependency $dependency): void {
            ++RequestLog::$calls;
        }
        #[FunctionHook(__NAMESPACE__ . '\\%s')]
        final class %s {}
        PHP,
        __NAMESPACE__,
        $function,
        $source,
        $function,
        $class,
    ));
    $calls = 0;
    $container = setupBuilder($uncached)
        ->addService('trusted.dependency', new Dependency('trusted'))
        ->addFactory(LoadingDependency::class, static function () use ($source, &$calls): LoadingDependency {
            ++$calls;
            class_alias(EntryId::class, __NAMESPACE__ . '\\' . $source);
            return new LoadingDependency();
        })
        ->build();
    $callback = eval(sprintf(
        'namespace %s; return static fn(#[\\Componenta\\DI\\Attribute\\MapRequestPayload] %s $dto): object => $dto;',
        __NAMESPACE__,
        $class,
    ));
    $request = new ServerRequest('POST', '/callbacks')->withParsedBody([$key => 'untrusted']);
    RequestLog::$calls = 0;

    try {
        $container->call($callback, [ServerRequestInterface::class => $request]);
        \PHPUnit\Framework\Assert::fail('A late source must retain its diagnostic owner and provenance.');
    } catch (RequestParameterSourceConflictException $error) {
        expect($error->dtoClass)->toBe(__NAMESPACE__ . '\\' . $class)
            ->and($error->key)->toBe($key)
            ->and($calls)->toBe(1)
            ->and(RequestLog::$calls)->toBe(0);
    }
})->with(['cached' => false, 'non-memoized' => true])
    ->with(['dependency', Dependency::class]);

final class PublicContextResolver implements ParameterResolverInterface
{
    /** @var array<string|int,mixed> */
    public array $provided = [];

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'inspectContext';
    }

    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        $this->provided = $context->provided;
        return [$target->position, 'observed'];
    }
}

function inspectSetupContext(object $instance, string $inspectContext): void {}

#[FunctionHook(__NAMESPACE__ . '\\inspectSetupContext')]
final class ContextRequestTarget {}

test('classless setup suffix extensions receive public request context without provenance or owner transport keys', function (): void {
    $resolver = new PublicContextResolver();
    $container = (new ContainerBuilder())->addParameterResolver($resolver, 2000)->build();
    $request = new ServerRequest('POST', '/callbacks')->withParsedBody(['visible' => 'public']);

    $entry = $container->call(static fn(#[MapRequestPayload] ContextRequestTarget $dto): object => $dto, [ServerRequestInterface::class => $request]);

    expect($entry)->toBeInstanceOf(ContextRequestTarget::class)
        ->and($resolver->provided)->toBe(['visible' => 'public', ServerRequestInterface::class => $request]);
});

test('mapped DTO provenance guards classless setup callback sources by name and type key', function (string $kind, string $key): void {
    $container = (new ContainerBuilder(new Config([], new Environment(['CALLBACK_LABEL' => 'trusted']))))
        ->addService('trusted.dependency', new Dependency('trusted'))
        ->build();
    $request = new ServerRequest('POST', '/callbacks')->withParsedBody([$key => 'untrusted']);
    $callback = $kind === 'entry'
        ? static fn(#[MapRequestPayload] EntryRequestTarget $dto): object => $dto
        : static fn(#[MapRequestPayload] EnvironmentRequestTarget $dto): object => $dto;
    RequestLog::$calls = 0;

    try {
        $container->call($callback, [ServerRequestInterface::class => $request]);
        \PHPUnit\Framework\Assert::fail('Mapped request values must not shadow a callback source.');
    } catch (RequestParameterSourceConflictException $error) {
        expect($error->dtoClass)->toBe($kind === 'entry' ? EntryRequestTarget::class : EnvironmentRequestTarget::class)
            ->and($error->key)->toBe($key)
            ->and(RequestLog::$calls)->toBe(0);
    }
})->with([
    ['entry', 'dependency'],
    ['entry', Dependency::class],
    ['environment', 'label'],
]);

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class FailingHook extends SetUp
{
    public function __construct()
    {
        parent::__construct(static function (object $instance): void {
            ++FailureLog::$attempts;
            if (FailureLog::$attempts === 1) {
                throw FailureLog::$cause;
            }
        });
    }
}

final class FailureLog
{
    public static int $attempts = 0;
    public static \DomainException $cause;
}

#[FailingHook]
final class RetriedTarget {}

test('a failed setup callback preserves its cause and is not published as a shared service', function (): void {
    FailureLog::$attempts = 0;
    FailureLog::$cause = new \DomainException('Setup failed.');
    $container = (new ContainerBuilder())->build();

    try {
        $container->get(RetriedTarget::class);
        \PHPUnit\Framework\Assert::fail('The first setup attempt must fail.');
    } catch (ResolutionException $error) {
        expect($error->serviceId)->toBe(RetriedTarget::class)
            ->and($error->getPrevious())->toBe(FailureLog::$cause);
    }
    $entry = $container->get(RetriedTarget::class);
    expect($entry)->toBeInstanceOf(RetriedTarget::class)
        ->and($container->get(RetriedTarget::class))->toBe($entry)
        ->and(FailureLog::$attempts)->toBe(2);
});

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ForwardedHook extends SetUp
{
    /** @param array<string,mixed> $params */
    public function __construct(string $method, array $params = [])
    {
        parent::__construct(method: $method, params: $params);
    }
}

#[ForwardedHook(method: 'initialize', params: ['label' => 'forwarded'])]
final class ForwardedTarget
{
    public string $label;

    public function initialize(string $label): void
    {
        $this->label = $label;
    }
}

test('constructor only setup subclasses preserve named method and params arguments', function (): void {
    $container = (new ContainerBuilder())->build();

    expect($container->make(ForwardedTarget::class)->label)->toBe('forwarded');
});
