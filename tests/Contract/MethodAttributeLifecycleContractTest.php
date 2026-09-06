<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionMethod;
use Reflector;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class MethodLifecycleAttribute {}

final class MethodLifecycleHandler implements AttributeHandlerInterface
{
    /** @var list<string> */
    public array $before = [];
    /** @var list<string> */
    public array $after = [];

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$attribute instanceof MethodLifecycleAttribute || !$target instanceof ReflectionMethod) {
            throw new LogicException('Expected a method lifecycle attribute.');
        }

        if ($context->entry === null) {
            $this->before[] = $target->getName();
        } else {
            $this->after[] = $target->getName();
        }
    }
}

test('method attributes including private parent methods run once in each registered phase per creation', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $parent = 'MethodLifecycleParent_' . $suffix;
    $target = 'MethodLifecycleTarget_' . $suffix;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        abstract class %s {
            #[MethodLifecycleAttribute]
            private function inheritedHook(): void {}
        }
        final class %s extends %s {
            #[MethodLifecycleAttribute]
            public function hook(): void {}
        }
        PHP,
        __NAMESPACE__,
        $parent,
        $target,
        $parent,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!class_exists($class)) {
        throw new LogicException('Expected the method lifecycle fixture.');
    }
    $handler = new MethodLifecycleHandler();
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(
            MethodLifecycleAttribute::class,
            $handler,
            phase: AttributePhase::Both,
        ))
        ->build();

    expect($container->has($class))->toBeTrue()
        ->and($handler->before)->toBe([])
        ->and($handler->after)->toBe([]);

    $container->make($class);
    $container->make($class);
    sort($handler->before);
    sort($handler->after);

    expect($handler->before)->toBe(['hook', 'hook', 'inheritedHook', 'inheritedHook'])
        ->and($handler->after)->toBe(['hook', 'hook', 'inheritedHook', 'inheritedHook']);
});
