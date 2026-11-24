<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\AttributePlanDiagnosticsContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use PHPUnit\Framework\Assert;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use Reflector;
use RuntimeException;

#[Attribute(Attribute::TARGET_ALL)]
final class PlanAuditMarker {}

#[Attribute(Attribute::TARGET_ALL)]
final class PlanAuditCompanion {}

#[PlanAuditMarker]
final class PlanAuditTarget
{
    #[PlanAuditMarker]
    public string $value;

    #[PlanAuditMarker]
    public function run(#[PlanAuditMarker] string $value): void {}
}

function planAuditFunction(#[PlanAuditMarker] string $value): void {}

test('stable targets expose matching attribute metadata on repeat builds', function (
    ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $target,
): void {
    $registry = new AttributeDefinitionRegistry();
    $definition = new AttributeDefinition(PlanAuditMarker::class);
    $registry->register($definition);
    $builder = new AttributePlanBuilder($registry);

    foreach ([1, 2] as $_attempt) {
        $usages = $builder->build($target)->attributes(PlanAuditMarker::class);

        expect($usages)->toHaveCount(1)
            ->and($usages[0]->attributeClass)->toBe(PlanAuditMarker::class)
            ->and($usages[0]->definition)->toBe($definition);
    }
})->with([
    'class' => [new ReflectionClass(PlanAuditTarget::class)],
    'method' => [new ReflectionMethod(PlanAuditTarget::class, 'run')],
    'property' => [new ReflectionProperty(PlanAuditTarget::class, 'value')],
    'method parameter' => [new ReflectionParameter([PlanAuditTarget::class, 'run'], 0)],
    'function parameter' => [new ReflectionParameter(__NAMESPACE__ . '\\planAuditFunction', 0)],
]);

test('missing companion diagnostics identify the complete attribute target', function (
    ReflectionClass|ReflectionMethod|ReflectionProperty|ReflectionParameter $target,
    string $name,
): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(PlanAuditMarker::class, requires: [PlanAuditCompanion::class]));
    $builder = new AttributePlanBuilder($registry);

    try {
        $builder->build($target);
        \PHPUnit\Framework\Assert::fail('A missing companion must be rejected.');
    } catch (AttributeCompositionException $exception) {
        expect($exception->getMessage())->toBe(
            $name . ' requires ' . PlanAuditCompanion::class . ' because of #[' . PlanAuditMarker::class . '].',
        );
    }
})->with([
    'class' => [new ReflectionClass(PlanAuditTarget::class), PlanAuditTarget::class],
    'property' => [new ReflectionProperty(PlanAuditTarget::class, 'value'), PlanAuditTarget::class . '::$value'],
    'method' => [new ReflectionMethod(PlanAuditTarget::class, 'run'), PlanAuditTarget::class . '::run()'],
    'method parameter' => [new ReflectionParameter([PlanAuditTarget::class, 'run'], 0), '$value of ' . PlanAuditTarget::class . '::run()'],
    'function parameter' => [new ReflectionParameter(__NAMESPACE__ . '\\planAuditFunction', 0), '$value of ' . __NAMESPACE__ . '\\planAuditFunction()'],
]);

final class PlanFlagsConstants
{
    public const int TARGETS = Attribute::TARGET_CLASS;
}

final class PlanFlagsRuntimeState
{
    public static int $attributes = 0;
    public static int $targets = 0;
}

final class PlanFlagsHandler implements AttributeHandlerInterface
{
    public int $calls = 0;

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        ++$this->calls;
    }
}

test('native attribute flags preserve load failures and can be prepared after the loader recovers', function (bool $compositionFailure): void {
    $suffix = bin2hex(random_bytes(6));
    $flags = 'LatePlanFlags_' . $suffix;
    $attribute = 'PlanFlagsAttribute_' . $suffix;
    $target = 'PlanFlagsTarget_' . $suffix;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        #[\Attribute(%s::TARGETS)]
        final class %s {
            public function __construct() { ++PlanFlagsRuntimeState::$attributes; }
        }
        #[%s]
        final class %s {
            public function __construct() { ++PlanFlagsRuntimeState::$targets; }
        }
        PHP,
        __NAMESPACE__,
        $flags,
        $attribute,
        $attribute,
        $target,
    ));
    $attribute = __NAMESPACE__ . '\\' . $attribute;
    $target = __NAMESPACE__ . '\\' . $target;
    $flags = __NAMESPACE__ . '\\' . $flags;
    if (!class_exists($attribute, false) || !class_exists($target, false)) {
        throw new LogicException('Expected the native flags fixture.');
    }
    $handler = new PlanFlagsHandler();
    $definition = new AttributeDefinition($attribute, $handler);
    [$container, $runtime] = (new ContainerBuilder())->addAttributeDefinition($definition)->assemble();
    $plans = $runtime->plans;
    Assert::assertInstanceOf(AttributePlanBuilder::class, $plans);
    $reflection = new ReflectionClass($target);
    $failure = $compositionFailure
        ? new AttributeCompositionException('flags loader failed')
        : new RuntimeException('flags loader failed');
    $availability = new class () {
        public bool $ready = false;
        public int $loads = 0;
    };
    $loader = static function (string $requested) use ($flags, $failure, $availability): void {
        if ($requested !== $flags) {
            return;
        }
        ++$availability->loads;
        if (!$availability->ready) {
            throw $failure;
        }
        class_alias(PlanFlagsConstants::class, $flags);
    };
    PlanFlagsRuntimeState::$attributes = PlanFlagsRuntimeState::$targets = 0;
    spl_autoload_register($loader);

    try {
        $caught = null;
        try {
            $plans->build($reflection);
        } catch (AttributeCompositionException $error) {
            $caught = $error;
        }
        if (!$caught instanceof AttributeCompositionException) {
            throw new LogicException('Expected native flags preparation to fail.');
        }
        if ($compositionFailure) {
            expect($caught)->toBe($failure)->and($caught->getPrevious())->toBeNull();
        } else {
            expect($caught->getPrevious())->toBe($failure)
                ->and($caught->getMessage())->toBe('Cannot instantiate #[Attribute] metadata for "' . $attribute . '": flags loader failed');
        }
        expect($availability->loads)->toBe(1)
            ->and($handler->calls)->toBe(0)
            ->and(PlanFlagsRuntimeState::$attributes)->toBe(0)
            ->and(PlanFlagsRuntimeState::$targets)->toBe(0);

        $availability->ready = true;
        $plan = $plans->build($reflection);
        expect($plan->usages)->toHaveCount(1)
            ->and($plan->usages[0]->definition)->toBe($definition)
            ->and($plans->build($reflection))->toBe($plan)
            ->and($availability->loads)->toBe(2)
            ->and($handler->calls)->toBe(0)
            ->and(PlanFlagsRuntimeState::$attributes)->toBe(0)
            ->and(PlanFlagsRuntimeState::$targets)->toBe(0);

        $entry = $container->get($target);
        expect($entry)->toBeInstanceOf($target)
            ->and($container->get($target))->toBe($entry)
            ->and($handler->calls)->toBe(1)
            ->and(PlanFlagsRuntimeState::$attributes)->toBe(1)
            ->and(PlanFlagsRuntimeState::$targets)->toBe(1)
            ->and($availability->loads)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
        PlanFlagsRuntimeState::$attributes = PlanFlagsRuntimeState::$targets = 0;
    }
})->with(['foreign loader failure' => false, 'composition loader failure' => true]);
