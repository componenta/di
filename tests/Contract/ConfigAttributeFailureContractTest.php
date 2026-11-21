<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ConfigAttributeFailure;

use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Config\LazyValue;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use RuntimeException;

final class PropertyTarget
{
    public static int $constructions = 0;

    #[ConfigAttribute('lazy.value')]
    public string $value;

    public function __construct()
    {
        ++self::$constructions;
    }
}

test('Config attribute failures preserve owned exceptions and diagnose foreign failures before retrying', function (bool $owned, bool $property): void {
    PropertyTarget::$constructions = 0;
    $cause = $owned
        ? new ResolutionException('owned configuration failure')
        : new RuntimeException('foreign configuration failure');
    $attempts = 0;
    $value = new LazyValue(static function () use ($cause, &$attempts): string {
        if (++$attempts === 1) {
            throw $cause;
        }
        return 'configured';
    });
    $container = (new ContainerBuilder(new Config(['lazy.value' => $value], new Environment([]))))->build();
    $callable = static fn(#[ConfigAttribute('lazy.value')] string $value): string => $value;
    $resolve = $property
        ? static fn(): string => $container->getAs(PropertyTarget::class, PropertyTarget::class)->value
        : static fn(): mixed => $container->call($callable);
    $failure = null;

    try {
        $resolve();
    } catch (ResolutionException $error) {
        $failure = $error;
    }

    if (!$failure instanceof ResolutionException) {
        throw new LogicException('A failing configuration callback must stop attribute resolution.');
    }
    expect($attempts)->toBe(1);
    if ($owned) {
        expect($failure)->toBe($cause)
            ->and($failure->getPrevious())->toBeNull();
    } else {
        expect($failure->getPrevious())->toBe($cause);
        if ($property) {
            expect($failure->propertyClass)->toBe(PropertyTarget::class)
                ->and($failure->propertyName)->toBe('value')
                ->and($failure->propertyType)->toBe('string');
        } else {
            expect($failure->parameterName)->toBe('value')
                ->and($failure->parameterPosition)->toBe(0)
                ->and($failure->parameterType)->toBe('string');
        }
    }

    expect($resolve())->toBe('configured')
        ->and($resolve())->toBe('configured')
        ->and($attempts)->toBe(2)
        ->and(PropertyTarget::$constructions)->toBe($property ? 2 : 0);
})->with(['owned DI exception' => true, 'foreign callback exception' => false])
    ->with(['property' => true, 'parameter' => false]);
