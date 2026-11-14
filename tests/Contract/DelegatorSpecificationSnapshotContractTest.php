<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\DelegatorSpecificationSnapshot;

use Componenta\DI\Tests\Support\ContainerBuilder;

final readonly class Result
{
    public function __construct(
        public string $base,
        public Decorator $owner,
        public string $method,
        public string $label,
    ) {}
}

final class Decorator
{
    public function __construct(public string $label) {}

    public function decorate(string $entry): Result
    {
        return new Result($entry, $this, 'decorate', $this->label);
    }

    public function alternative(string $entry): Result
    {
        return new Result($entry, $this, 'alternative', $this->label);
    }
}

test('delegator admission freezes direct callable references while preserving owner state', function (bool $runtime): void {
    $original = new Decorator('original');
    $owner = $original;
    $method = 'decorate';
    $specification = [&$owner, &$method];
    $builder = (new ContainerBuilder())->addService('base', 'initial');
    if (!$runtime) {
        $builder->addDelegator('base', $specification);
    }
    $container = $builder->build();
    if ($runtime) {
        $container->delegator('base', $specification);
    }

    $owner = new Decorator('replacement');
    $method = 'alternative';
    $cold = $container->get('base');

    expect($cold)->toEqual(new Result('initial', $original, 'decorate', 'original'));
    if (!$cold instanceof Result) {
        throw new \LogicException('Expected the decorated result.');
    }
    expect($cold->owner)->toBe($original)
        ->and($container->get('base'))->toBe($cold);

    $original->label = 'updated business state';
    $container->set('base', 'refreshed');
    $fresh = $container->get('base');

    expect($fresh)->toEqual(new Result('refreshed', $original, 'decorate', 'updated business state'));
    if (!$fresh instanceof Result) {
        throw new \LogicException('Expected the refreshed decorated result.');
    }
    expect($fresh->owner)->toBe($original)
        ->and($container->get('base'))->toBe($fresh);
})->with(['bootstrap' => false, 'runtime' => true]);

test('deferred delegator snapshots keep routing aligned with warm cache invalidation', function (bool $runtime): void {
    $original = new Decorator('original');
    $replacement = new Decorator('replacement');
    $owner = 'decorator.owner';
    $method = 'decorate';
    $specification = [&$owner, &$method];
    $builder = (new ContainerBuilder())
        ->addService('base', 'initial')
        ->addService('decorator.owner', $original)
        ->addService('different.owner', new Decorator('different'));
    if (!$runtime) {
        $builder->addDelegator('base', $specification);
    }
    $container = $builder->build();
    if ($runtime) {
        $container->delegator('base', $specification);
    }

    $owner = 'different.owner';
    $method = 'alternative';
    $cold = $container->get('base');

    expect($cold)->toEqual(new Result('initial', $original, 'decorate', 'original'))
        ->and($container->get('base'))->toBe($cold);

    $container->set('decorator.owner', $replacement);
    $fresh = $container->get('base');

    expect($fresh)->toEqual(new Result('initial', $replacement, 'decorate', 'replacement'))
        ->and($fresh)->not->toBe($cold)
        ->and($container->get('base'))->toBe($fresh)
        ->and($owner)->toBe('different.owner')
        ->and($method)->toBe('alternative');
})->with(['bootstrap' => false, 'runtime' => true]);

final class StaticDecorator
{
    public static function decorate(string $entry): string
    {
        return $entry . ':original';
    }

    public static function alternative(string $entry): string
    {
        return $entry . ':replacement';
    }
}

test('delegator routing is frozen before dependency discovery autoloads a callable owner', function (): void {
    $requestedOwner = __NAMESPACE__ . '\\AutoloadedDecorator_' . bin2hex(random_bytes(5));
    $owner = $requestedOwner;
    $method = 'decorate';
    $container = (new ContainerBuilder())->addService('base', 'initial')->build();
    $autoload = static function (string $class) use ($requestedOwner, &$owner, &$method): void {
        if ($class === $requestedOwner) {
            $owner = StaticDecorator::class;
            $method = 'alternative';
            class_alias(StaticDecorator::class, $class);
        }
    };
    spl_autoload_register($autoload);

    try {
        $container->delegator('base', [&$owner, &$method]);

        expect($container->get('base'))->toBe('initial:original')
            ->and($owner)->toBe(StaticDecorator::class)
            ->and($method)->toBe('alternative');
    } finally {
        spl_autoload_unregister($autoload);
    }
});
