<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Object\ObjectCompletedPhaseBoundariesContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS)]
final class BeforeMarker {}

#[Attribute(Attribute::TARGET_CLASS)]
final class AfterMarker {}

final class Trace
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        public readonly string $beforeAlias,
        public readonly string $loadBeforeAt,
        public readonly ?string $afterAlias,
    ) {}

    public function loadBefore(): void
    {
        if (!class_exists($this->beforeAlias, false)) {
            class_alias(BeforeMarker::class, $this->beforeAlias);
        }
    }
}

abstract class Product
{
    public bool $ready;

    public function __construct(Trace $trace)
    {
        $trace->events[] = 'constructor';
        $this->ready = true;
        if ($trace->afterAlias !== null && !class_exists($trace->afterAlias, false)) {
            class_alias(AfterMarker::class, $trace->afterAlias);
        }
        if ($trace->loadBeforeAt === 'constructor') {
            $trace->loadBefore();
        }
    }
}

final readonly class Handler implements AttributeHandlerInterface
{
    public function __construct(private Trace $trace) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if ($attribute instanceof BeforeMarker) {
            $this->trace->events[] = 'before';
            return;
        }
        if (!$attribute instanceof AfterMarker) {
            throw new LogicException('Expected a phase marker.');
        }
        $this->trace->events[] = 'after';
        if ($this->trace->loadBeforeAt === 'after') {
            $this->trace->loadBefore();
        }
    }
}

test('completed before-phase changes stop later object effects and allow a fresh creation', function (string $strategy, string $loadBeforeAt): void {
    $suffix = bin2hex(random_bytes(6));
    $before = 'LateBefore_' . $suffix;
    $after = $strategy === 'handlerless' ? 'LateAfter_' . $suffix : 'AfterMarker';
    $target = 'Target_' . $suffix;
    $creation = match ($strategy) {
        'lazy' => '#[\\Componenta\\DI\\Attribute\\Lazy]',
        'proxy' => '#[\\Componenta\\DI\\Attribute\\Proxy]',
        default => '',
    };
    eval(sprintf(
        'namespace %s; %s #[%s, %s] final class %s extends Product {}',
        __NAMESPACE__,
        $creation,
        $before,
        $after,
        $target,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected the object phase fixture.');
    }
    $trace = new Trace(
        __NAMESPACE__ . '\\' . $before,
        $loadBeforeAt,
        $strategy === 'handlerless' ? __NAMESPACE__ . '\\' . $after : null,
    );
    $handler = new Handler($trace);
    [$container, $runtime] = (new ContainerBuilder())
        ->addService(Trace::class, $trace)
        ->addAttributeDefinition(new AttributeDefinition(BeforeMarker::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->addAttributeDefinition(new AttributeDefinition(AfterMarker::class, $handler))
        ->assemble();
    $pipeline = $runtime->objects;
    Assert::assertInstanceOf(ObjectPipeline::class, $pipeline);
    $create = static function () use ($pipeline, $class, $trace): bool {
        $entry = $pipeline->create($class, configure: static function () use ($trace): void {
            $trace->events[] = 'configure';
        });
        if (!$entry instanceof Product) {
            throw new LogicException('Expected the created product.');
        }
        return $entry->ready;
    };

    expect($create)->toThrow(AttributeCompositionException::class, 'BeforeInstantiation completed')
        ->and($trace->events)->toBe($loadBeforeAt === 'constructor' ? ['constructor'] : ['constructor', 'after']);

    $trace->events = [];
    expect($create())->toBeTrue()
        ->and($trace->events)->toBe(['before', 'constructor', 'after', 'configure']);
})->with(['handlerless', 'eager', 'lazy', 'proxy'])
    ->with(['constructor', 'after']);
