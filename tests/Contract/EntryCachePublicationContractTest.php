<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\EntryCachePublication;

use Componenta\DI\ConfigKey;
use Componenta\DI\Definition\Definition;
use Fiber;
use stdClass;

use function Componenta\DI\Tests\Support\container;

final readonly class Value
{
    public function __construct(public string $label) {}
}

test('a suspended source cannot republish its base after a new definition is installed', function (): void {
    $di = container([
        ConfigKey::FACTORIES => [
            'source' => static function (): Value {
                Fiber::suspend('old factory selected');
                return new Value('old');
            },
        ],
        ConfigKey::ALIASES => ['first' => 'source', 'second' => 'source'],
    ]);
    $fiber = new Fiber(static fn(): mixed => $di->get('first'));

    expect($fiber->start())->toBe('old factory selected');
    $di->set('source', Definition::factory(static fn(): Value => new Value('new')));
    $fiber->resume();
    $current = $di->get('second');

    expect($fiber->getReturn())->toEqual(new Value('old'))
        ->and($current)->toEqual(new Value('new'))
        ->and($di->get('first'))->toBe($current)
        ->and($di->get('source'))->toBe($current);
});

test('a null decorated value is shared until its source is replaced', function (): void {
    $calls = 0;
    $di = container([
        ConfigKey::SERVICES => ['source' => new stdClass()],
        ConfigKey::ALIASES => ['alias' => 'source'],
        ConfigKey::DELEGATORS => [
            'alias' => [static function (object $source) use (&$calls): null {
                ++$calls;
                return null;
            }],
        ],
    ]);

    expect($di->get('alias'))->toBeNull()
        ->and($di->get('alias'))->toBeNull()
        ->and($calls)->toBe(1);

    $di->set('source', new stdClass());

    expect($di->get('alias'))->toBeNull()
        ->and($di->get('alias'))->toBeNull()
        ->and($calls)->toBe(2);
});
