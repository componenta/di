<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Reflector;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD)]
final class CompletedPhaseMarker {}

final class CompletedPhaseObserver implements AttributeHandlerInterface
{
    public int $calls = 0;

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        ++$this->calls;
    }
}

test('a late member policy identifies its target and completed phase after a configuration callback', function (bool $method): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'CompletedMemberAlias_' . $suffix;
    $name = 'CompletedMemberProduct_' . $suffix;
    $member = $method ? 'public function value(): string { return "ready"; }' : 'public string $value = "ready";';
    eval('namespace ' . __NAMESPACE__ . '; final class ' . $name . ' { #[' . $alias . '] ' . $member . ' }');
    $class = __NAMESPACE__ . '\\' . $name;
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    if (!class_exists($class)) {
        throw new LogicException('Expected the late member fixture.');
    }
    $handler = new CompletedPhaseObserver();
    $pipeline = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(CompletedPhaseMarker::class, $handler, phase: AttributePhase::BeforeInstantiation))
        ->build()->get(ObjectPipeline::class);

    expect(fn() => $pipeline->create($class, configure: static function (object $entry) use ($fullAlias): void {
        class_alias(CompletedPhaseMarker::class, $fullAlias);
    }))->toThrow(
        AttributeCompositionException::class,
        'Attribute composition for ' . $class . '::value changed after BeforeInstantiation completed. Load its attributes before execution.',
    )->and($handler->calls)->toBe(0);

    $pipeline->create($class);
    expect($handler->calls)->toBe(1);
})->with(['property' => false, 'method' => true]);

test('late parameter policies identify both free functions and class methods', function (bool $method): void {
    $suffix = bin2hex(random_bytes(5));
    $alias = 'CompletedParameterAlias_' . $suffix;
    $name = 'CompletedParameterProduct_' . $suffix;
    $function = 'completedParameter_' . $suffix;
    $declaration = 'function ' . $function . '(#[' . $alias . '("settings")] string $value, #[\Componenta\DI\Attribute\EntryId("loader")] object $loader): string { return $value; }';
    if ($method) {
        $declaration = 'final class ' . $name . ' { public static ' . $declaration . ' }';
    }
    eval('namespace ' . __NAMESPACE__ . '; ' . $declaration);
    $fullAlias = __NAMESPACE__ . '\\' . $alias;
    $callable = __NAMESPACE__ . '\\' . ($method ? $name . '::' : '') . $function;
    $container = (new ContainerBuilder())
        ->addFactory('loader', static function () use ($fullAlias): object {
            class_alias(\Componenta\DI\Attribute\Config::class, $fullAlias);
            return new \stdClass();
        })->build();

    expect(fn() => $container->call($callable, ['value' => 'provided']))
        ->toThrow(
            AttributeCompositionException::class,
            'Attribute composition for parameter $value of ' . $callable . '() changed after parameter resolution completed. Load its attributes before execution.',
        );
})->with(['free function' => false, 'class method' => true]);
