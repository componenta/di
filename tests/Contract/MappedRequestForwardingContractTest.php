<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\MappedRequestForwarding;

use Attribute;
use Closure;
use Componenta\Caster\NullCasterProvider;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Container;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\ProxyFactory;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Entry\CompositeResolver;
use Componenta\DI\Resolver\Entry\EntryParameters;
use Componenta\DI\Resolver\Entry\EntryParametersFactoryInterface;
use Componenta\DI\Resolver\Entry\EntryResolverInterface;
use Componenta\DI\Resolver\Entry\ReflectionResolver;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use Fiber;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use ReflectionParameter;
use WeakReference;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Source implements ParameterSourceAttributeInterface {}

final readonly class LiteralDto
{
    public function __construct(public string $value) {}
}

abstract class DeferredDto
{
    public static int $constructions = 0;

    public function __construct(public string $value)
    {
        ++self::$constructions;
    }
}

final readonly class SourceLoader implements ParameterResolverInterface
{
    public function __construct(private string $alias) {}

    public function supports(ParameterTarget $target): bool
    {
        return $target->name === 'discovery';
    }

    /** @return array{int, string} */
    public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
    {
        class_alias(Source::class, $this->alias);
        return [$target->position, 'active'];
    }
}

final class ForwardingResolver implements EntryResolverInterface
{
    /** @var array<string|int, mixed> */
    public array $arguments = [];
    public ?EntryParameters $input = null;

    /** @param (Closure(string, array<string|int,mixed>): void)|null $beforeResolve */
    public function __construct(
        private readonly EntryResolverInterface $inner,
        private readonly ?Closure $beforeResolve = null,
    ) {}

    public function can(string $id): bool
    {
        return $this->inner->can($id);
    }

    public function resolve(string $id, EntryParameters $params): mixed
    {
        $this->arguments = $params->values;
        $this->input = $params;
        $this->beforeResolve?->__invoke($id, $params->values);
        return $this->inner->resolve($id, $params);
    }
}

final readonly class ForwardingFactory implements EntryParametersFactoryInterface
{
    public function __construct(private FactoryInterface $inner, private bool $forwardRequest = true) {}

    /** @param array<string|int, mixed> $params */
    public function make(string $entry, array $params = []): object
    {
        if (!$this->forwardRequest) {
            unset($params[ServerRequestInterface::class]);
        }
        return $this->inner->make($entry, $params);
    }

    public function makeEntry(string $entry, EntryParameters $parameters): object
    {
        if (!$this->forwardRequest) {
            $parameters = $parameters->without(ServerRequestInterface::class);
        }
        return $parameters->forwardTo($this->inner, $entry);
    }
}

function mapRequest(FactoryInterface $factory, ReflectionParameter $parameter, ServerRequestInterface $request): mixed
{
    return new RequestAttributeHandler($factory, new NullCasterProvider())->resolveParameter(
        new MapRequest(),
        new ParameterTarget($parameter),
        new ParameterResolutionContext([ServerRequestInterface::class => $request]),
        new AttributePlan($parameter, []),
        ParameterAttributeValue::unresolved(),
    )->value;
}

test('a forwarding entry resolver preserves checks for a late mapped parameter source', function (bool $forwardRequest): void {
    $suffix = bin2hex(random_bytes(5));
    $source = 'LateSource_' . $suffix;
    $class = 'ForwardedDto_' . $suffix;
    $plans = new AttributePlanBuilder(new AttributeDefinitionRegistry());
    $parameters = new ParametersResolver($plans);
    $parameters->add(new SourceLoader(__NAMESPACE__ . '\\' . $source), 100);
    $parameters->add(new AttributeMatchingParameterResolver(
        Source::class,
        static fn(ParameterTarget $target): array => [$target->position, 'resolved source'],
    ), 50);
    $parameters->add(new ArrayResolver());
    $parameters->seal();

    $pipeline = new ObjectPipeline(new AttributeProcessor($plans), $parameters, new ProxyFactory());
    $entryResolver = new ForwardingResolver(new CompositeResolver(new ReflectionResolver($pipeline)));
    $container = new Container($entryResolver, (new ContainerBuilder())->build());
    $factory = new ForwardingFactory($container, $forwardRequest);
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'mapped value']);
    $target = eval(sprintf(
        'namespace %s; final readonly class %s { public function __construct(public string $discovery, #[%s] public string $value) {} } return static fn(%s $dto): object => $dto;',
        __NAMESPACE__,
        $class,
        $source,
        $class,
    ));
    if (!$target instanceof Closure) {
        throw new LogicException('Expected the mapped DTO target.');
    }
    $parameter = new ReflectionParameter($target, 0);
    $forwardedParameters = ['value' => 'mapped value'];
    if ($forwardRequest) {
        $forwardedParameters[ServerRequestInterface::class] = $request;
    }

    expect(fn() => mapRequest($factory, $parameter, $request))
        ->toThrow(RequestParameterSourceConflictException::class, 'value')
        ->and($entryResolver->arguments)->toBe($forwardedParameters);
})->with(['request forwarded' => true, 'request omitted by factory' => false]);

test('a request mapper passes only public arguments to an entry resolver', function (): void {
    $existing = (new ContainerBuilder())->assemble();
    $entryResolver = new ForwardingResolver(new ReflectionResolver($existing[1]->objects));
    $factory = new ForwardingFactory(new Container($entryResolver, $existing[0]));
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'body']);
    $parameter = new ReflectionParameter(static fn(LiteralDto $dto): object => $dto, 0);

    expect(mapRequest($factory, $parameter, $request))->toEqual(new LiteralDto('body'))
        ->and($entryResolver->arguments)->toBe(['value' => 'body', ServerRequestInterface::class => $request]);

    $input = $entryResolver->input ?? throw new LogicException('Expected entry parameters.');
    $methods = get_class_methods($input);
    sort($methods);
    $publicProperties = new \ReflectionObject($input)->getProperties(\ReflectionProperty::IS_PUBLIC);
    expect($methods)->toBe(['forwardTo', 'without'])
        ->and(array_map(static fn(\ReflectionProperty $property): string => $property->getName(), $publicProperties))->toBe(['values']);
});

test('an opaque factory receives application arguments for a mapped DTO without a DI transport argument', function (): void {
    $factory = new class () implements FactoryInterface {
        public function make(string $entry, array $params = []): object
        {
            unset($params[ServerRequestInterface::class]);
            return new $entry(...$params);
        }
    };
    $request = new ServerRequest('POST', '/')->withParsedBody(['value' => 'body']);
    $parameter = new ReflectionParameter(static fn(LiteralDto $dto): LiteralDto => $dto, 0);

    expect(mapRequest($factory, $parameter, $request))->toEqual(new LiteralDto('body'));
});

test('a lazy DTO retains mapped parameters through a deferred or delegated entry resolver', function (string $route, string $strategy): void {
    $suffix = bin2hex(random_bytes(5));
    $class = 'DeferredDto_' . $suffix;
    $attribute = 'DeferredSource_' . $suffix;
    $target = eval(sprintf(
        'namespace %s; #[\\Componenta\\DI\\Attribute\\%s] final class %s extends DeferredDto { public function __construct(#[%s("trusted.value")] string $value) { parent::__construct($value); } } return static fn(%s $dto): object => $dto;',
        __NAMESPACE__,
        $strategy,
        $class,
        $attribute,
        $class,
    ));
    if (!$target instanceof Closure) {
        throw new LogicException('Expected the mapped DTO target.');
    }

    [$inner, $runtime] = new ContainerBuilder()->addService('trusted.value', 'configured')->assemble();
    $resolver = new class ($inner, $runtime->objects, $route) implements EntryResolverInterface {
        public function __construct(private Container $inner, private ObjectPipeline $objects, private string $route) {}

        public function can(string $id): bool
        {
            return is_a($id, DeferredDto::class, true);
        }

        public function resolve(string $id, EntryParameters $params): mixed
        {
            if (!is_a($id, DeferredDto::class, true)) {
                throw new LogicException('Expected a lazy DTO class.');
            }
            return $this->route === 'delegated'
                ? $params->forwardTo($this->inner, $id)
                : $this->objects->create($id, resolveParameters: static fn(): EntryParameters => $params);
        }
    };
    $request = new ServerRequest('POST', '/')->withParsedBody(['value' => 'body']);
    $container = new Container($resolver, $inner);
    DeferredDto::$constructions = 0;
    $dto = mapRequest($container, new ReflectionParameter($target, 0), $request);
    if (!$dto instanceof DeferredDto) {
        throw new LogicException('Expected the mapped DTO.');
    }
    expect(new ReflectionClass($dto)->isUninitializedLazyObject($dto))->toBeTrue();

    class_alias(EntryId::class, __NAMESPACE__ . '\\' . $attribute);

    expect(static fn(): string => $dto->value)->toThrow(RequestParameterSourceConflictException::class, 'value')
        ->and(DeferredDto::$constructions)->toBe(0);
})->with(['delegated', 'deferred'])->with(['Lazy', 'Proxy']);

test('nested unmapped construction of the same DTO keeps its explicit parameters', function (): void {
    [$inner, $runtime] = (new ContainerBuilder())->assemble();
    $reflection = new ReflectionResolver($runtime->objects);
    $nested = null;
    $entryResolver = new ForwardingResolver($reflection, static function (string $id, array $params) use ($inner, &$nested): void {
        if ($id === '') {
            throw new LogicException('Expected a mapped entry ID.');
        }
        class_alias(Source::class, __NAMESPACE__ . '\\NestedSource');
        $nested = $inner->make($id, $params);
    });
    $container = new Container($entryResolver, $inner);
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'body']);
    $target = eval(sprintf(
        'namespace %s; final readonly class NestedDto { public function __construct(#[NestedSource] public string $value) {} } return static fn(NestedDto $dto): object => $dto;',
        __NAMESPACE__,
    ));
    $class = __NAMESPACE__ . '\\NestedDto';
    if (!$target instanceof Closure || !class_exists($class, false)) {
        throw new LogicException('Expected the mapped DTO target.');
    }
    $parameter = new ReflectionParameter($target, 0);

    expect(fn() => mapRequest($container, $parameter, $request))
        ->toThrow(RequestParameterSourceConflictException::class, 'value')
        ->and($nested === null ? null : $nested::class)->toBe($class)
        ->and($reflection->resolve($class, $entryResolver->arguments))->toEqual($nested);
});

test('mapped provenance is private to an entry resolution suspended in a fiber', function (): void {
    [$inner, $runtime] = (new ContainerBuilder())->assemble();
    $reflection = new ReflectionResolver($runtime->objects);
    $entryResolver = new ForwardingResolver($reflection, static function (): void {
        Fiber::suspend('mapped resolution');
    });
    $container = new Container($entryResolver, $inner);
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'body']);
    $target = eval(sprintf(
        'namespace %s; final readonly class FiberDto { public function __construct(#[FiberSource] public string $value) {} } return static fn(FiberDto $dto): object => $dto;',
        __NAMESPACE__,
    ));
    if (!$target instanceof Closure) {
        throw new LogicException('Expected the mapped DTO target.');
    }
    $parameter = new ReflectionParameter($target, 0);
    $fiber = new Fiber(static fn(): mixed => mapRequest($container, $parameter, $request));

    expect($fiber->start())->toBe('mapped resolution');
    class_alias(Source::class, __NAMESPACE__ . '\\FiberSource');
    expect($reflection->resolve(__NAMESPACE__ . '\\FiberDto', $entryResolver->arguments))->toBeObject()
        ->and(fn() => $fiber->resume())->toThrow(RequestParameterSourceConflictException::class, 'value')
        ->and($fiber->isTerminated())->toBeTrue();
});

test('an abandoned mapped resolution can release a fiber referenced by its request', function (): void {
    $inner = (new ContainerBuilder())->build();
    $entryResolver = new class () implements EntryResolverInterface {
        public function can(string $id): bool
        {
            return $id === LiteralDto::class;
        }

        public function resolve(string $id, EntryParameters $params): mixed
        {
            Fiber::suspend('mapped resolution');
            return new LiteralDto('body');
        }
    };
    $container = new Container($entryResolver, $inner);
    $parameter = new ReflectionParameter(static fn(LiteralDto $dto): object => $dto, 0);
    $fiber = new Fiber(static fn(ServerRequestInterface $request): mixed => mapRequest($container, $parameter, $request));
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['value' => 'body'])->withAttribute('worker', $fiber);
    expect($fiber->start($request))->toBe('mapped resolution');

    $reference = WeakReference::create($fiber);
    unset($request, $fiber);
    gc_collect_cycles();

    expect($reference->get())->toBeNull();
});
