<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ConstructorPolicyLifecycle;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS)]
final class InitializeWithoutConstructor {}

abstract class Product
{
    public string $state = 'uninitialized';
}

#[InitializeWithoutConstructor]
final class PrivateProduct extends Product
{
    private function __construct()
    {
        throw new LogicException('The constructor policy must bypass the private constructor.');
    }
}

#[InitializeWithoutConstructor]
final class ProtectedProduct extends Product
{
    protected function __construct()
    {
        throw new LogicException('The constructor policy must bypass the protected constructor.');
    }
}

final class InitializeWithoutConstructorHandler implements AttributeHandlerInterface
{
    /** @var list<string> */
    public array $events = [];

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof InitializeWithoutConstructor || !$target instanceof ReflectionClass) {
            throw new LogicException('Expected a class constructor policy.');
        }

        $entry = $context->entry;
        if ($entry === null) {
            $this->events[] = 'before';
            $context->disableConstructor();
            return;
        }
        if (!$entry instanceof Product) {
            throw new LogicException('Expected the constructor-policy product.');
        }

        $this->events[] = 'after';
        $entry->state = 'initialized';
    }
}

test('a constructor policy active in both phases creates and initializes classes with nonpublic constructors', function (string $class): void {
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a constructor-policy fixture.');
    }
    $handler = new InitializeWithoutConstructorHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            InitializeWithoutConstructor::class,
            $handler,
            capabilities: [ConstructorPolicy::class],
            phase: AttributePhase::Both,
        ))
        ->build();

    expect($container->has($class))->toBeTrue()
        ->and($handler->events)->toBe([]);

    $shared = $container->getAs($class, $class);
    expect($shared)->toBeInstanceOf($class)
        ->and($shared->state)->toBe('initialized')
        ->and($handler->events)->toBe(['before', 'after'])
        ->and($container->get($class))->toBe($shared)
        ->and($handler->events)->toBe(['before', 'after']);

    $fresh = $container->makeAs($class, $class);
    expect($fresh)->toBeInstanceOf($class)
        ->and($fresh)->not->toBe($shared)
        ->and($fresh->state)->toBe('initialized')
        ->and($handler->events)->toBe(['before', 'after', 'before', 'after'])
        ->and($container->get($class))->toBe($shared);
})->with([
    'private constructor' => PrivateProduct::class,
    'protected constructor' => ProtectedProduct::class,
]);
