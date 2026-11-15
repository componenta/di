<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExternalLookupRegistration;

use Closure;
use Componenta\DI\Exception\ResolutionException;
use Fiber;
use LogicException;
use Psr\Container\ContainerInterface;

use function Componenta\DI\Tests\Support\container;

final class LookupContainer implements ContainerInterface
{
    public int $queries = 0;

    /** @param Closure():bool $owns */
    public function __construct(private readonly Closure $owns, private readonly string $value = 'external') {}

    public function has(string $id): bool
    {
        if ($id !== 'entry') {
            return false;
        }
        ++$this->queries;
        return ($this->owns)();
    }

    public function get(string $id): string
    {
        if ($id !== 'entry') {
            throw new LogicException('Unexpected external lookup.');
        }
        return $this->value;
    }
}

test('external lookup visits owners registered by an earlier ownership callback before local fallback', function (bool $availability, bool $warm): void {
    $di = container();
    if (!$availability) {
        $di->set('entry', 'local');
        if ($warm) {
            expect($di->get('entry'))->toBe('local');
        }
    } elseif ($warm) {
        expect($di->has('entry'))->toBeFalse();
    }
    $last = new LookupContainer(static fn(): bool => true);
    $middle = new LookupContainer(static function () use ($di, $last): bool {
        $di->addContainer($last);
        return false;
    });
    $first = new LookupContainer(static function () use ($di, $middle): bool {
        $di->addContainer($middle);
        return false;
    });
    $di->addContainer($first);

    $result = $availability ? $di->has('entry') : $di->get('entry');

    expect($result)->toBe($availability ? true : 'external')
        ->and([$first->queries, $middle->queries, $last->queries])->toBe([1, 1, 1]);
})->with(['get' => false, 'has' => true])->with(['cold' => false, 'warm' => true]);

test('external lookup keeps first-owner priority when an owner registers another candidate', function (): void {
    $di = container();
    $later = new LookupContainer(static fn(): bool => true, 'later');
    $first = new LookupContainer(static function () use ($di, $later): bool {
        $di->addContainer($later);
        return true;
    }, 'first');
    $di->addContainer($first);

    expect($di->get('entry'))->toBe('first')
        ->and($first->queries)->toBe(1)
        ->and($later->queries)->toBe(0);
});

test('a newly registered ownership failure releases the outer lookup guard for recovery', function (): void {
    $di = container();
    $failure = new LogicException('Ownership inspection failed.');
    $fail = true;
    $owner = new LookupContainer(static function () use (&$fail, $failure): bool {
        if ($fail) {
            $fail = false;
            throw $failure;
        }
        return true;
    });
    $first = new LookupContainer(static function () use ($di, $owner): bool {
        $di->addContainer($owner);
        return false;
    });
    $di->addContainer($first);

    try {
        $di->get('entry');
        \PHPUnit\Framework\Assert::fail('The newly registered owner must be queried.');
    } catch (ResolutionException $exception) {
        expect($exception->getPrevious())->toBe($failure);
    }

    expect($di->get('entry'))->toBe('external')
        ->and([$first->queries, $owner->queries])->toBe([2, 2]);
});

test('a suspended external lookup observes new owners when its ownership callback resumes', function (bool $availability): void {
    $di = container();
    $owner = new LookupContainer(static fn(): bool => true);
    $first = new LookupContainer(static function (): bool {
        Fiber::suspend('inspecting');
        return false;
    });
    $di->addContainer($first);
    $lookup = new Fiber(static fn(): mixed => $availability ? $di->has('entry') : $di->get('entry'));

    expect($lookup->start())->toBe('inspecting');
    $di->addContainer($owner);
    $lookup->resume();

    expect($lookup->getReturn())->toBe($availability ? true : 'external')
        ->and([$first->queries, $owner->queries])->toBe([1, 1]);
})->with(['get' => false, 'has' => true]);
