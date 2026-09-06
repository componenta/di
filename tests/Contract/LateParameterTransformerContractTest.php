<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributePlan;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Container;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Attribute\ParameterAttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\ParameterAttributeValue;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use ReflectionProperty;
use Reflector;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
class AppendValue
{
    public function __construct(public string $suffix) {}
}

final class AppendValueHandler implements AttributeHandlerInterface, ParameterAttributeHandlerInterface
{
    /** @param Closure():void|null $load */
    public function __construct(private ?Closure $load = null) {}

    public function resolveParameter(
        object $attribute,
        ParameterTarget $target,
        ParameterResolutionContext $context,
        AttributePlan $plan,
        ParameterAttributeValue $value,
    ): ParameterAttributeValue {
        if (!$attribute instanceof AppendValue || !is_string($value->value)) {
            throw new LogicException('Expected a string transformer input.');
        }
        return ParameterAttributeValue::resolved($this->transform($attribute, $value->value));
    }

    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        if (!$target instanceof ReflectionProperty || !$attribute instanceof AppendValue) {
            throw new LogicException('Expected a property transformer.');
        }
        $value = $context->readProperty($target);
        if (!is_string($value)) {
            throw new LogicException('Expected a string property.');
        }
        $context->writeProperty($target, $this->transform($attribute, $value));
    }
    private function transform(AppendValue $attribute, string $value): string
    {
        $this->load?->__invoke();
        $this->load = null;
        return $value . $attribute->suffix;
    }
}

#[Attribute(Attribute::TARGET_CLASS)]
final class TransformerBootstrapExtension {}

abstract class TransformedValueState
{
    public string $value;
}

it('applies transformers loaded by the current source without replaying completed handlers', function (string $kind, bool $preloaded, bool $bootstrap = false, bool $loadDuringTransformer = false): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'LateAppend_' . $suffix;
    $target = 'TransformedValue_' . $suffix;
    $attributes = sprintf('#[\Componenta\DI\Attribute\EntryId("value"), AppendValue(":known"), %s(":late")]', $attribute);
    $declaration = match ($kind) {
        'property' => $attributes . ' public string $value;',
        'constructor' => 'public function __construct(' . $attributes . ' string $value) { $this->value = $value; }',
        'callable' => 'public static function read(' . $attributes . ' string $value): string { return $value; }',
        default => throw new LogicException('Unknown target kind.'),
    };
    eval(sprintf('namespace %s; final class %s extends TransformedValueState { %s }', __NAMESPACE__, $target, $declaration));
    $class = __NAMESPACE__ . '\\' . $target;
    if (!class_exists($class)) {
        throw new LogicException('Expected the transformer fixture class.');
    }
    $load = static function () use ($attribute): void {
        eval(sprintf('namespace %s; #[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)] final class %s extends AppendValue {}', __NAMESPACE__, $attribute));
    };
    if ($preloaded) {
        $load();
    }
    $sources = 0;
    $builder = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(AppendValue::class, new AppendValueHandler($loadDuringTransformer ? $load : null), [ValueTransformer::class], after: [ValueProvider::class]))
        ->addFactory('value', static function () use ($preloaded, $load, $loadDuringTransformer, &$sources): string {
            ++$sources;
            if (!$preloaded && !$loadDuringTransformer) {
                $load();
            }
            return 'source';
        });

    $results = [];
    $resolve = static function (Container $container) use ($kind, $class): mixed {
        $result = $kind === 'callable' ? $container->call([$class, 'read']) : $container->make($class);
        return $result instanceof TransformedValueState ? $result->value : $result;
    };
    if ($bootstrap) {
        $builder->addAttributeDefinition(static function (Container $container) use ($resolve, &$results): AttributeDefinition {
            $results[] = $resolve($container);
            return new AttributeDefinition(TransformerBootstrapExtension::class);
        });
    }
    $container = $builder->build();
    for ($i = count($results); $i < 2; ++$i) {
        $results[] = $resolve($container);
    }
    expect($results)->toBe(['source:known:late', 'source:known:late'])->and($sources)->toBe(1);
})->with([
    'constructor late' => ['constructor', false],
    'constructor preloaded' => ['constructor', true],
    'callable late' => ['callable', false],
    'callable preloaded' => ['callable', true],
    'property late' => ['property', false],
    'property preloaded' => ['property', true],
    'bootstrap constructor' => ['constructor', false, true],
    'bootstrap callable' => ['callable', false, true],
    'loaded by constructor transformer' => ['constructor', false, false, true],
    'loaded by callable transformer' => ['callable', false, false, true],
]);

it('rechecks mapped input provenance when the current transformer loads a source attribute', function (): void {
    $suffix = bin2hex(random_bytes(5));
    $attribute = 'LateMappedSource_' . $suffix;
    $target = 'LateMappedTarget_' . $suffix;
    $function = 'readLateMapped_' . $suffix;
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class %s extends TransformedValueState {
            public function __construct(#[AppendValue(":known"), %s(":late")] string $value) { $this->value = $value; }
        }
        function %s(#[\Componenta\DI\Attribute\MapRequestPayload] %s $dto): string { return $dto->value; }
        PHP,
        __NAMESPACE__,
        $target,
        $attribute,
        $function,
        $target,
    ));
    $load = static function () use ($attribute): void {
        eval(sprintf('namespace %s; #[\Attribute(\Attribute::TARGET_PARAMETER)] final class %s extends AppendValue implements \Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface {}', __NAMESPACE__, $attribute));
    };
    $container = (new ContainerBuilder())
        ->addAttributeDefinition(new AttributeDefinition(AppendValue::class, new AppendValueHandler($load), [ValueTransformer::class]))
        ->build();
    $params = [
        \Psr\Http\Message\ServerRequestInterface::class => new \Nyholm\Psr7\ServerRequest('POST', '/')
            ->withParsedBody(['value' => 'payload']),
    ];

    expect(fn() => $container->call(__NAMESPACE__ . '\\' . $function, $params))
        ->toThrow(\Componenta\DI\Exception\RequestParameterSourceConflictException::class, 'value');
});
