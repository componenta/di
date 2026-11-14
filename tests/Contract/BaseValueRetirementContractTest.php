<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\BaseValueRetirement;

use Closure;
use Componenta\DI\ConfigKey;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Exception\MutationRetirementException;
use LogicException;

use function Componenta\DI\Tests\Support\container;

final class Source
{
    public static ?Closure $retire = null;

    public function __construct(public readonly string $label) {}

    public function __destruct()
    {
        if ($this->label === 'old' && self::$retire !== null) {
            (self::$retire)();
        }
    }
}

test('a retired base value sees every invalidated alias when decorations do not retain the source', function (bool $definition): void {
    $di = container([
        ConfigKey::SERVICES => ['source' => new Source('old')],
        ConfigKey::ALIASES => ['a' => 'source', 'b' => 'source'],
        ConfigKey::DELEGATORS => [
            'a' => [static fn(Source $source): string => $source->label . ':a'],
            'b' => [static fn(Source $source): string => $source->label . ':b'],
        ],
    ]);
    expect([$di->get('a'), $di->get('b')])->toBe(['old:a', 'old:b']);

    $observed = [];
    $cause = new LogicException('Retired base value reentered the container.');
    Source::$retire = static function () use ($di, &$observed, $cause): never {
        $observed = [$di->get('a'), $di->get('b')];
        throw $cause;
    };
    $failure = null;
    try {
        $di->set('source', $definition
            ? Definition::factory(static fn(): Source => new Source('new'))
            : new Source('new'));
    } catch (MutationRetirementException $error) {
        $failure = $error;
    } finally {
        Source::$retire = null;
    }

    expect($failure)->toBeInstanceOf(MutationRetirementException::class);
    if (!$failure instanceof MutationRetirementException) {
        throw new LogicException('Expected the committed mutation to report its retirement failure.');
    }
    expect($failure->id)->toBe('source')
        ->and($failure->causes)->toBe([$cause])
        ->and($failure->getPrevious())->toBe($cause)
        ->and($observed)->toBe(['new:a', 'new:b'])
        ->and([$di->get('a'), $di->get('b')])->toBe($observed);
})->with(['raw shared replacement' => false, 'definition replacement' => true]);
