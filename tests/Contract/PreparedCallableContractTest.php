<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\PreparedCallable;

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
