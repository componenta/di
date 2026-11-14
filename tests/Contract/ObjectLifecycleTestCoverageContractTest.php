<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ObjectLifecycleTestCoverage;

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

final readonly class Dependency
{
    public function __construct(public string $label) {}
}

abstract class ParentOwner
{
    #[EntryId('private.parent')]
    private Dependency $dependency;

    public function __construct()
    {
        $this->dependency = new Dependency('parent constructor value');
    }

    public function parentDependency(): Dependency
    {
        return $this->dependency;
    }
}

class ChildOwner extends ParentOwner
{
    #[EntryId('private.child')]
    private Dependency $dependency;

    public function __construct()
    {
        parent::__construct();
        $this->dependency = new Dependency('child constructor value');
    }

    public function childDependency(): Dependency
    {
        return $this->dependency;
    }
}

#[Lazy]
final class LazyChildOwner extends ChildOwner {}

#[Proxy]
final class ProxyChildOwner extends ChildOwner {}

test('parent and child private properties with the same name receive their own services', function (string $class): void {
    if (!is_a($class, ChildOwner::class, true)) {
        throw new LogicException('Expected the private-property fixture.');
    }
    $parent = new Dependency('parent');
    $child = new Dependency('child');
    $container = (new ContainerBuilder())
        ->addService('private.parent', $parent)
        ->addService('private.child', $child)
        ->build();

    foreach ([1, 2] as $_attempt) {
        $entry = $container->make($class);
        if (!$entry instanceof ChildOwner) {
            throw new LogicException('Expected the private-property owner.');
        }

        expect($entry->parentDependency())->toBe($parent)
            ->and($entry->childDependency())->toBe($child);
    }
})->with([ChildOwner::class, LazyChildOwner::class, ProxyChildOwner::class]);

#[SetUp('initialize', ['dependency' => new EntryId('static.setup.dependency')])]
final class StaticSetupOwner
{
    public static int $calls = 0;

    public static function initialize(Dependency $dependency): void
    {
        ++self::$calls;
    }
}

test('setup rejects static methods before resolving their dependencies or invoking their bodies', function (): void {
    StaticSetupOwner::$calls = 0;
    $dependencyCreations = 0;
    $container = (new ContainerBuilder())
        ->addFactory('static.setup.dependency', static function () use (&$dependencyCreations): Dependency {
            ++$dependencyCreations;
            return new Dependency('must not be resolved');
        })
        ->build();

    foreach ([1, 2] as $_attempt) {
        expect(fn(): object => $container->get(StaticSetupOwner::class))
            ->toThrow(ResolutionException::class, 'must be public, concrete and non-static.')
            ->and($dependencyCreations)->toBe(0)
            ->and(StaticSetupOwner::$calls)->toBe(0);
    }
});
