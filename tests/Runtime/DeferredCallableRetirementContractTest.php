<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Runtime\DeferredCallableRetirementContract;

use Closure;
use Componenta\DI\Exception\MutationRetirementException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use Psr\Container\ContainerInterface;

final class Decorator
{
    public static ?Closure $retire = null;
    public int $calls = 0;

    public function __construct(private readonly string $label) {}

    public function __invoke(string $entry): string
    {
        ++$this->calls;
        return $entry . ':' . $this->label;
    }

    public function __destruct()
    {
        if ($this->label === 'old' && self::$retire !== null) {
            (self::$retire)();
        }
    }
}

final class ExternalProvider implements ContainerInterface
{
    public bool $active = true;

    public function has(string $id): bool
    {
        return $this->active && $id === 'decorator';
    }

    public function get(string $id): Decorator
    {
        if (!$this->has($id)) {
            throw new LogicException('Unexpected external service lookup.');
        }
        return new Decorator('old');
    }
}

test('retired external callable owners observe all dependent pipelines after the replacement commits', function (bool $throws): void {
    $container = (new ContainerBuilder())
        ->addServices(['first' => 'one', 'second' => 'two'])
        ->addDelegator('first', 'decorator')
        ->addDelegator('second', 'decorator')
        ->build();
    $external = new ExternalProvider();
    $container->addContainer($external);
    expect([$container->get('first'), $container->get('second')])->toBe(['one:old', 'two:old']);

    $observed = [];
    $cause = new LogicException('Retired external callable owner.');
    Decorator::$retire = static function () use ($container, &$observed, $throws, $cause): void {
        $observed[] = [$container->get('first'), $container->get('second')];
        if ($throws) {
            throw $cause;
        }
    };
    $replacement = new Decorator('new');
    $external->active = false;
    $failure = null;
    try {
        $container->set('decorator', $replacement);
    } catch (MutationRetirementException $error) {
        $failure = $error;
    } finally {
        Decorator::$retire = null;
    }

    expect($observed)->toBe([['one:new', 'two:new'], ['one:new', 'two:new']])
        ->and([$container->get('first'), $container->get('second')])->toBe(['one:new', 'two:new'])
        ->and($container->get('decorator'))->toBe($replacement)
        ->and($replacement->calls)->toBe(2);
    if ($throws) {
        expect($failure)->toBeInstanceOf(MutationRetirementException::class);
        if (!$failure instanceof MutationRetirementException) {
            throw new LogicException('Expected a committed retirement failure.');
        }
        expect($failure->id)->toBe('decorator')
            ->and($failure->causes)->toBe([$cause, $cause])
            ->and($failure->getPrevious())->toBe($cause);
    } else {
        expect($failure)->toBeNull();
    }
})->with(['reentrant observation' => false, 'reentrant observation and failure' => true]);
