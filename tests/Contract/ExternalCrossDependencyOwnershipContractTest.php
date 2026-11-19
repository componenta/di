<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExternalCrossDependencyOwnership;

use Closure;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;
use stdClass;

final readonly class Product
{
    public function __construct(public string $value) {}
}

final class External implements ContainerInterface
{
    /** @var list<string> */
    public array $queries = [];

    /**
     * @param array<string, mixed> $entries
     * @param Closure(string):bool|null $onHas
     */
    public function __construct(private array $entries, private ?Closure $onHas = null) {}

    public function has(string $id): bool
    {
        $this->queries[] = $id;
        return $this->onHas === null ? array_key_exists($id, $this->entries) : ($this->onHas)($id);
    }

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw NotFoundException::forService($id);
    }
}

final class OwnershipState
{
    public bool $armed = false;
    public bool $ownsFirst = true;
    public bool $changed = false;
    public int $firstProbes = 0;
    public int $secondProbes = 0;
}

test('external registration revisits an earlier dependency whose owner changes while inspecting another id', function (
    string $phase,
    bool $registerNested,
    bool $releaseOwner,
): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $previousOwner = static fn(Product $entry): Product => new Product($entry->value . ':previous');
    $replacement = static fn(Product $entry): Product => new Product($entry->value . ':replacement');
    $unrelated = new stdClass();
    $di = (new ContainerBuilder())->addServices([
        'first' => new Product('first'),
        'second' => new Product('second'),
        'first.owner' => $local,
        'second.owner' => $local,
        'unrelated' => $unrelated,
    ])->addDelegator('first', 'first.owner')->addDelegator('second', 'second.owner')->build();
    $state = new OwnershipState();
    $marker = new External(['nested.marker' => 'registered']);
    $change = static function () use ($di, $state, $marker, $registerNested, $releaseOwner): void {
        $state->armed = false;
        if ($registerNested) {
            $di->addContainer($marker);
        }
        $state->ownsFirst = !$releaseOwner;
        $state->changed = true;
    };
    $earlier = new External(['first.owner' => $previousOwner], static function (string $id) use ($state, $phase, $change): bool {
        if ($id === 'first.owner') {
            return $state->ownsFirst;
        }
        if ($id === 'second.owner' && $state->armed) {
            ++$state->secondProbes;
            if (($phase === 'existing lookup' && $state->secondProbes === 1)
                || ($phase === 'final proof' && $state->secondProbes === 2)
            ) {
                $change();
            }
        }
        return false;
    });
    $di->addContainer($earlier);
    $firstBefore = $di->get('first');
    $secondBefore = $di->get('second');
    expect($firstBefore)->toEqual(new Product('first:previous'))
        ->and($secondBefore)->toEqual(new Product('second:local'))
        ->and($di->get('unrelated'))->toBe($unrelated);
    $candidate = new External([
        'first.owner' => $replacement,
        'second.owner' => $replacement,
    ], static function (string $id) use ($state, $phase, $change): bool {
        if ($id === 'second.owner' && $state->armed && $phase === 'candidate lookup') {
            $change();
        }
        return $id === 'first.owner' || $id === 'second.owner';
    });
    $state->armed = true;

    $di->addContainer($candidate);
    $inspection = array_count_values($candidate->queries);
    $first = $di->get('first');
    $second = $di->get('second');

    expect($state->changed)->toBeTrue()
        ->and($first)->toEqual(new Product('first:' . ($releaseOwner ? 'replacement' : 'previous')))
        ->and($first === $firstBefore)->toBe(!$releaseOwner)
        ->and($second)->toEqual(new Product('second:replacement'))
        ->and($second)->not->toBe($secondBefore)
        ->and($di->get('first'))->toBe($first)
        ->and($di->get('second'))->toBe($second)
        ->and($di->get('first.owner'))->toBe($releaseOwner ? $replacement : $previousOwner)
        ->and($di->get('unrelated'))->toBe($unrelated)
        ->and($inspection['first.owner'] ?? 0)->toBe($releaseOwner ? 1 : 0)
        ->and($inspection['second.owner'] ?? 0)->toBe(1);
    if ($registerNested) {
        expect($di->get('nested.marker'))->toBe('registered');
    }
})->with(['existing lookup', 'candidate lookup', 'final proof'])
    ->with(['no membership change' => false, 'nested registration' => true])
    ->with(['existing owner remains' => false, 'existing owner relinquishes' => true]);

test('a failed postponed ownership check rejects the candidate without invalidating shared pipelines', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $previousOwner = static fn(Product $entry): Product => new Product($entry->value . ':previous');
    $replacement = static fn(Product $entry): Product => new Product($entry->value . ':replacement');
    $di = (new ContainerBuilder())->addServices([
        'first' => new Product('first'),
        'second' => new Product('second'),
        'first.owner' => $local,
        'second.owner' => $local,
    ])->addDelegator('first', 'first.owner')->addDelegator('second', 'second.owner')->build();
    $state = new OwnershipState();
    $failure = new LogicException('Postponed ownership inspection failed.');
    $earlier = new External(['first.owner' => $previousOwner], static function (string $id) use ($state, $failure): bool {
        if ($id !== 'first.owner') {
            return false;
        }
        if ($state->armed && ++$state->firstProbes === 2) {
            $state->armed = false;
            throw $failure;
        }
        return true;
    });
    $di->addContainer($earlier);
    $first = $di->get('first');
    $second = $di->get('second');
    $candidate = new External(['first.owner' => $replacement, 'second.owner' => $replacement, 'candidate.marker' => true]);
    $state->armed = true;

    try {
        $di->addContainer($candidate);
        throw new LogicException('Expected postponed ownership inspection to reject the candidate.');
    } catch (InvalidConfigurationException $exception) {
        expect($exception->getPrevious())->toBe($failure);
    }

    expect($di->has('candidate.marker'))->toBeFalse()
        ->and($di->get('first'))->toBe($first)
        ->and($di->get('second'))->toBe($second);
    $di->addContainer($candidate);
    expect($di->get('candidate.marker'))->toBeTrue()
        ->and($di->get('first'))->toBe($first)
        ->and($di->get('second'))->toEqual(new Product('second:replacement'));
});

final readonly class Lookup
{
    public function definition(string $name): object
    {
        return $this;
    }
}

test('a postponed ownership callback can protect and rewarm an alias before candidate inspection', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $replacement = static fn(Product $entry): Product => new Product($entry->value . ':replacement');
    $previous = new Lookup();
    $di = (new ContainerBuilder())->addServices([
        'first' => MapRequest::class,
        'second' => new Product('second'),
        'local.lookup' => new Lookup(),
        'second.owner' => $local,
    ])->addAlias('lookup', 'local.lookup')
        ->addDelegator('first', ['lookup', 'definition'])
        ->addDelegator('second', 'second.owner')
        ->build();
    $state = new OwnershipState();
    $warmed = null;
    $earlier = new External(['lookup' => $previous], static function (string $id) use ($di, $state, &$warmed): bool {
        if ($id !== 'lookup') {
            return false;
        }
        if ($state->armed && ++$state->firstProbes === 2) {
            $state->armed = false;
            $di->alias('lookup', AttributeDefinitionRegistry::class);
            $warmed = $di->get('first');
        }
        return true;
    });
    $di->addContainer($earlier);
    expect($di->get('first'))->toBe($previous);
    $di->get('second');
    $candidate = new External(['second.owner' => $replacement], static function (string $id): bool {
        if ($id === 'lookup') {
            throw new LogicException('The candidate cannot inspect the now-protected alias.');
        }
        return $id === 'second.owner';
    });
    $state->armed = true;

    $di->addContainer($candidate);

    $registry = $di->get(AttributeDefinitionRegistry::class);
    expect($warmed)->toBe($registry->definition(MapRequest::class))
        ->and($di->get('first'))->toBe($warmed)
        ->and($di->get('second'))->toEqual(new Product('second:replacement'))
        ->and($candidate->queries)->not->toContain('lookup');
});
