<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Attribute;
use Componenta\Caster\CasterInterface;
use Componenta\Caster\CasterProviderInterface;
use Componenta\Config\Config;
use Componenta\Config\ConfigPath;
use Componenta\Config\ContainerValue;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Config as ConfigValue;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\CurrentUri;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\MapCookies;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\MapRequestAttributes;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\MapServerParams;
use Componenta\DI\Attribute\MapUploadedFiles;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\RequestDataSource;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Attribute\Transform;
use Componenta\DI\Attribute\UploadedFile;
use Componenta\DI\ConfigKey;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\Validation\ContextInterface;
use Componenta\Validation\Error\ErrorMessageCollectorInterface;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\ValidatorInterface;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

final class Fixtures
{
    public static function config(): Config
    {
        return new Config(['bench' => ['label' => 'configured']], new Environment([]));
    }

    /** @param array<string, mixed> $sections */
    public static function container(array $sections = [], bool $targeted = false): Container
    {
        $factory = new ContainerFactory();
        $definitions = new DependencyDefinitions($sections);
        $value = $targeted
            ? $factory->createWithTargetedAttributeDiscovery(self::config(), $definitions)
            : $factory->create(self::config(), $definitions);

        if (!$value->container instanceof Container) {
            throw new LogicException('Expected the Componenta container.');
        }
        return $value->container;
    }

    /** @return array<string, mixed> */
    public static function sections(): array
    {
        return [
            ConfigKey::SERVICES => [
                'bench.value' => 7,
                'bench.null' => null,
                'bench.false' => false,
                'bench.handler' => new Handler(),
            ],
            ConfigKey::FACTORIES => [
                'bench.factory' => Definition::factory(
                    static fn(ContainerValue $context, array $params): Product => new Product(
                        $context->get(Leaf::class, Leaf::class),
                        $params['number'] ?? 7,
                    ),
                ),
                'bench.definition' => ClassDefinition::create(Product::class)
                    ->constructor(['leaf' => Definition::reference(Leaf::class), 'number' => 7]),
                'bench.autowire' => ClassDefinition::create(Product::class)->autowire(),
                'bench.configured' => ClassDefinition::create(ConfiguredProduct::class)
                    ->constructor(['parts' => ['leaf' => Definition::reference(Leaf::class)]])
                    ->call('configure', ['leaf' => Definition::reference(Leaf::class), 'tag' => 'first'])
                    ->call('configure', ['leaf' => Definition::reference(Leaf::class), 'tag' => 'second']),
            ],
            ConfigKey::INVOKABLES => [EmptyEntry::class],
            ConfigKey::ALIASES => [
                'bench.alias' => 'bench.alias.middle',
                'bench.alias.middle' => Product::class,
                'bench.decorated' => 'bench.value',
            ],
            ConfigKey::DELEGATORS => [
                'bench.decorated' => [
                    static fn(int $value): int => $value + 1,
                    static fn(int $value): int => $value * 2,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function attributeSections(bool $uncached, bool $withProviders = false): array
    {
        $sections = self::sections();
        $sections[ConfigKey::ATTRIBUTE_DEFINITIONS] = [
            new AttributeDefinition(CacheControl::class, rules: $uncached ? [new NoOpRule()] : []),
        ];
        if ($withProviders) {
            $sections[ConfigKey::SERVICES][CasterProviderInterface::class] = new TrimCaster();
            $sections[ConfigKey::SERVICES][ValidationProviderInterface::class] = new DtoValidator();
        }
        return $sections;
    }

    public static function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new LogicException($message);
        }
    }

    public static function verifyEntry(string $id, mixed $result): void
    {
        $valid = match ($id) {
            'bench.value' => $result === 7,
            'bench.null' => $result === null,
            'bench.false' => $result === false,
            'bench.decorated' => $result === 16,
            EmptyEntry::class => $result instanceof EmptyEntry,
            'bench.configured' => $result instanceof ConfiguredProduct
                && $result->tags === ['first', 'second']
                && $result->parts['leaf'] === $result->leaf,
            Leaf::class => $result instanceof Leaf,
            Graph::class => $result instanceof Graph && $result->left->leaf === $result->right->leaf,
            default => $result instanceof Product && $result->number === 7,
        };
        self::check($valid, 'Unexpected entry: ' . $id);
    }
}

final class Leaf {}

final class EmptyEntry {}

final readonly class Product
{
    public function __construct(public Leaf $leaf, public int $number = 7) {}
}

final class ConfiguredProduct
{
    public array $tags = [];
    public Leaf $leaf;

    public function __construct(public readonly array $parts) {}

    public function configure(Leaf $leaf, string $tag): void
    {
        $this->leaf = $leaf;
        $this->tags[] = $tag;
    }
}

final readonly class LeftBranch
{
    public function __construct(public Leaf $leaf) {}
}

final readonly class RightBranch
{
    public function __construct(public Leaf $leaf) {}
}

final readonly class Graph
{
    public function __construct(public LeftBranch $left, public RightBranch $right, public Product $product) {}
}

function benchmarkHandler(Leaf $leaf, int $number): int
{
    return $number;
}

final class Handler
{
    public function __invoke(Leaf $leaf, int $number): int
    {
        return $number;
    }

    public function method(Leaf $leaf, int $number): int
    {
        return $number;
    }

    public static function staticMethod(Leaf $leaf, int $number): int
    {
        return $number;
    }
}

#[Attribute(Attribute::TARGET_ALL)]
final class CacheControl {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class TenantId {}

interface BenchLeft {}

interface BenchRight {}

final class LeftCandidate implements BenchLeft {}

final class ParameterTargets
{
    public static function defaults(int $number = 7, ?string $name = null): array
    {
        return [$number, $name];
    }

    public static function variadic(int ...$numbers): array
    {
        return $numbers;
    }

    public static function union(BenchLeft|BenchRight|false $candidate = false): BenchLeft|BenchRight|false
    {
        return $candidate;
    }

    public static function custom(#[TenantId] string $tenant): string
    {
        return $tenant;
    }
}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class Callbacks
{
    public static function label(Leaf $leaf): string
    {
        return 'initialized';
    }

    public static function setup(AttributedEntry $entry, Leaf $leaf): void
    {
        $entry->callbackRan = true;
    }

    public static function transform(string $value, Leaf $leaf): string
    {
        return trim($value);
    }

    public static function map(array $data, Leaf $leaf): array
    {
        $data['value'] .= '-callback';
        return $data;
    }
}

#[CacheControl]
#[SetUp('configure')]
#[SetUp(Callbacks::setup(...))]
final class AttributedEntry
{
    #[Inject]
    public Leaf $injected;

    #[Init(Callbacks::label(...))]
    public string $initialized;

    public bool $methodRan = false;
    public bool $callbackRan = false;

    public function __construct(
        #[ConfigValue(new ConfigPath('bench.label'))]
        public string $configured,
        #[Env('DI_BENCH_FIXTURE_MODE', default: 'default')]
        public string $mode,
        #[EntryId('bench.value')]
        public int $number,
        #[Make(Leaf::class)]
        public Leaf $fresh,
        #[Transform(Callbacks::transform(...))]
        public string $label = ' example ',
    ) {}

    public function configure(Leaf $leaf): void
    {
        $this->methodRan = true;
    }
}

#[NoConstructor]
final class SkippedConstructor
{
    #[Inject]
    public Leaf $leaf;

    public function __construct()
    {
        throw new LogicException('Constructor must be skipped.');
    }
}

class LazyValue
{
    public function __construct(public int $value = 7) {}

    public function read(): int
    {
        return $this->value;
    }
}

#[Lazy]
final class GhostEntry extends LazyValue {}

#[Proxy]
final class ProxyEntry extends LazyValue {}

final class ProxyParameterEntry
{
    public function __construct(#[Proxy] LazyValue $value)
    {
        $this->value = $value;
    }

    public readonly LazyValue $value;
}

final class ProxyPropertyEntry
{
    #[Proxy]
    public LazyValue $value;
}

final class MissingEntry extends RuntimeException implements NotFoundExceptionInterface {}

final class ExternalProvider implements ContainerInterface
{
    public function __construct(public array $entries) {}

    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new MissingEntry($id);
        }
        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}

final class TrimCaster implements CasterInterface, CasterProviderInterface
{
    public string $name { get => 'trim'; }

    public function provide(string $name): ?CasterInterface
    {
        return $name === 'trim' ? $this : null;
    }

    public function cast(mixed $value): string
    {
        if (!is_string($value)) {
            throw new LogicException('Expected a string for the trim caster.');
        }
        return trim($value);
    }
}

final class DtoValidator implements ValidationProviderInterface, ValidatorInterface
{
    public function provide(string $entryId): ?ValidatorInterface
    {
        return $entryId === MappedDto::class ? $this : null;
    }

    public function validate(iterable $data, ?ContextInterface $context = null): true|ErrorMessageCollectorInterface
    {
        $values = is_array($data) ? $data : iterator_to_array($data);
        Fixtures::check(($values['value'] ?? null) === ' mapped ', 'Validator must receive raw input.');
        return true;
    }
}

final readonly class MappedDto
{
    public function __construct(public string $value) {}
}

final class RequestTargets
{
    public static function extract(
        #[CacheControl, CurrentRequest] ServerRequestInterface $request,
        #[CurrentUri] UriInterface $uri,
        #[QueryParam('query')] string $query,
        #[PayloadParam('value')] string $value,
        #[Header('X-Token')] string $header,
        #[Cookie('cookie')] string $cookie,
        #[RequestAttribute('route')] string $route,
        #[ServerParam('server')] string $server,
        #[UploadedFile('upload')] ?UploadedFileInterface $file,
    ): array {
        return [$request, $uri, $query, $value, $header, $cookie, $route, $server, $file];
    }

    public static function allSources(
        #[CacheControl, MapQueryString] array $query,
        #[MapRequestPayload] array $payload,
        #[MapHeaders] array $headers,
        #[MapCookies] array $cookies,
        #[MapRequestAttributes] array $attributes,
        #[MapServerParams] array $server,
        #[MapUploadedFiles] array $files,
        #[MapRequest(sources: [RequestDataSource::Query, RequestDataSource::Cookies])] array $combined,
    ): array {
        return [$query, $payload, $headers, $cookies, $attributes, $server, $files, $combined];
    }

    public static function plain(#[CacheControl, MapRequest] array $input): array
    {
        return $input;
    }

    public static function callback(#[CacheControl, MapRequest(afterMap: Callbacks::map(...))] array $input): array
    {
        return $input;
    }

    public static function dto(#[CacheControl, MapRequest] MappedDto $input): MappedDto
    {
        return $input;
    }

    public static function dtoCallback(#[CacheControl, MapRequest(afterMap: Callbacks::map(...))] MappedDto $input): MappedDto
    {
        return $input;
    }

    public static function dtoCastValidated(
        #[CacheControl, MapRequest(cast: ['value' => 'trim'])] MappedDto $input,
    ): MappedDto {
        return $input;
    }

    public static function cast(#[CacheControl, Cast('trim')] string $input): string
    {
        return $input;
    }

    public static function transform(#[CacheControl, Transform(Callbacks::transform(...))] string $input): string
    {
        return $input;
    }

    public static function inlineTransform(
        #[CacheControl, Transform(static function (string $input): string {
            return trim($input);
        })]
        string $input,
    ): string {
        return $input;
    }
}
