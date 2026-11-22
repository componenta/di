<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ResolutionInputIsolation;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Reflector;
use stdClass;

#[Attribute(Attribute::TARGET_CLASS)]
final class ObserveArguments {}

#[ObserveArguments]
final readonly class Product
{
    /** @param array<array-key,mixed> $payload */
    public function __construct(public array $payload) {}
}

/** @param array<array-key,mixed> $arguments */
function formatLocalCopy(array $arguments, bool $cycle): void
{
    $payload = $arguments['payload'] ?? null;
    if (!is_array($payload)) {
        throw new LogicException('Expected payload input.');
    }
    if ($cycle) {
        $loop = $payload['self'] ?? null;
        if (!is_array($loop)) {
            throw new LogicException('Expected the recursive payload.');
        }
        $loop['label'] = 'local formatting';
        $payload['self'] = $loop;
    } else {
        $payload['label'] = 'local formatting';
    }
    $arguments['payload'] = $payload;
}

/** @param array<array-key,mixed> $payload */
function observedLabel(array $payload, bool $cycle): mixed
{
    if (!$cycle) {
        return $payload['label'] ?? null;
    }
    $loop = $payload['self'] ?? null;
    if (!is_array($loop)) {
        throw new LogicException('Expected the recursive payload.');
    }
    return $loop['label'] ?? null;
}

test('editing an argument snapshot cannot change nested input for a later resolver or the caller', function (bool $constructor, bool $cycle): void {
    $container = new ContainerBuilder()
        ->addAttributeDefinition(new AttributeDefinition(ObserveArguments::class, new class ($cycle) implements AttributeHandlerInterface {
            public function __construct(private readonly bool $cycle) {}

            public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
            {
                formatLocalCopy($context->parameters, $this->cycle);
                if ($this->cycle && is_array($payload = $context->parameters['payload'] ?? null) && observedLabel($payload, true) !== 'original') {
                    throw new LogicException('A second read must return the original snapshot.');
                }
            }
        }, phase: AttributePhase::BeforeInstantiation))
        ->addParameterResolver(new class ($cycle) implements ParameterResolverInterface {
            public function __construct(private readonly bool $cycle) {}

            public function supports(ParameterTarget $target): bool
            {
                return $target->name === 'prefix';
            }

            public function resolveParameter(ParameterTarget $target, ParameterResolutionContext $context): array
            {
                formatLocalCopy($context->provided, $this->cycle);
                return [$target->position, 'resolved'];
            }
        }, 10_000)
        ->build();
    $label = 'original';
    $identity = new stdClass();
    $payload = ['label' => &$label, 'identity' => $identity];
    if ($cycle) {
        $payload['self'] = &$payload;
    }

    $arguments = ['payload' => $payload];
    $result = $constructor
        ? $container->make(Product::class, $arguments)
        : $container->call(static fn(string $prefix, array $payload): Product => new Product($payload), $arguments);

    if (!$result instanceof Product) {
        throw new LogicException('Expected a product.');
    }
    expect(observedLabel($result->payload, $cycle))->toBe('original')
        ->and($label)->toBe('original')
        ->and($result->payload['identity'])->toBe($identity);
})->with(['make' => true, 'call' => false])
    ->with(['nested slot' => false, 'recursive array' => true]);

test('a callable receives literal nested references after argument selection', function (): void {
    $container = new ContainerBuilder()->build();
    $label = 'original';
    $arguments = ['payload' => ['label' => &$label]];

    $actual = $container->call(static function (array $payload): string {
        $payload['label'] = 'set by callable';
        return $payload['label'];
    }, $arguments);

    expect($actual)->toBe('set by callable')
        ->and($label)->toBe('set by callable');
});
