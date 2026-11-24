<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\External\ExternalLookupRegistrationContract;

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

test('a suspended ownership probe leaves the provider available to another execution', function (): void {
    $di = container();
    $owner = new LookupContainer(static function (): bool {
        if (Fiber::getCurrent() !== null) {
            Fiber::suspend('inspecting');
        }
        return true;
    });
    $di->addContainer($owner);
    $lookup = new Fiber(static fn(): bool => $di->has('entry'));

    expect($lookup->start())->toBe('inspecting')
        ->and($di->get('entry'))->toBe('external');
    $lookup->resume();

    expect($lookup->getReturn())->toBeTrue();
});

test('recursive ownership checks do not enumerate provider permutations', function (int $lookups, int $limit): void {
    $di = container();
    $providers = 8;
    $checks = 0;
    for ($i = 0; $i < $providers; ++$i) {
        $di->addContainer(new LookupContainer(static function () use ($di, &$checks, $lookups, $limit): bool {
            if (++$checks > $limit) {
                throw new LogicException('Too many ownership checks for one lookup.');
            }
            for ($query = 0; $query < $lookups; ++$query) {
                $di->has('entry');
            }
            return false;
        }));
    }

    expect($di->has('entry'))->toBeFalse()
        ->and($checks)->toBeLessThanOrEqual($limit);
})->with([
    'one lookup per probe' => [1, 8 ** 3],
    'repeated lookups per probe' => [2, 8 ** 4],
]);

test('a nested lookup observes ownership that changes after a deeper lookup', function (): void {
    $di = container();
    $available = false;
    $availability = [];
    $first = new LookupContainer(static function () use ($di, &$availability): bool {
        $availability[] = $di->has('entry');
        return false;
    });
    $second = new LookupContainer(static function () use ($di, &$available, &$availability): bool {
        if (!$available) {
            $availability[] = $di->has('entry');
            $available = true;
        }
        return false;
    });
    $owner = new LookupContainer(static function () use (&$available): bool {
        return $available;
    }, 'found');
    $di->addContainer($first);
    $di->addContainer($second);
    $di->addContainer($owner);

    expect($di->has('entry'))->toBeTrue()
        ->and($availability)->toBe([false, true])
        ->and($di->get('entry'))->toBe('found');
});

test('a fresh nested lookup replaces earlier misses when it discovers an owner', function (): void {
    $di = container();
    $state = new class () {
        public bool $available = false;
        public bool $ownerActive = false;
        /** @var list<bool> */
        public array $observed = [];
    };
    $observe = static function () use ($di): bool {
        $di->has('entry');
        return false;
    };
    $observer = new LookupContainer(static function () use ($di, $state): bool {
        if (!$state->available) {
            $state->observed[] = $di->has('entry');
            $state->available = true;
            $state->observed[] = $di->has('entry');
        } else {
            $found = $di->has('entry');
            if (!$state->ownerActive) {
                $state->observed[] = $found;
            }
        }
        return false;
    });
    $owner = new LookupContainer(static function () use ($di, $state): bool {
        $state->ownerActive = true;
        try {
            $di->has('entry');
        } finally {
            $state->ownerActive = false;
        }
        return $state->available;
    });
    $di->addContainer(new LookupContainer($observe));
    $di->addContainer(new LookupContainer($observe));
    $di->addContainer($observer);
    $di->addContainer($owner);

    expect($di->has('entry'))->toBeTrue()
        ->and($state->observed[0] ?? null)->toBeFalse();
    $after = array_slice($state->observed, 1);
    expect($after)->not->toBeEmpty();
    expect(in_array(false, $after, true))->toBeFalse();
});

test('a second availability query observes a new owner inside an outer recheck', function (): void {
    $di = container();
    $state = new class () {
        public bool $ready = false;
        public bool $owns = false;
        public mixed $value = null;
        /** @var list<bool> */
        public array $observed = [];
    };
    $di->addContainer(new LookupContainer(static function () use ($di, $state): bool {
        $di->has('entry');
        $state->ready = true;
        $di->has('entry');
        return false;
    }));
    $di->addContainer(new LookupContainer(static function () use ($di, $state): bool {
        if ($state->ready && !$state->owns) {
            $state->observed[] = $di->has('entry');
            $state->owns = true;
            $state->observed[] = $di->has('entry');
            $state->value = $di->get('entry');
        }
        return false;
    }));
    $di->addContainer(new LookupContainer(static function () use ($di, $state): bool {
        if (!$state->owns) {
            $di->has('entry');
        }
        return $state->owns;
    }, 'new owner'));

    expect($di->has('entry'))->toBeTrue()
        ->and($state->observed)->toBe([false, true])
        ->and($state->value)->toBe('new owner');
});
