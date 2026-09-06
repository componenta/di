<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExplicitDefinitions;

use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Tests\Support\ContainerBuilder;

final class Dependency {}

#[Lazy, Proxy, NoConstructor, SetUp('initialize')]
final class ExplicitProduct
{
    #[EntryId('missing.property')]
    public string $property = 'untouched';
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        #[CurrentRequest] public string $value = 'native-default',
        public ?Dependency $dependency = null,
    ) {
        $this->events[] = 'constructor';
    }

    public function initialize(): void
    {
        $this->events[] = 'attribute';
    }

    public function configure(#[EntryId('missing.method')] string $value = 'method-default'): void
    {
        $this->events[] = $value;
    }
}

it('creates ClassDefinition results using only explicit instructions and native defaults', function (): void {
    $dependencyCalls = 0;
    $di = (new ContainerBuilder())
        ->addFactory(Dependency::class, static function () use (&$dependencyCalls): Dependency {
            ++$dependencyCalls;
            return new Dependency();
        })
        ->addDefinition(ExplicitProduct::class, ClassDefinition::create(ExplicitProduct::class)
            ->call('configure')
            ->call('configure', ['value' => 'explicit']))
        ->build();

    $entry = $di->get(ExplicitProduct::class);

    expect($entry->events)->toBe(['constructor', 'method-default', 'explicit'])
        ->and($entry->value)->toBe('native-default')
        ->and($entry->dependency)->toBeNull()
        ->and($entry->property)->toBe('untouched')
        ->and($dependencyCalls)->toBe(0)
        ->and($di->get(ExplicitProduct::class))->toBe($entry);
});

it('accepts definitions with their own payload shape and rejects unsupported markers without replacing a binding', function (): void {
    expect(new \ReflectionClass(\Componenta\DI\Definition\DefinitionInterface::class)->getProperties())->toBe([]);
    $definition = new class () implements \Componenta\DI\Definition\DefinitionInterface {};
    $original = new Dependency();
    $di = (new ContainerBuilder())->addService('original', $original)->build();

    expect(fn() => $di->set('original', $definition))->toThrow(\Componenta\DI\Exception\InvalidConfigurationException::class)
        ->and($di->get('original'))->toBe($original);
});

final class FallbackProduct
{
    public ?Dependency $configured = null;

    public function __construct(#[EntryId('missing.constructor')] public ?Dependency $dependency = null) {}

    public function configure(#[EntryId('missing.method')] ?Dependency $dependency = null): void
    {
        $this->configured = $dependency;
    }
}

it('uses autowiring only as an explicit fallback for constructor and method arguments', function (bool $enabled): void {
    $shared = new Dependency();
    $explicit = new Dependency();
    $definition = ClassDefinition::create(FallbackProduct::class)->autowire()->autowire($enabled)->constructor([])->call('configure');
    $di = (new ContainerBuilder())
        ->addService(Dependency::class, $shared)
        ->addDefinition(FallbackProduct::class, $definition)
        ->build();

    $defaults = $di->make(FallbackProduct::class);
    $override = $di->make(FallbackProduct::class, ['dependency' => $explicit]);

    expect($defaults->dependency)->toBe($enabled ? $shared : null)
        ->and($defaults->configured)->toBe($enabled ? $shared : null)
        ->and($override->dependency)->toBe($explicit)
        ->and($override->configured)->toBe($enabled ? $shared : null);
})->with(['enabled' => true, 'disabled' => false]);

final class VariadicProduct
{
    /** @var array<array-key,string> */
    public array $values;

    public function __construct(string $prefix = 'default', string ...$parts)
    {
        $this->values = [$prefix, ...$parts];
    }

    public function append(string ...$parts): void
    {
        $this->values = [...$this->values, ...$parts];
    }
}

it('preserves explicitly supplied variadic arguments and omitted native defaults', function (bool $override): void {
    $definition = ClassDefinition::create(VariadicProduct::class)
        ->constructor([1 => 'first', 2 => 'second'])
        ->call('append', ['third', 'fourth']);
    $di = (new ContainerBuilder())->addDefinition(VariadicProduct::class, $definition)->build();

    $entry = $di->make(VariadicProduct::class, $override ? [0 => 'runtime'] : []);

    expect($entry->values)->toBe([$override ? 'runtime' : 'default', 'first', 'second', 'third', 'fourth']);
})->with(['native default' => false, 'runtime override' => true]);

it('keeps factory and invokable results outside the attribute lifecycle', function (string $kind): void {
    $factory = static fn(): ExplicitProduct => new ExplicitProduct();
    $sections = match ($kind) {
        'factory' => [\Componenta\DI\ConfigKey::FACTORIES => [ExplicitProduct::class => $factory]],
        'definition' => [\Componenta\DI\ConfigKey::FACTORIES => [ExplicitProduct::class => \Componenta\DI\Definition\Definition::factory($factory)]],
        'invokable' => [\Componenta\DI\ConfigKey::INVOKABLES => [\Componenta\DI\Definition\Definition::invokable(ExplicitProduct::class)]],
        default => throw new \LogicException('Unknown definition fixture.'),
    };
    $di = \Componenta\DI\Tests\Support\container($sections);

    $shared = $di->get(ExplicitProduct::class);
    $fresh = $di->make(ExplicitProduct::class);

    expect($shared->events)->toBe(['constructor'])
        ->and($fresh->events)->toBe(['constructor'])
        ->and($shared->value)->toBe('native-default')
        ->and($shared->property)->toBe('untouched')
        ->and($fresh)->not->toBe($shared)
        ->and($di->get(ExplicitProduct::class))->toBe($shared);
})->with(['factory', 'definition', 'invokable']);

#[SetUp('initialize')]
final class ManagedDependency
{
    public int $initializations = 0;
    public function initialize(): void
    {
        ++$this->initializations;
    }
}

#[SetUp('notConfigured')]
final readonly class ReferencingProduct
{
    public function __construct(public ManagedDependency $dependency) {}
}

it('lets referenced dependencies use their own registered lifecycle', function (bool $autowire): void {
    $definition = ClassDefinition::create(ReferencingProduct::class)->autowire($autowire);
    if (!$autowire) {
        $definition = $definition->constructor(['dependency' => \Componenta\DI\Definition\Definition::reference(ManagedDependency::class)]);
    }
    $di = (new ContainerBuilder())->addDefinition(ReferencingProduct::class, $definition)->build();

    $entry = $di->make(ReferencingProduct::class);

    expect($entry->dependency->initializations)->toBe(1)
        ->and($entry->dependency)->toBe($di->get(ManagedDependency::class));
})->with(['explicit reference' => false, 'type fallback' => true]);

final readonly class RequiredProduct
{
    public function __construct(public ?Dependency $dependency) {}
}

it('does not supply implicit null or autowire a required argument when fallback is disabled', function (): void {
    $dependency = new Dependency();
    $di = (new ContainerBuilder())
        ->addService(Dependency::class, $dependency)
        ->addDefinition(RequiredProduct::class, ClassDefinition::create(RequiredProduct::class))
        ->build();

    expect(fn() => $di->get(RequiredProduct::class))->toThrow(\Componenta\DI\Exception\ResolutionException::class)
        ->and($di->make(RequiredProduct::class, ['dependency' => null])->dependency)->toBeNull();
    $di->set(RequiredProduct::class, ClassDefinition::create(RequiredProduct::class)->autowire());
    expect($di->get(RequiredProduct::class)->dependency)->toBe($dependency);
});
