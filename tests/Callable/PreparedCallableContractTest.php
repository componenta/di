<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Callable\PreparedCallableContract;

use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\PreparedCallable;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

use function Componenta\DI\Tests\Support\container;

test('a prepared call uses supplied native values and does not change subsequent DI calls', function (): void {
    $container = container();
    $first = new ServerRequest('GET', '/first');
    $second = new ServerRequest('GET', '/second');
    $target = static fn(
        #[CurrentRequest] ServerRequestInterface $request,
        string ...$arguments,
    ): array => [$request, $arguments];

    expect($container->call(new PreparedCallable($target), [$first, 'one', 'named' => 'two']))
        ->toBe([$first, ['one', 'named' => 'two']])
        ->and($container->call($target, [ServerRequestInterface::class => $second, 'arguments' => ['normal']]))
        ->toBe([$second, ['normal']])
        ->and($container->call(new PreparedCallable($target), ['request' => $second, 'named' => 'next']))
        ->toBe([$second, ['named' => 'next']]);
});

test('prepared calls preserve omitted defaults and the target exception instance', function (): void {
    $container = container();
    $failure = new \DomainException('Prepared target failed.');
    $target = static function (string $left = 'default', string $right = 'right') use ($failure): string {
        if ($right === 'fail') {
            throw $failure;
        }
        return $left . ':' . $right;
    };
    $prepared = new PreparedCallable($target);

    expect($container->call($prepared, ['right' => 'value']))->toBe('default:value');

    $caught = null;
    try {
        $container->call($prepared, ['right' => 'fail']);
    } catch (\DomainException $actual) {
        $caught = $actual;
    }
    expect($caught)->toBe($failure);
});

final class ReferencedOwner
{
    public function __construct(private readonly string $value) {}

    public function original(): string
    {
        return $this->value;
    }

    public function redirected(): string
    {
        return 'redirected';
    }
}

test('prepared callables preserve the original owner and method from referenced array slots', function (): void {
    $container = container();
    $original = new ReferencedOwner('original');
    $owner = $original;
    $method = 'original';
    $callable = [&$owner, &$method];
    $prepared = new PreparedCallable($callable);

    $owner = new ReferencedOwner('replacement');
    $method = 'redirected';

    expect($prepared->callable)->toBe([$original, 'original'])
        ->and($container->call($prepared))->toBe('original')
        ->and($prepared())->toBe('original');

    $method = 'missing';
    expect($container->call($prepared))->toBe('original');
});

test('prepared callables preserve native object identity and state', function (): void {
    $container = container();
    $owner = new class () {
        public string $value = 'first';

        public function read(string $suffix = 'default'): string
        {
            return $this->value . ':' . $suffix;
        }
    };
    $prepared = new PreparedCallable([$owner, 'read']);

    expect($prepared->callable)->toBe([$owner, 'read'])
        ->and($container->call($prepared))->toBe('first:default');
    $owner->value = 'second';
    expect($container->call($prepared, ['suffix' => 'current']))->toBe('second:current');
});

final class StaticOwner
{
    public static function original(): string
    {
        return 'original';
    }

    public static function redirected(): string
    {
        return 'original:redirected';
    }
}

final class ReplacementOwner
{
    public static function original(): string
    {
        return 'replacement';
    }

    public static function redirected(): string
    {
        return 'replacement:redirected';
    }
}

final class AutoloadAttempt
{
    public bool $fail = true;
    public int $loads = 0;
}

test('prepared callables capture referenced routes before validation can run an autoloader', function (
    string $mutation,
    ?string $nextResult,
): void {
    $requested = __NAMESPACE__ . '\\PendingOwner_' . bin2hex(random_bytes(5));
    $owner = $requested;
    $method = 'original';
    /** @var callable-array $specification */
    $specification = [&$owner, &$method];
    $loads = 0;
    $loader = static function (string $class) use ($requested, $mutation, &$owner, &$method, &$loads): void {
        if ($class !== $requested) {
            return;
        }
        ++$loads;
        class_alias(StaticOwner::class, $class);
        if ($mutation === 'owner' || $mutation === 'both') {
            $owner = ReplacementOwner::class;
        } elseif ($mutation === 'invalid owner') {
            $owner = '';
        }
        if ($mutation === 'method' || $mutation === 'both') {
            $method = 'redirected';
        }
    };
    spl_autoload_register($loader);

    try {
        $prepared = new PreparedCallable($specification);
        expect($prepared->callable)->toBe([$requested, 'original'])
            ->and($prepared())->toBe('original')
            ->and(container()->call($prepared))->toBe('original')
            ->and($loads)->toBe(1);

        if ($nextResult === null) {
            expect(static fn(): PreparedCallable => new PreparedCallable($specification))->toThrow(\TypeError::class);
        } else {
            expect((new PreparedCallable($specification))())->toBe($nextResult);
        }
        expect($loads)->toBe(1);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with([
    'receiver changed' => ['owner', 'replacement'],
    'method changed' => ['method', 'original:redirected'],
    'both changed' => ['both', 'replacement:redirected'],
    'receiver invalidated' => ['invalid owner', null],
]);

test('prepared callables reject malformed inputs during construction', function (mixed $input): void {
    $create = static function () use ($input): PreparedCallable {
        // @phpstan-ignore argument.type (Intentionally invalid public callable input.)
        return new PreparedCallable($input);
    };

    expect($create)->toThrow(\TypeError::class);
})->with([
    'empty array' => [[]],
    'missing method' => [[StaticOwner::class, 'missing']],
    'non-string method' => [[StaticOwner::class, 12]],
    'invalid receiver' => [[12, 'original']],
    'sparse array' => [[1 => StaticOwner::class, 2 => 'original']],
    'extra element' => [[StaticOwner::class, 'original', 'extra']],
    'missing function' => [__NAMESPACE__ . '\\missingFunction'],
    'non-invokable object' => [new \stdClass()],
    'private adapter method array' => [[PreparedCallable::class, 'snapshotCallable']],
    'private adapter method string' => [PreparedCallable::class . '::snapshotCallable'],
]);

test('prepared callable validation preserves autoload failures and permits a fresh attempt', function (
    bool $array,
    string $kind,
): void {
    $requested = __NAMESPACE__ . '\\FailingOwner_' . bin2hex(random_bytes(5));
    /** @var (array{non-falsy-string,'original'}&callable)|(non-falsy-string&callable) $specification */
    $specification = $array ? [$requested, 'original'] : $requested . '::original';
    $cause = match ($kind) {
        'type error' => new \TypeError('Prepared callable autoload failed.'),
        'DI exception' => new \Componenta\DI\Exception\ResolutionException('Prepared callable autoload failed.'),
        default => new \DomainException('Prepared callable autoload failed.'),
    };
    $attempt = new AutoloadAttempt();
    $loader = static function (string $class) use ($requested, $cause, $attempt): void {
        if ($class !== $requested) {
            return;
        }
        ++$attempt->loads;
        if ($attempt->fail) {
            throw $cause;
        }
        class_alias(StaticOwner::class, $class);
    };
    spl_autoload_register($loader);

    try {
        try {
            $unexpected = new PreparedCallable($specification);
            \PHPUnit\Framework\Assert::fail('Autoload failure unexpectedly produced a ' . get_debug_type($unexpected->callable) . ' callable.');
        } catch (\Throwable $error) {
            expect($error)->toBe($cause)->and($attempt->loads)->toBe(1);
        }

        $attempt->fail = false;
        $prepared = new PreparedCallable($specification);
        expect($prepared->callable)->toBe($specification)
            ->and($prepared())->toBe('original')
            ->and($attempt->loads)->toBe(2);
    } finally {
        spl_autoload_unregister($loader);
    }
})->with(['array' => true, 'string' => false])
    ->with(['foreign exception', 'type error', 'DI exception']);
