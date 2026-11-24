<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Delegator\QualifiedDelegatorLifetimeContract;

use Closure;
use Componenta\DI\Exception\MutationRetirementException;
use Componenta\DI\Tests\Support\ContainerBuilder;
use ErrorException;
use LogicException;
use Psr\Container\ContainerInterface;
use WeakReference;

final class State
{
    /** @var list<WeakReference<Receiver>> */
    public static array $owners = [];
    /** @var list<int> */
    public static array $liveDuringCall = [];
    public static int $released = 0;
    public static ?Closure $retire = null;
}

class Ancestor
{
    public static function decorate(string $entry): string
    {
        State::$liveDuringCall[] = count(array_filter(State::$owners, static fn(WeakReference $owner): bool => $owner->get() !== null));
        return $entry . ':old';
    }
}

final class Receiver extends Ancestor
{
    public function __destruct()
    {
        ++State::$released;
        State::$retire?->__invoke();
    }
}

final class Provider implements ContainerInterface
{
    public bool $active = true;

    public function has(string $id): bool
    {
        return $this->active && $id === 'qualified.decorator';
    }

    /** @return array{Receiver,string} */
    public function get(string $id): array
    {
        if (!$this->has($id)) {
            throw new LogicException('Unexpected external service lookup.');
        }
        $owner = new Receiver();
        State::$owners[] = WeakReference::create($owner);
        return [$owner, Ancestor::class . '::decorate'];
    }
}

test('qualified static delegator receivers remain owned until dependent pipelines retire after commit', function (bool $throws): void {
    State::$owners = [];
    State::$liveDuringCall = [];
    State::$released = 0;
    State::$retire = null;
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if ($severity === E_DEPRECATED && str_starts_with($message, 'Callables of the form ')) {
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        $native = new Provider()->get('qualified.decorator');
        if (!is_callable($native)) {
            throw new LogicException('Expected the native qualified static callable.');
        }
        expect(call_user_func_array($native, ['native']))->toBe('native:old')
            ->and(State::$liveDuringCall)->toBe([1])
            ->and(State::$released)->toBe(0);
        unset($native);
        expect(State::$released)->toBe(1);
        State::$owners = State::$liveDuringCall = [];
        State::$released = 0;

        $container = (new ContainerBuilder())
            ->addServices(['first' => 'one', 'second' => 'two'])
            ->addDelegator('first', 'qualified.decorator')
            ->addDelegator('second', 'qualified.decorator')
            ->build();
        $provider = new Provider();
        $container->addContainer($provider);
        expect([$container->get('first'), $container->get('second')])->toBe(['one:old', 'two:old'])
            ->and(State::$liveDuringCall)->toBe([1, 2])
            ->and(State::$released)->toBe(0);

        $observed = [];
        $cause = new LogicException('Retired qualified receiver.');
        State::$retire = static function () use ($container, &$observed, $cause, $throws): void {
            $observed[] = [$container->get('first'), $container->get('second')];
            if ($throws) {
                throw $cause;
            }
        };
        $provider->active = false;
        $replacement = static fn(string $entry): string => $entry . ':new';
        $failure = null;
        try {
            $container->set('qualified.decorator', $replacement);
        } catch (MutationRetirementException $error) {
            $failure = $error;
        } finally {
            State::$retire = null;
        }

        expect($observed)->toBe([['one:new', 'two:new'], ['one:new', 'two:new']])
            ->and(State::$released)->toBe(2)
            ->and(array_map(static fn(WeakReference $owner): mixed => $owner->get(), State::$owners))->toBe([null, null])
            ->and([$container->get('first'), $container->get('second')])->toBe(['one:new', 'two:new']);
        if ($throws) {
            expect($failure)->toBeInstanceOf(MutationRetirementException::class);
            if (!$failure instanceof MutationRetirementException) {
                throw new LogicException('Expected the committed retirement failure.');
            }
            expect($failure->causes)->toBe([$cause, $cause])
                ->and($failure->getPrevious())->toBe($cause);
        } else {
            expect($failure)->toBeNull();
        }
    } finally {
        State::$retire = null;
        restore_error_handler();
    }
})->with(['reentrant observation' => false, 'reentrant observation and failure' => true]);
