<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\CompletedParameterClassification;

use Attribute;
use Componenta\DI\Exception\AttributeCompositionException;
use Componenta\DI\Resolver\Parameter\AttributeMatchingParameterResolver;
use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use Componenta\DI\Tests\Support\ContainerBuilder;
use stdClass;

use function Componenta\DI\Tests\Support\fixtureInstance;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LabelSource {}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class LabelMetadata {}

abstract class Label
{
    public static int $constructions = 0;
    public string $value;

    public static function load(string $alias, string $attribute): void
    {
        if (!class_exists($alias, false)) {
            class_alias($attribute, $alias);
        }
    }
}

test('late attribute classification cannot publish a stale shared service', function (
    bool $queryIsAlias,
    bool $loadInConstructor,
    bool $related,
    int $priority,
): void {
    $suffix = bin2hex(random_bytes(5));
    $class = __NAMESPACE__ . '\\Label_' . $suffix;
    $alias = __NAMESPACE__ . '\\Deferred_' . $suffix;
    $declared = $queryIsAlias ? LabelSource::class : $alias;
    $query = $queryIsAlias ? $alias : LabelSource::class;
    $source = $related ? LabelSource::class : LabelMetadata::class;
    $load = sprintf('self::load(%s, %s);', var_export($alias, true), var_export($source, true));
    eval(sprintf(
        <<<'PHP'
        namespace %s;
        final class Label_%s extends Label {
            public function __construct(
                #[\%s] string $value = 'fallback',
                #[\Componenta\DI\Attribute\EntryId("loader")] ?object $loader = null,
            ) {
                ++Label::$constructions;
                $this->value = $value;
                %s
            }
        }
        PHP,
        __NAMESPACE__,
        $suffix,
        $declared,
        $loadInConstructor ? $load : '',
    ));
    Label::$constructions = 0;
    $resolverCalls = 0;
    $factoryCalls = 0;
    $container = (new ContainerBuilder())
        ->addParameterResolver(new AttributeMatchingParameterResolver(
            $query,
            static function (ParameterTarget $target, ParameterResolutionContext $context) use (&$resolverCalls): array {
                ++$resolverCalls;
                return [$target->position, 'from source'];
            },
        ), $priority)
        ->addFactory('loader', static function () use ($loadInConstructor, $alias, $source, &$factoryCalls): object {
            ++$factoryCalls;
            if (!$loadInConstructor) {
                Label::load($alias, $source);
            }
            return new stdClass();
        })->build();
    $get = static fn(): Label => fixtureInstance($container->get($class), Label::class);
    $changesSelection = $related && $priority > 200;

    if ($changesSelection) {
        expect($get)->toThrow(AttributeCompositionException::class, '$value')
            ->and(Label::$constructions)->toBe($loadInConstructor ? 1 : 0)
            ->and($resolverCalls)->toBe(0);
    }

    $result = $get();
    expect($result->value)->toBe($changesSelection ? 'from source' : 'fallback')
        ->and($get())->toBe($result)
        ->and(Label::$constructions)->toBe($changesSelection && $loadInConstructor ? 2 : 1)
        ->and($factoryCalls)->toBe(1)
        ->and($resolverCalls)->toBe($changesSelection ? 1 : 0);
})->with([
    'declaration revealed by a later dependency' => [false, false, true, 1000],
    'query alias revealed by a later dependency' => [true, false, true, 1000],
    'declaration revealed inside the constructor' => [false, true, true, 1000],
    'query alias revealed inside the constructor' => [true, true, true, 1000],
    'unrelated metadata' => [false, false, false, 1000],
    'unrelated query alias' => [true, true, false, 1000],
    'matching resolver after the selected default' => [false, false, true, 0],
]);
