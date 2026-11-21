<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\LateProxyStrategy;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionClass;

#[Attribute(Attribute::TARGET_CLASS)]
final class UncachedMarker {}

final readonly class UncachedRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class State
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        public readonly string $proxyAlias,
        public readonly string $revealAt,
    ) {}

    public function revealProxy(): void
    {
        if (!class_exists($this->proxyAlias, false)) {
            class_alias(Proxy::class, $this->proxyAlias);
        }
    }
}

abstract class Product
{
    public bool $ready = false;

    public function __construct(State $state)
    {
        $state->events[] = 'constructor';
        if ($state->revealAt === 'constructor') {
            $state->revealProxy();
        }
        $this->ready = true;
    }
}

test('a late class Proxy cannot retroactively change a completed eager creation strategy', function (string $revealAt, bool $uncached): void {
    $suffix = bin2hex(random_bytes(6));
    $attribute = 'LateProxy_' . $suffix;
    $target = 'Target_' . $suffix;
    eval(sprintf(
        'namespace %s; #[%s] final class %s extends Product {}',
        __NAMESPACE__,
        $attribute,
        $target,
    ));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!is_a($class, Product::class, true)) {
        throw new LogicException('Expected a late proxy strategy fixture.');
    }
    $state = new State(__NAMESPACE__ . '\\' . $attribute, $revealAt);
    $builder = (new ContainerBuilder())->addService(State::class, $state);
    if ($uncached) {
        $builder->addAttributeDefinition(new AttributeDefinition(UncachedMarker::class, rules: [new UncachedRule()]));
    }
    $pipeline = $builder->build()->getAs(ObjectPipeline::class, ObjectPipeline::class);
    $pipeline->prepare($class);
    $create = static fn(): object => $pipeline->create($class, configure: static function () use ($state): void {
        $state->events[] = 'configure';
        if ($state->revealAt === 'configure') {
            $state->revealProxy();
        }
    });

    expect($create)->toThrow(AttributeCompositionException::class, 'BeforeInstantiation completed')
        ->and($state->events)->toBe($revealAt === 'constructor' ? ['constructor'] : ['constructor', 'configure']);

    $state->events = [];
    $entry = $create();
    if (!$entry instanceof Product) {
        throw new LogicException('Expected a newly created proxy.');
    }
    expect(new ReflectionClass($class)->isUninitializedLazyObject($entry))->toBeTrue()
        ->and($state->events)->toBe([])
        ->and($entry->ready)->toBeTrue()
        ->and($state->events)->toBe(['constructor', 'configure'])
        ->and(new ReflectionClass($class)->isUninitializedLazyObject($entry))->toBeFalse();
})->with(['constructor', 'configure'])
    ->with(['cached plans' => false, 'uncached plans' => true]);
