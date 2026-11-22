<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\PsrLookupFailure;

use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Container;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Internal\AvailableEntryLookup;
use Componenta\DI\Tests\Support\ContainerBuilder;
use DomainException;
use LogicException;

final readonly class Label
{
    public function __construct(#[EntryId('palette.label')] public string $value) {}
}

test('get distinguishes a missing requested entry from a missing dependency of a selected entry', function (): void {
    $container = (new ContainerBuilder())->addAlias('theme.label', Label::class)->build();
    expect($container->has('theme.label'))->toBeTrue()
        ->and($container->has('unknown.label'))->toBeFalse();

    $error = null;
    try {
        $container->get('theme.label');
    } catch (ResolutionException $failure) {
        $error = $failure;
    }

    if ($error === null) {
        throw new LogicException('Expected a service resolution failure.');
    }
    $cause = $error->getPrevious();
    if (!$cause instanceof NotFoundException) {
        throw new LogicException('Expected the original missing dependency.');
    }
    expect($error->serviceId)->toBe('theme.label')
        ->and($cause->id)->toBe('palette.label')
        ->and(fn() => $container->get('unknown.label'))->toThrow(NotFoundException::class, 'unknown.label')
        ->and(fn() => $container->make(Label::class))->toThrow(NotFoundException::class, 'palette.label');

    $container->set('palette.label', 'blue');
    expect($container->get('theme.label'))->toEqual(new Label('blue'));
});

test('a selected delegator may fail because its own entry is missing', function (): void {
    $container = (new ContainerBuilder())->addService('product', new \stdClass())->build();
    $container->delegator('product', static fn(): mixed => $container->get('missing.decorator.dependency'));

    expect($container->has('product'))->toBeTrue()
        ->and(fn() => $container->get('product'))->toThrow(ResolutionException::class, 'missing.decorator.dependency');
});

#[Lazy, Proxy]
final class ConflictingEntry
{
    public string $value = 'never constructed';
}

test('an existing entry with invalid composition remains known to PSR-11 lookup', function (): void {
    $container = (new ContainerBuilder())->addAlias('conflicting', ConflictingEntry::class)->build();

    expect($container->has('conflicting'))->toBeTrue()
        ->and(fn() => $container->get('conflicting'))->toThrow(AttributeCompositionException::class)
        ->and($container->call(static fn(?ConflictingEntry $dependency = null): ?ConflictingEntry => $dependency))->toBeNull();
});

/** @param array<string|int,mixed> $params */
function ConflictingEntry(ContainerValue $container, array $params = []): string
{
    return 'native';
}

test('a native external container does not offer an invalid autowiring candidate to its parent', function (): void {
    $parent = new ContainerBuilder()->build();
    $provider = new ContainerBuilder()->build();
    $parent->addContainer($provider);
    $lookup = static fn(?ConflictingEntry $entry = null): ?ConflictingEntry => $entry;

    expect($parent->has(ConflictingEntry::class))->toBeTrue()
        ->and($parent->call($lookup))->toBeNull();
    $value = new ConflictingEntry();
    $parent->set(ConflictingEntry::class, $value);

    expect($parent->call($lookup))->toBe($value)
        ->and(fn() => $parent->get(ConflictingEntry::class))->toThrow(AttributeCompositionException::class);
});

test('an external native container proxy offers entries from its backing container', function (): void {
    $entry = new Label('external');
    $backing = new ContainerBuilder()->addService(Label::class, $entry)->build();
    $parent = new ContainerBuilder()->build();
    $provider = $parent->makeProxy(Container::class, static fn(): Container => $backing);
    if (!$provider instanceof Container) {
        throw new LogicException('Expected a container proxy.');
    }
    $parent->addContainer($provider);

    $lookup = static fn(?Label $label = null): ?Label => $label;
    expect($parent->call($lookup))->toBe($entry)
        ->and($parent->get(Label::class))->toBe($entry);
});

test('native entry lookup has no public operation to attach a reader to a container proxy', function (): void {
    expect(get_class_methods(AvailableEntryLookup::class))->not->toContain('attach');
});

test('get wraps an initialization failure of a native container proxy', function (): void {
    $owner = new ContainerBuilder()->build();
    $failure = new DomainException('backing container is unavailable');
    $proxy = $owner->makeProxy(Container::class, static fn(): never => throw $failure);
    if (!$proxy instanceof Container) {
        throw new LogicException('Expected a container proxy.');
    }

    try {
        $proxy->get('entry');
    } catch (ResolutionException $error) {
        expect($error->serviceId)->toBe('entry')
            ->and($error->getPrevious())->toBe($failure);
        return;
    }
    throw new LogicException('Expected a container resolution exception.');
});

test('an unavailable native provider proxy leaves a local autowiring route available', function (): void {
    $local = new Label('local');
    $owner = new ContainerBuilder()->addService(Label::class, $local)->build();
    $provider = $owner->makeProxy(Container::class, static fn(): never => throw new DomainException('unavailable'));
    if (!$provider instanceof Container) {
        throw new LogicException('Expected a container proxy.');
    }
    $owner->addContainer($provider);

    expect($owner->call(static fn(?Label $value = null): ?Label => $value))->toBe($local);
});

test('an invalid implicit entry does not mask a valid named function or factory', function (): void {
    $name = __NAMESPACE__ . '\\ConflictingEntry';
    $container = new ContainerBuilder()->addFactory('native.factory', $name)->build();

    expect($container->call($name))->toBe('native')
        ->and($container->get('native.factory'))->toBe('native');

    $container->set($name, static fn(): string => 'entry');
    expect($container->call($name))->toBe('entry');
});
