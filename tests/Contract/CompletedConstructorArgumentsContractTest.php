<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CompletedConstructorArguments;

use Attribute;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Exception\RequestParameterSourceConflictException;
use Componenta\DI\Internal\Resolver\Parameter\Request\MappedRequestContext;
use Componenta\DI\Object\ObjectPipeline;
use Componenta\DI\Resolver\Parameter\ParameterSourceAttributeInterface;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;

abstract class Label
{
    public static int $constructions = 0;
    public string $value;

    public static function reveal(string $attribute): void
    {
        if (!class_exists($attribute, false)) {
            class_alias(EntryId::class, $attribute);
        }
    }
}

test('a later change to a consumed constructor source rejects the object without replaying its construction', function (
    string $strategy,
    bool $setup,
): void {
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\Label_' . $suffix;
    $attribute = 'PendingLabel_' . $suffix;
    $fullAttribute = __NAMESPACE__ . '\\' . $attribute;
    $attributes = match ($strategy) {
        'lazy' => '#[\\Componenta\\DI\\Attribute\\Lazy]',
        'proxy' => '#[\\Componenta\\DI\\Attribute\\Proxy]',
        default => '',
    };
    if ($setup) {
        $attributes .= '#[\\Componenta\\DI\\Attribute\\SetUp("ready")]';
    }
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        %s final class Label_%s extends Label {
            public function __construct(#[%s('palette.label')] string $value = 'fallback') {
                ++Label::$constructions;
                $this->value = $value;
                %s
            }

            public function ready(): void {
                self::reveal(%s);
            }
        }
        PHP,
        __NAMESPACE__,
        $attributes,
        $suffix,
        $attribute,
        $setup ? '' : '$this->ready();',
        var_export($fullAttribute, true),
    ));
    $di = (new ContainerBuilder())->addService('palette.label', 'configured')->build();
    Label::$constructions = 0;
    $read = static function () use ($di, $class): string {
        $entry = $di->get($class);
        if (!$entry instanceof Label) {
            throw new LogicException('Expected a label fixture.');
        }
        return $entry->value;
    };

    expect($read)->toThrow(AttributeCompositionException::class, '$value')
        ->and(Label::$constructions)->toBe(1);
    $fresh = $di->make($class);
    if (!$fresh instanceof Label) {
        throw new LogicException('Expected a fresh label fixture.');
    }
    expect($fresh->value)->toBe('configured')
        ->and(Label::$constructions)->toBe(2);
})->with([
    'eager constructor' => ['eager', false],
    'setup' => ['eager', true],
    'lazy constructor' => ['lazy', false],
    'proxy setup' => ['proxy', true],
]);

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LabelSource implements ParameterSourceAttributeInterface {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LabelMetadata {}

test('publishing a DTO validates the source of its consumed constructor values', function (string $strategy, bool $matches): void {
    Label::$constructions = 0;
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\MappedLabel_' . $suffix;
    $attribute = __NAMESPACE__ . '\\AvailableAfter_' . $suffix;
    $classAttribute = match ($strategy) {
        'lazy' => '#[\\Componenta\\DI\\Attribute\\Lazy]',
        'proxy' => '#[\\Componenta\\DI\\Attribute\\Proxy]',
        default => '',
    };
    eval(sprintf(
        'namespace %s; %s final class MappedLabel_%s extends Label { public function __construct(#[AvailableAfter_%s] string $value) { ++Label::$constructions; $this->value = $value; %s } }',
        __NAMESPACE__,
        $classAttribute,
        $suffix,
        $suffix,
        $strategy === 'constructor' ? 'class_alias(' . var_export($matches ? LabelSource::class : LabelMetadata::class, true) . ', ' . var_export($attribute, true) . ');' : '',
    ));
    if (!is_a($class, Label::class, true)) {
        throw new LogicException('Expected a mapped label class.');
    }
    $di = (new ContainerBuilder())->build();
    $pipeline = $di->getAs(ObjectPipeline::class, ObjectPipeline::class);
    $fields = ['value' => 'example'];
    $source = MappedRequestContext::with($fields, $fields);
    $callbacks = 0;
    $configure = $strategy === 'constructor' ? null : static function (object $entry) use ($attribute, $matches, &$callbacks): void {
        ++$callbacks;
        class_alias($matches ? LabelSource::class : LabelMetadata::class, $attribute);
    };
    $read = static function () use ($pipeline, $class, $source, $configure): string {
        $entry = $pipeline->create($class, $source, $configure);
        if (!$entry instanceof Label) {
            throw new LogicException('Expected a label fixture.');
        }
        return $entry->value;
    };

    if ($matches) {
        expect($read)->toThrow(RequestParameterSourceConflictException::class, 'value');
    } else {
        expect($read())->toBe('example');
    }
    expect(Label::$constructions)->toBe(1)
        ->and($callbacks)->toBe($strategy === 'constructor' ? 0 : 1);
})->with(['eager constructor' => 'constructor', 'eager configure' => 'eager', 'lazy configure' => 'lazy', 'proxy configure' => 'proxy'])
    ->with(['source revealed' => true, 'unrelated metadata' => false]);

test('completed setup input is checked after final configuration of the same object attempt', function (string $strategy): void {
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\SetupMapped_' . $suffix;
    $attribute = __NAMESPACE__ . '\\PendingSetup_' . $suffix;
    $classAttribute = match ($strategy) {
        'lazy' => '#[\\Componenta\\DI\\Attribute\\Lazy]',
        'proxy' => '#[\\Componenta\\DI\\Attribute\\Proxy]',
        default => '',
    };
    eval(sprintf(
        'namespace %s; %s #[\\Componenta\\DI\\Attribute\\SetUp("readInput")] final class SetupMapped_%s extends Label { public function readInput(#[PendingSetup_%s] string $value): void { $this->value = $value; } }',
        __NAMESPACE__,
        $classAttribute,
        $suffix,
        $suffix,
    ));
    if (!is_a($class, Label::class, true)) {
        throw new LogicException('Expected a configured label class.');
    }
    $pipeline = (new ContainerBuilder())->build()->getAs(ObjectPipeline::class, ObjectPipeline::class);
    $data = ['value' => 'example'];
    $configured = [];
    $configure = static function (object $entry) use (&$configured, $attribute): void {
        if (!$entry instanceof Label) {
            throw new LogicException('Expected the constructed label.');
        }
        $configured[] = $entry->value;
        class_alias(LabelSource::class, $attribute);
    };
    $read = static function () use ($pipeline, $class, $data, $configure): string {
        $entry = $pipeline->create($class, MappedRequestContext::with($data, $data), $configure);
        if (!$entry instanceof Label) {
            throw new LogicException('Expected a configured label.');
        }
        return $entry->value;
    };

    expect($read)->toThrow(RequestParameterSourceConflictException::class, 'value')
        ->and($configured)->toBe(['example']);
})->with(['eager', 'lazy', 'proxy']);
