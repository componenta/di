<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\AttributeProcessorRevision;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;
use Reflector;

final class NativePayload
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ClassMarker
{
    public function __construct(public NativePayload $payload) {}
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class MemberMarker
{
    public function __construct(public NativePayload $payload) {}
}

#[Attribute(Attribute::TARGET_ALL)]
final class UnusedMarker {}

final class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class ClassHandler implements AttributeHandlerInterface
{
    public int $calls = 0;

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        ++$this->calls;
    }
}

test('object handler discovery returns a complete revision after member autoloading', function (bool $uncached, bool $warm): void {
    $suffix = bin2hex(random_bytes(6));
    $classAttribute = 'LateClassMarker_' . $suffix;
    $memberAttribute = 'LateMemberMarker_' . $suffix;
    $target = 'Target_' . $suffix;
    eval(sprintf(
        'namespace %s; #[%s(new NativePayload())] final class %s { #[%s(new NativePayload())] public string $metadata = "value"; }',
        __NAMESPACE__,
        $classAttribute,
        $target,
        $memberAttribute,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!class_exists($class)) {
        throw new LogicException('Expected the member discovery fixture.');
    }
    $handler = new ClassHandler();
    $builder = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(ClassMarker::class, $handler))
        ->addAttributeDefinition(new AttributeDefinition(MemberMarker::class));
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UnusedMarker::class, rules: [new NoOpRule()]));
    }
    $container = $builder->build();
    $processor = $container->get(AttributeProcessor::class);
    $reflection = new ReflectionClass($class);
    NativePayload::$constructions = 0;
    if ($warm) {
        $processor->prepare($reflection);
    }
    expect(NativePayload::$constructions)->toBe(0)->and($handler->calls)->toBe(0);

    $loads = 0;
    $loader = static function (string $requested) use ($classAttribute, $memberAttribute, &$loads): void {
        if ($requested === __NAMESPACE__ . '\\' . $memberAttribute) {
            ++$loads;
            class_alias(ClassMarker::class, __NAMESPACE__ . '\\' . $classAttribute);
            class_alias(MemberMarker::class, __NAMESPACE__ . '\\' . $memberAttribute);
        }
    };
    spl_autoload_register($loader);
    try {
        expect($processor->hasHandlers($reflection))->toBeTrue()
            ->and(NativePayload::$constructions)->toBe(0)
            ->and($handler->calls)->toBe(0);

        $context = new ObjectCreationContext($reflection);
        $context->initialize(new $class());
        $processor->process($reflection, AttributePhase::AfterInstantiation, $context);
        expect($handler->calls)->toBe(1)
            ->and(NativePayload::$constructions)->toBe(1)
            ->and($processor->hasHandlers($reflection))->toBeTrue()
            ->and(NativePayload::$constructions)->toBe(1)
            ->and($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
        NativePayload::$constructions = 0;
    }
})->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['cold metadata' => false, 'warm metadata' => true]);
