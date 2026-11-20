<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ObjectContextLoadedSourceBoundary;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeCompositionRuleInterface;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeSet;
use Componenta\DI\Attribute\Composition\AttributeUsage;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Reflector;

#[Attribute(Attribute::TARGET_CLASS)]
final class ReadContext {}

final readonly class NoOpRule implements AttributeCompositionRuleInterface
{
    public function validate(AttributeUsage $attribute, AttributeSet $set): void {}
}

final class ReadState
{
    public bool $reading = false;
    public int $loadsWhileReading = 0;
    /** @var list<mixed> */
    public array $values = [];
}

final readonly class Reader implements AttributeHandlerInterface
{
    public function __construct(private ReadState $state) {}

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $this->state->reading = true;
        try {
            $this->state->values[] = $context->parameters['role'];
        } finally {
            $this->state->reading = false;
        }
    }
}

abstract class Product
{
    public static int $constructions = 0;
    public string $state = 'uninitialized';
}

test('mapped object context access checks loaded sources without introducing an autoload point', function (
    bool $uncached,
    bool $preloaded,
): void {
    $suffix = bin2hex(random_bytes(6));
    $attribute = 'Source_' . $suffix;
    $target = 'Target_' . $suffix;
    $source = __NAMESPACE__ . '\\' . $attribute;
    eval(sprintf(
        'namespace %s; #[\Componenta\DI\Attribute\NoConstructor, ReadContext] final class %s extends Product { public function __construct(#[%s("trusted")] string $role) { ++self::$constructions; } }',
        __NAMESPACE__,
        $target,
        $attribute,
    ));
    $callable = eval(sprintf(
        'namespace %s; return static fn(#[\Componenta\DI\Attribute\MapQueryString] %s $product): Product => $product;',
        __NAMESPACE__,
        $target,
    ));
    if (!$callable instanceof Closure) {
        throw new LogicException('Expected the mapped object callable.');
    }
    if ($preloaded) {
        class_alias(EntryId::class, $source);
    }
    Product::$constructions = 0;
    $state = new ReadState();
    $loader = static function (string $requested) use ($source, $state): void {
        if ($requested === $source && $state->reading) {
            ++$state->loadsWhileReading;
            class_alias(EntryId::class, $source);
        }
    };
    $container = (new ContainerBuilder())
        ->addService('trusted', 'trusted value')
        ->addAttributeDefinition(new AttributeDefinition(
            ReadContext::class,
            new Reader($state),
            rules: $uncached ? [new NoOpRule()] : [],
            phase: AttributePhase::BeforeInstantiation,
        ))
        ->build();
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['role' => 'supplied value']);
    spl_autoload_register($loader);
    try {
        if ($preloaded) {
            expect(fn() => $container->call($callable, [ServerRequestInterface::class => $request]))
                ->toThrow(RequestParameterSourceConflictException::class, 'role')
                ->and($state->values)->toBe([]);
        } else {
            $product = $container->call($callable, [ServerRequestInterface::class => $request]);
            expect($product)->toBeInstanceOf(Product::class)
                ->and($state->values)->toBe(['supplied value'])
                ->and(class_exists($source, false))->toBeFalse();
        }
        expect($state->reading)->toBeFalse()
            ->and($state->loadsWhileReading)->toBe(0)
            ->and(Product::$constructions)->toBe(0);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['cached plans' => false, 'uncached plans' => true])
    ->with(['unavailable source' => false, 'loaded source control' => true]);
