<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract;

use Attribute;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\ConfigKey;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Resolver\Attribute\AttributeHandlerInterface;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use LogicException;
use Reflector;

use function Componenta\DI\Tests\Support\container;

#[Attribute(Attribute::TARGET_CLASS)]
final class IdentityAttribute {}

class_alias(IdentityAttribute::class, __NAMESPACE__ . '\\IdentityAttributeAlias');

#[IdentityAttribute]
class CanonicalAttributeTarget
{
    public string $value = '';
}

/** @return non-empty-string */
function lowercaseAttributeTarget(): string
{
    $class = __NAMESPACE__ . '\\LowercaseAttributeTarget';
    if (!class_exists($class, false)) {
        eval('namespace ' . __NAMESPACE__ . '; #[identityattribute] final class LowercaseAttributeTarget extends CanonicalAttributeTarget {}');
    }
    if (!class_exists($class)) {
        throw new LogicException('Expected the differently cased attribute fixture.');
    }
    return $class;
}

final class IdentityAttributeHandler implements AttributeHandlerInterface
{
    public function handle(object $attribute, Reflector $target, ObjectCreationContext $context): void
    {
        $entry = $context->entry;
        if (!$entry instanceof CanonicalAttributeTarget) {
            throw new LogicException('Expected an attribute identity target.');
        }
        $entry->value = 'handled';
    }
}

it('rejects duplicate definitions of the same PHP attribute class', function (string $variant): void {
    $name = match ($variant) {
        'case' => strtolower(IdentityAttribute::class),
        'separator' => '\\' . IdentityAttribute::class,
        'alias' => __NAMESPACE__ . '\\IdentityAttributeAlias',
        default => throw new LogicException('Unknown attribute name variant.'),
    };
    if (!class_exists($name)) {
        throw new LogicException('Expected a valid attribute class name.');
    }

    expect(fn() => container([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            new AttributeDefinition(IdentityAttribute::class),
            new AttributeDefinition($name),
        ],
    ]))->toThrow(InvalidConfigurationException::class, 'already has a semantic definition');
})->with(['case', 'separator', 'alias']);

it('uses one definition for different spellings of the same attribute', function (): void {
    $container = container([
        ConfigKey::ATTRIBUTE_DEFINITIONS => [
            new AttributeDefinition(IdentityAttribute::class, new IdentityAttributeHandler()),
        ],
    ]);

    $lowercase = $container->make(lowercaseAttributeTarget());
    if (!$lowercase instanceof CanonicalAttributeTarget) {
        throw new LogicException('Expected the attribute identity fixture.');
    }

    expect($container->make(CanonicalAttributeTarget::class)->value)->toBe('handled')
        ->and($lowercase->value)->toBe('handled');
});
