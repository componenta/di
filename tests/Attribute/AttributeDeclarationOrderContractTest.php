<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\AttributeDeclarationOrderContract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class Label
{
    public function __construct(public string $value) {}
}

#[Label('first'), Label('second')]
final class Subject {}

function parameter(#[Label('first'), Label('second')] string $value): void {}

/** @extends ReflectionClass<object> */
final class ReversedClassAttributes extends ReflectionClass
{
    /** @return array<int, ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return array_reverse(parent::getAttributes($name, $flags), true);
    }
}

final class ReversedParameterAttributes extends ReflectionParameter
{
    /** @return array<int, ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return array_reverse(parent::getAttributes($name, $flags), true);
    }
}

test('independent attributes retain declaration order when a reflector returns reversed numeric indexes', function (bool $parameter): void {
    $registry = new AttributeDefinitionRegistry();
    $registry->register(new AttributeDefinition(Label::class));
    $target = $parameter
        ? new ReversedParameterAttributes(__NAMESPACE__ . '\\parameter', 0)
        : new ReversedClassAttributes(Subject::class);

    $plan = (new AttributePlanBuilder($registry))->build($target);

    expect(array_map(static fn(AttributeUsage $usage): mixed => $usage->arguments[0], $plan->usages))
        ->toBe(['first', 'second']);
})->with(['class reflector' => false, 'parameter reflector' => true]);

/** @extends ReflectionAttribute<Label> */
final class SyntheticLabel extends ReflectionAttribute
{
    public int $reads = 0;
    public string $value = 'initial';

    public function __construct() {}

    public function getName(): string
    {
        ++$this->reads;
        return Label::class;
    }

    /** @return array{string} */
    public function getArguments(): array
    {
        return [$this->value];
    }

    public function isRepeated(): bool
    {
        return false;
    }

    public function newInstance(): Label
    {
        return new Label($this->value);
    }
}

/** @extends ReflectionClass<Subject> */
final class SyntheticClassAttributes extends ReflectionClass
{
    public function __construct(private readonly SyntheticLabel $label)
    {
        parent::__construct(Subject::class);
    }

    /** @return list<ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return [$this->label];
    }
}

final class SyntheticParameterAttributes extends ReflectionParameter
{
    public function __construct(private readonly SyntheticLabel $label)
    {
        parent::__construct(__NAMESPACE__ . '\\parameter', 0);
    }

    /** @return list<ReflectionAttribute<object>> */
    public function getAttributes(?string $name = null, int $flags = 0): array
    {
        return [$this->label];
    }
}

test('validated attribute metadata rejects synthetic declarations before reading them', function (string $entry): void {
    $registry = new AttributeDefinitionRegistry();
    $definition = new AttributeDefinition(Label::class);
    $registry->register($definition);
    $builder = new AttributePlanBuilder($registry);
    $attribute = new SyntheticLabel();

    $read = match ($entry) {
        'class plan' => static fn() => $builder->build(new SyntheticClassAttributes($attribute)),
        'parameter plan' => static fn() => $builder->build(new SyntheticParameterAttributes($attribute)),
        'parameter target' => static fn() => new ParameterTarget(new SyntheticParameterAttributes($attribute)),
        'usage' => static fn() => new AttributeUsage($attribute, $definition, new ReflectionClass(Subject::class), 0),
        default => throw new LogicException('Unknown attribute metadata fixture.'),
    };

    expect($read)->toThrow(AttributeCompositionException::class, 'native ReflectionAttribute')
        ->and($attribute->reads)->toBe(0);
})->with(['class plan', 'parameter plan', 'parameter target', 'usage']);
