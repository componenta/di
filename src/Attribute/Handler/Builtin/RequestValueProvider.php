<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\ConfigPath;
use Componenta\Config\DefaultValue;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\Handler\ValueProviderHandlerInterface;
use Componenta\DI\Attribute\Handler\ValueProviderPrecedence;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Attribute\UploadedFile;
use Componenta\DI\Exception\RequestDataConflictException;
use Componenta\DI\FactoryInterface;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\Parameter\Request\RequestDataConflictPolicy;
use Componenta\DI\Resolver\Parameter\Request\RequestMappingPipeline;
use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\Validation\Context;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionNamedType;

/** Owns all PSR-7 value providers; request attributes remain passive DTOs. */
final class RequestValueProvider implements ValueProviderHandlerInterface
{
    public ValueProviderPrecedence $precedence {
        get => ValueProviderPrecedence::ProviderFirst;
    }

    private readonly RequestMappingPipeline $mapping;

    public function __construct(
        private readonly FactoryInterface $factory,
        private readonly ContainerInterface $container,
        ?RequestMappingPipeline $mapping = null,
    ) {
        $this->mapping = $mapping ?? new RequestMappingPipeline();
    }

    public function provide(object $attribute, ValueTargetInterface $target, ValueContext $context): mixed
    {
        $request = $context->resolution->request()
            ?? throw new LogicException(sprintf('%s requires a PSR-7 ServerRequestInterface.', $attribute::class));

        return match (true) {
            $attribute instanceof Header => $this->header($attribute, $request),
            $attribute instanceof Cookie => $this->cookie($attribute, $request),
            $attribute instanceof QueryParam => $this->query($attribute, $target, $request),
            $attribute instanceof PayloadParam => $this->payload($attribute, $target, $request),
            $attribute instanceof RequestAttribute => $this->requestAttribute($attribute, $target, $request),
            $attribute instanceof ServerParam => $this->server($attribute, $request),
            $attribute instanceof UploadedFile => $this->file($attribute, $request),
            $attribute instanceof MapRequest => $this->mapRequest($attribute, $target, $request, $context),
            default => throw new LogicException(sprintf('Unsupported request provider attribute %s.', $attribute::class)),
        };
    }

    private function header(Header $attribute, ServerRequestInterface $request): mixed
    {
        return $request->hasHeader($attribute->name)
            ? $request->getHeaderLine($attribute->name)
            : $this->requiredOrDefault('header', $attribute->name, $attribute->default);
    }

    private function cookie(Cookie $attribute, ServerRequestInterface $request): mixed
    {
        $values = $request->getCookieParams();

        return array_key_exists($attribute->name, $values)
            ? $values[$attribute->name]
            : $this->requiredOrDefault('cookie', $attribute->name, $attribute->default);
    }

    private function query(
        QueryParam $attribute,
        ValueTargetInterface $target,
        ServerRequestInterface $request,
    ): mixed {
        $name = $attribute->name ?? $target->name;
        $values = $request->getQueryParams();

        return array_key_exists($name, $values)
            ? $values[$name]
            : $this->requiredOrDefault('query parameter', $name, $attribute->default);
    }

    private function payload(
        PayloadParam $attribute,
        ValueTargetInterface $target,
        ServerRequestInterface $request,
    ): mixed {
        $values = $this->payloadArray($request);
        $name = $attribute->name ?? $target->name;

        if ($name instanceof ConfigPath) {
            $result = $this->path($values, array_values($name->toArray()));

            return $result['found']
                ? $result['value']
                : $this->requiredOrDefault('payload parameter', $name->value, $attribute->default);
        }

        return array_key_exists($name, $values)
            ? $values[$name]
            : $this->requiredOrDefault('payload parameter', $name, $attribute->default);
    }

    private function requestAttribute(
        RequestAttribute $attribute,
        ValueTargetInterface $target,
        ServerRequestInterface $request,
    ): mixed {
        $name = $attribute->name ?? $target->name;
        $values = $request->getAttributes();

        return array_key_exists($name, $values)
            ? $values[$name]
            : $this->requiredOrDefault('request attribute', $name, $attribute->default);
    }

    private function server(ServerParam $attribute, ServerRequestInterface $request): mixed
    {
        $values = $request->getServerParams();

        return array_key_exists($attribute->name, $values)
            ? $values[$attribute->name]
            : $this->requiredOrDefault('server parameter', $attribute->name, $attribute->default);
    }

    private function file(UploadedFile $attribute, ServerRequestInterface $request): mixed
    {
        $current = $request->getUploadedFiles();

        foreach (explode('.', $attribute->name) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current instanceof UploadedFileInterface || is_array($current)
            ? $current
            : null;
    }

    private function mapRequest(
        MapRequest $attribute,
        ValueTargetInterface $target,
        ServerRequestInterface $request,
        ValueContext $context,
    ): mixed {
        $rawData = $this->merge(
            $this->mapRequestSources($attribute, $request),
            $attribute->conflictPolicy,
        );

        $type = $target->type;
        $isArray = $type instanceof ReflectionNamedType
            && $type->isBuiltin()
            && $type->getName() === 'array';
        $class = $isArray ? null : $target->className;

        if (!$isArray && $class === null) {
            throw new LogicException('#[MapRequest] requires an array or a single class/interface target type.');
        }

        if ($class !== null) {
            $this->assertNamedDtoData($rawData);
            // Keep the v4 contract: validation sees transport data before aliases,
            // casts, defaults, sort normalization or exclusions can hide it.
            $this->validate($class, $rawData);
        }

        $data = $this->mapping->run(
            $rawData,
            $attribute->map,
            $attribute->defaults,
            $attribute->cast,
            $attribute->sortMap,
            $attribute->exclude,
            $this->casters($attribute),
        );

        if ($isArray) {
            return $data;
        }

        /** @var class-string $class */
        $this->assertNamedDtoData($data);

        return $this->factory->make(
            $class,
            ResolutionContext::mapped($data, $request, $context->resolution->trusted),
        );
    }

    /**
     * @return array<string, array<string|int, mixed>>
     */
    private function mapRequestSources(MapRequest $attribute, ServerRequestInterface $request): array
    {
        /** @var array<string, array<string|int, mixed>> $sources */
        $sources = [];
        /** @var array<string, true> $seen */
        $seen = [];

        if ($attribute->attributes !== []
            && !in_array(RequestDataSource::Attributes, $attribute->sources, true)
        ) {
            $sources['request attributes'] = $this->select(
                $request->getAttributes(),
                $attribute->attributes,
                'request attributes',
            );
        }
        if ($attribute->files !== []
            && !in_array(RequestDataSource::Files, $attribute->sources, true)
        ) {
            $sources['uploaded files'] = $this->select(
                $request->getUploadedFiles(),
                $attribute->files,
                'uploaded files',
            );
        }

        foreach ($attribute->sources as $source) {
            if (!$source instanceof RequestDataSource) {
                throw new InvalidArgumentException(sprintf(
                    'MapRequest sources must contain %s values; got %s.',
                    RequestDataSource::class,
                    get_debug_type($source),
                ));
            }
            if (isset($seen[$source->value])) {
                throw new InvalidArgumentException(sprintf(
                    'MapRequest source "%s" is declared more than once.',
                    $source->value,
                ));
            }
            $seen[$source->value] = true;

            $values = $this->source($source, $request);
            if ($source === RequestDataSource::Attributes && $attribute->attributes !== []) {
                $values = $this->select($values, $attribute->attributes, 'request attributes');
            } elseif ($source === RequestDataSource::Files && $attribute->files !== []) {
                $values = $this->select($values, $attribute->files, 'uploaded files');
            }

            $sources[$source->value] = $values;
        }

        return $sources;
    }

    /**
     * @param array<string|int, mixed> $values
     * @param list<string> $selectors
     * @return array<string|int, mixed>
     */
    private function select(array $values, array $selectors, string $kind): array
    {
        if ($selectors === [MapRequest::ALL]) {
            return $values;
        }
        if (in_array(MapRequest::ALL, $selectors, true)) {
            throw new InvalidArgumentException(sprintf(
                'MapRequest wildcard for %s must be the only selector.',
                $kind,
            ));
        }

        $selected = [];
        foreach ($selectors as $selector) {
            if (!is_string($selector) || $selector === '') {
                throw new InvalidArgumentException(sprintf(
                    'MapRequest %s selectors must be non-empty strings.',
                    $kind,
                ));
            }
            if (array_key_exists($selector, $values)) {
                $selected[$selector] = $values[$selector];
            }
        }
        return $selected;
    }

    /** @return array<string|int, mixed> */
    private function source(RequestDataSource $source, ServerRequestInterface $request): array
    {
        return match ($source) {
            RequestDataSource::Payload => $this->payloadArray($request),
            RequestDataSource::Query => $request->getQueryParams(),
            RequestDataSource::Headers => array_map(
                static fn(array $values): string => implode(', ', $values),
                $request->getHeaders(),
            ),
            RequestDataSource::Cookies => $request->getCookieParams(),
            RequestDataSource::Attributes => $request->getAttributes(),
            RequestDataSource::Server => $request->getServerParams(),
            RequestDataSource::Files => $request->getUploadedFiles(),
        };
    }

    /** @return array<string|int, mixed> */
    private function payloadArray(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return match (true) {
            $body === null => [],
            is_array($body) => $body,
            is_object($body) => get_object_vars($body),
            default => throw new InvalidArgumentException(sprintf(
                'Parsed request body must be array, object or null; got %s.',
                get_debug_type($body),
            )),
        };
    }

    /**
     * @param array<string, array<string|int, mixed>> $sources
     * @return array<string|int, mixed>
     */
    private function merge(array $sources, RequestDataConflictPolicy $policy): array
    {
        $data = [];
        /** @var array<string|int, string> $owners */
        $owners = [];

        foreach ($sources as $source => $values) {
            foreach ($values as $key => $value) {
                if (!array_key_exists($key, $data)) {
                    $data[$key] = $value;
                    $owners[$key] = $source;
                    continue;
                }

                if ($data[$key] === $value || $policy === RequestDataConflictPolicy::FirstWins) {
                    continue;
                }

                throw new RequestDataConflictException($key, $owners[$key], $source);
            }
        }

        return $data;
    }

    private function casters(MapRequest $attribute): ?CasterProviderInterface
    {
        if ($attribute->cast === []) {
            return null;
        }
        if (!$this->container->has(CasterProviderInterface::class)) {
            throw new LogicException(sprintf(
                '#[MapRequest] defines casts but %s is not configured.',
                CasterProviderInterface::class,
            ));
        }

        $provider = $this->container->get(CasterProviderInterface::class);
        if (!$provider instanceof CasterProviderInterface) {
            throw new LogicException(sprintf(
                'Container entry %s has an invalid type.',
                CasterProviderInterface::class,
            ));
        }
        return $provider;
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $data
     */
    private function validate(string $class, array $data): void
    {
        if (!$this->container->has(ValidationProviderInterface::class)) {
            return;
        }

        $provider = $this->container->get(ValidationProviderInterface::class);
        if (!$provider instanceof ValidationProviderInterface) {
            throw new LogicException(sprintf(
                'Container entry %s has an invalid type.',
                ValidationProviderInterface::class,
            ));
        }

        $provider->provide($class)?->validate(
            $data,
            new Context([ContextInterface::THROW_ON_FAILURE_ATTRIBUTE => true]),
        );
    }

    /** @param array<string|int, mixed> $data */
    private function assertNamedDtoData(array $data): void
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Class-typed request mapping accepts only named string keys.');
            }
        }
    }

    private function requiredOrDefault(string $kind, string $name, mixed $default): mixed
    {
        if ($default !== DefaultValue::None) {
            return $default;
        }

        throw new InvalidArgumentException(sprintf('Required %s "%s" is missing.', $kind, $name));
    }

    /**
     * @param array<string|int, mixed> $data
     * @param list<string> $segments
     * @return array{found: bool, value: mixed}
     */
    private function path(array $data, array $segments): array
    {
        $current = $data;
        foreach ($segments as $segment) {
            if (!array_key_exists($segment, $current)) {
                return ['found' => false, 'value' => null];
            }

            $value = $current[$segment];
            if ($segment !== $segments[array_key_last($segments)] && !is_array($value)) {
                return ['found' => false, 'value' => null];
            }
            $current = is_array($value) ? $value : [];
        }

        if ($segments === []) {
            return ['found' => true, 'value' => $data];
        }

        $last = $segments[array_key_last($segments)];
        $parent = $data;
        foreach (array_slice($segments, 0, -1) as $segment) {
            $value = $parent[$segment] ?? null;
            if (!is_array($value)) {
                return ['found' => false, 'value' => null];
            }
            $parent = $value;
        }

        return array_key_exists($last, $parent)
            ? ['found' => true, 'value' => $parent[$last]]
            : ['found' => false, 'value' => null];
    }
}
