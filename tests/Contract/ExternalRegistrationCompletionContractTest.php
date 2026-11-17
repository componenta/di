<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ExternalRegistrationCompletion;

use Closure;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;

final readonly class Product
{
    public function __construct(public string $value) {}
}

final readonly class OwnershipContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $entries
     * @param Closure(string):bool|null $onHas
     */
    public function __construct(private array $entries, private ?Closure $onHas = null) {}

    public function has(string $id): bool
    {
        return $this->onHas === null ? array_key_exists($id, $this->entries) : ($this->onHas)($id);
    }

    public function get(string $id): mixed
    {
        return $this->entries[$id] ?? throw new LogicException('Unexpected external lookup: ' . $id);
    }
}

test('external registration refreshes stale owner evidence after a nested membership change', function (): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $existing = static fn(Product $entry): Product => new Product($entry->value . ':existing');
    $selected = static fn(Product $entry): Product => new Product($entry->value . ':selected');
    $container = (new ContainerBuilder())
        ->addServices(['entry' => new Product('base'), 'owner' => $local])
        ->addDelegator('entry', 'owner')
        ->build();
    $state = new class () {
        public bool $armed = false;
        public bool $owns = true;
    };
    $added = new OwnershipContainer(['nested.entry' => 'registered']);
    $earlier = new OwnershipContainer(['owner' => $existing], static function (string $id) use ($container, $added, $state): bool {
        if ($id !== 'owner') {
            return false;
        }
        $owned = $state->owns;
        if ($state->armed) {
            $state->armed = false;
            $container->addContainer($added);
            $state->owns = false;
        }
        return $owned;
    });
    $container->addContainer($earlier);
    $previous = $container->get('entry');
    $state->armed = true;

    $container->addContainer(new OwnershipContainer(['owner' => $selected]));
    $current = $container->get('entry');

    expect($previous)->toEqual(new Product('base:existing'))
        ->and($current)->toEqual(new Product('base:selected'))
        ->and($current)->not->toBe($previous)
        ->and($container->get('entry'))->toBe($current)
        ->and($container->get('owner'))->toBe($selected)
        ->and($container->get('nested.entry'))->toBe('registered');
});

test('external registration stops inspecting ownership once nested work registered its candidate', function (string $completion): void {
    $local = static fn(Product $entry): Product => new Product($entry->value . ':local');
    $selected = static fn(Product $entry): Product => new Product($entry->value . ':selected');
    $container = (new ContainerBuilder())->addServices([
        'entry' => new Product('base'),
        'owner' => $local,
        'other' => new Product('other'),
        'other.owner' => $local,
    ])->addDelegator('entry', 'owner')->addDelegator('other', 'other.owner')->build();
    $state = new class () {
        public bool $armed = false;
        public bool $candidateInspected = false;
        public bool $completed = false;
        public ?OwnershipContainer $candidate = null;
        public ?Product $warmed = null;
    };
    $complete = static function () use ($container, $state): void {
        $state->armed = false;
        $container->addContainer($state->candidate ?? throw new LogicException('Expected the registration candidate.'));
        $entry = $container->get('entry');
        if (!$entry instanceof Product) {
            throw new LogicException('Expected the decorated product.');
        }
        $state->warmed = $entry;
        $state->completed = true;
    };
    $candidate = new OwnershipContainer([
        'owner' => $selected,
        'candidate.entry' => 'registered',
    ], static function (string $id) use ($state, $complete, $completion): bool {
        if ($state->completed && ($id === 'owner' || $id === 'other.owner')) {
            throw new LogicException('Completed nested registration must not repeat candidate inspection.');
        }
        if ($id === 'owner') {
            $state->candidateInspected = true;
            if ($state->armed && $completion === 'candidate callback') {
                $complete();
            }
            return true;
        }
        return $id === 'candidate.entry';
    });
    $state->candidate = $candidate;
    $observer = new OwnershipContainer(['owner' => $selected], static function (string $id) use ($state, $complete, $completion): bool {
        if ($state->completed && ($id === 'owner' || $id === 'other.owner')) {
            throw new LogicException('Completed nested registration must not repeat existing-owner inspection.');
        }
        if ($id !== 'owner') {
            return false;
        }
        if ($completion === 'existing owner callback') {
            if ($state->armed) {
                $complete();
            }
            return true;
        }
        if ($completion === 'owner callback after candidate validation' && $state->armed && $state->candidateInspected) {
            $complete();
            return true;
        }
        return false;
    });
    $container->addContainer($observer);
    $unaffected = $container->get('other');
    $state->armed = true;

    $container->addContainer($candidate);

    expect($state->completed)->toBeTrue()
        ->and($state->warmed)->toEqual(new Product('base:selected'))
        ->and($container->get('entry'))->toBe($state->warmed)
        ->and($container->get('other'))->toBe($unaffected)
        ->and($container->get('candidate.entry'))->toBe('registered');
})->with([
    'existing owner callback',
    'candidate callback',
    'owner callback after candidate validation',
]);
