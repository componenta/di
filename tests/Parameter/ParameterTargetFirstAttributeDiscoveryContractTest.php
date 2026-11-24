<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Parameter\ParameterTargetFirstAttributeDiscoveryContract;

use Attribute;
use Componenta\DI\Resolver\Target\ParameterTarget;
use DomainException;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
final class ObservedLabel
{
    /** @var list<string> */
    public static array $events = [];

    public function __construct(public readonly string $value)
    {
        self::$events[] = 'construct';
    }
}

function targetWithMissingAfterFirstMatch(string $missing): ParameterTarget
{
    $function = 'consume_' . bin2hex(random_bytes(5));
    eval(sprintf(
        'namespace %s; function %s(#[ObservedLabel("first"), \\%s] string $value): void {}',
        __NAMESPACE__,
        $function,
        $missing,
    ));
    return new ParameterTarget(new ReflectionParameter(__NAMESPACE__ . '\\' . $function, 0));
}

beforeEach(function (): void {
    ObservedLabel::$events = [];
});

afterEach(function (): void {
    ObservedLabel::$events = [];
});

test('first attribute selection completes later discovery before constructing its known match', function (): void {
    $missing = __NAMESPACE__ . '\\Later_' . bin2hex(random_bytes(5));
    $target = targetWithMissingAfterFirstMatch($missing);
    $loader = static function (string $class) use ($missing): void {
        if ($class === $missing) {
            ObservedLabel::$events[] = 'load';
        }
    };
    spl_autoload_register($loader);

    try {
        $attribute = $target->firstAttribute(ObservedLabel::class);

        expect($attribute)->toBeInstanceOf(ObservedLabel::class)
            ->and($attribute?->value)->toBe('first')
            ->and(ObservedLabel::$events)->toBe(['load', 'construct']);
    } finally {
        spl_autoload_unregister($loader);
    }
});

test('later attribute discovery failures precede known instance construction and allow recovery', function (): void {
    $missing = __NAMESPACE__ . '\\FailingLater_' . bin2hex(random_bytes(5));
    $target = targetWithMissingAfterFirstMatch($missing);
    $failure = new DomainException('Later metadata cannot be loaded yet.');
    $state = new class () {
        public bool $fail = true;
    };
    $loader = static function (string $class) use ($missing, $failure, $state): void {
        if ($class !== $missing) {
            return;
        }
        ObservedLabel::$events[] = $state->fail ? 'load:fail' : 'load:recover';
        if ($state->fail) {
            throw $failure;
        }
    };
    spl_autoload_register($loader);

    try {
        $caught = null;
        try {
            $target->firstAttribute(ObservedLabel::class);
        } catch (DomainException $exception) {
            $caught = $exception;
        }

        expect($caught)->toBe($failure)
            ->and(ObservedLabel::$events)->toBe(['load:fail']);

        $state->fail = false;
        $attribute = $target->firstAttribute(ObservedLabel::class);

        expect($attribute)->toBeInstanceOf(ObservedLabel::class)
            ->and($attribute?->value)->toBe('first')
            ->and(ObservedLabel::$events)->toBe(['load:fail', 'load:recover', 'construct']);
    } finally {
        spl_autoload_unregister($loader);
    }
});
