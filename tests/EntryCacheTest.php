<?php

declare(strict_types=1);

namespace Componenta\DI\Tests;

use Componenta\DI\EntryCache;

describe('EntryCache', function () {
    it('reads base values through the single tryGet API including null', function () {
        $cache = new EntryCache();
        $cache->putBase('value', 10);
        $cache->putBase('null', null);

        expect($cache->tryGetBase('value', $value))->toBeTrue()
            ->and($value)->toBe(10)
            ->and($cache->tryGetBase('null', $null))->toBeTrue()
            ->and($null)->toBeNull()
            ->and($cache->tryGetBase('missing', $missing))->toBeFalse();
    });

    it('removes base entries explicitly', function () {
        $cache = new EntryCache();
        $cache->putBase('entry', new \stdClass());
        $cache->removeBase('entry');

        expect($cache->tryGetBase('entry', $value))->toBeFalse();
    });

    it('invalidates requested aliases and every sibling of the canonical id', function () {
        $cache = new EntryCache();
        $cache->putResolved('alias-a', 'canonical', 'a');
        $cache->putResolved('alias-b', 'canonical', 'b');
        $cache->putResolved('canonical', 'canonical', 'canonical');

        $cache->invalidate('alias-a', 'canonical');

        expect($cache->tryGetResolved('alias-a', $a))->toBeFalse()
            ->and($cache->tryGetResolved('alias-b', $b))->toBeFalse()
            ->and($cache->tryGetResolved('canonical', $canonical))->toBeFalse();
    });

    it('accepts initial base entries without changing null semantics', function () {
        $cache = new EntryCache([
            'value' => 10,
            'null' => null,
        ]);

        expect($cache->tryGetBase('value', $value))->toBeTrue()
            ->and($value)->toBe(10)
            ->and($cache->tryGetBase('null', $null))->toBeTrue()
            ->and($null)->toBeNull();
    });
});
