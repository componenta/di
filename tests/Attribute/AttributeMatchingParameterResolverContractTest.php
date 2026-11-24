<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Attribute\AttributeMatchingParameterResolverContract;

use Attribute;
use Componenta\DI\ConfigKey;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use LogicException;
use stdClass;

use function Componenta\DI\Tests\Support\container;

interface IdentifiesLabel {}

class LabelBase {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Label extends LabelBase implements IdentifiesLabel {}

test('an attribute matching resolver receives the actual target and invocation context', function (string $attribute): void {
    $resolver = new AttributeMatchingParameterResolver(
        $attribute,
        static function (ParameterTarget $target, ParameterResolutionContext $context): array {
            expect(in_array('resolve', get_class_methods($context), true))->toBeFalse();
            expect(in_array('observeAttributeQueries', get_class_methods($target), true))->toBeFalse();
            $value = $context->resolved[0] ?? null;
            if (!is_string($value)) {
                throw new LogicException('Expected the preceding argument.');
            }
            return [$target->position, $target->name . '=' . $value];
        },
    );
    $di = container([ConfigKey::PARAMETER_RESOLVERS => [1000 => $resolver]]);
    $call = static fn(string $source, #[Label] string $label = 'fallback'): array => [$source, $label];

    expect($di->call($call, ['source' => 'provided']))->toBe(['provided', 'label=provided']);
})->with(['exact declaration' => Label::class, 'base class' => LabelBase::class, 'interface' => IdentifiesLabel::class]);

test('null from a matching resolver lets the next resolver supply the parameter', function (): void {
    $resolver = new AttributeMatchingParameterResolver(
        Label::class,
        static fn(ParameterTarget $target, ParameterResolutionContext $context): ?array => null,
    );
    $di = container([ConfigKey::PARAMETER_RESOLVERS => [1000 => $resolver]]);

    expect($di->call(static fn(#[Label] string $label = 'fallback'): string => $label))->toBe('fallback');
});

test('a matching resolver result is validated by the normal parameter chain', function (): void {
    $resolver = new AttributeMatchingParameterResolver(
        Label::class,
        static fn(ParameterTarget $target, ParameterResolutionContext $context): array => [$target->position, new stdClass()],
    );
    $di = container([ConfigKey::PARAMETER_RESOLVERS => [1000 => $resolver]]);

    expect(static fn(): mixed => $di->call(static fn(#[Label] string $label = 'fallback'): string => $label))
        ->toThrow(ResolutionException::class);
});

test('a matching resolver rejects an empty attribute name at registration', function (): void {
    expect(static fn(): AttributeMatchingParameterResolver => new AttributeMatchingParameterResolver(
        '',
        static fn(ParameterTarget $target, ParameterResolutionContext $context): ?array => null,
    ))->toThrow(InvalidConfigurationException::class, 'attribute name must not be empty');
});
