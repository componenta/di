<?php

declare(strict_types=1);

use Componenta\DI\EntryCache;

describe('EntryCache', function () {
    describe('base layer', function () {
        it('reports missing keys as absent', function () {
            expect((new EntryCache())->hasBase('svc'))->toBeFalse();
        });

        it('returns stored values', function () {
            $cache = new EntryCache();
            $value = new stdClass();

            $cache->putBase('svc', $value);

            expect($cache->hasBase('svc'))->toBeTrue()
                ->and($cache->getBase('svc'))->toBe($value);
        });

        it('treats a stored null as present (array_key_exists semantics)', function () {
            $cache = new EntryCache();

            $cache->putBase('svc', null);

            expect($cache->hasBase('svc'))->toBeTrue()
                ->and($cache->getBase('svc'))->toBeNull();
        });

        it('removes the entry on removeBase', function () {
            $cache = new EntryCache();
            $cache->putBase('svc', 'v');

            $cache->removeBase('svc');

            expect($cache->hasBase('svc'))->toBeFalse();
        });

        it('overwrites previously stored values', function () {
            $cache = new EntryCache();
            $cache->putBase('svc', 'first');

            $cache->putBase('svc', 'second');

            expect($cache->getBase('svc'))->toBe('second');
        });
    });

    describe('resolved layer', function () {
        it('reports missing keys as absent', function () {
            expect((new EntryCache())->hasResolved('svc'))->toBeFalse();
        });

        it('stores values keyed by requested id', function () {
            $cache = new EntryCache();

            $cache->putResolved('requested', 'canonical', 'value');

            expect($cache->hasResolved('requested'))->toBeTrue()
                ->and($cache->getResolved('requested'))->toBe('value');
        });

        it('treats a stored null as present', function () {
            $cache = new EntryCache();

            $cache->putResolved('r', 'c', null);

            expect($cache->hasResolved('r'))->toBeTrue()
                ->and($cache->getResolved('r'))->toBeNull();
        });

        it('does not populate the canonical id when requested == canonical', function () {
            $cache = new EntryCache();

            $cache->putResolved('svc', 'svc', 'value');

            // Requested id present; no sibling mapping implied.
            expect($cache->hasResolved('svc'))->toBeTrue();
        });
    });

    describe('invalidate()', function () {
        it('removes the requested id from the resolved layer', function () {
            $cache = new EntryCache();
            $cache->putResolved('svc', 'svc', 'v');

            $cache->invalidate('svc');

            expect($cache->hasResolved('svc'))->toBeFalse();
        });

        it('removes both requested and canonical sides when they differ', function () {
            $cache = new EntryCache();
            $cache->putResolved('alias', 'canonical', 'v');
            $cache->putResolved('canonical', 'canonical', 'v');

            $cache->invalidate('alias', 'canonical');

            expect($cache->hasResolved('alias'))->toBeFalse()
                ->and($cache->hasResolved('canonical'))->toBeFalse();
        });

        it('wipes sibling aliases that shared the same canonical id', function () {
            $cache = new EntryCache();
            $cache->putResolved('alias-1', 'canonical', 'v');
            $cache->putResolved('alias-2', 'canonical', 'v');

            $cache->invalidate('canonical', 'canonical');

            expect($cache->hasResolved('alias-1'))->toBeFalse()
                ->and($cache->hasResolved('alias-2'))->toBeFalse();
        });

        it('performs one-sided invalidation when canonical id is null', function () {
            $cache = new EntryCache();
            $cache->putResolved('alias', 'canonical', 'v');
            $cache->putResolved('canonical', 'canonical', 'v');

            $cache->invalidate('alias');

            expect($cache->hasResolved('alias'))->toBeFalse()
                ->and($cache->hasResolved('canonical'))->toBeTrue();
        });

        it('leaves the base layer untouched', function () {
            $cache = new EntryCache();
            $cache->putBase('svc', 'base-value');
            $cache->putResolved('svc', 'svc', 'resolved-value');

            $cache->invalidate('svc');

            expect($cache->hasBase('svc'))->toBeTrue()
                ->and($cache->getBase('svc'))->toBe('base-value');
        });

        it('clears the reverse index so a second invalidation does not re-wipe new siblings', function () {
            $cache = new EntryCache();
            $cache->putResolved('alias', 'canonical', 'v');

            // First invalidation: should drop the alias -> canonical mapping.
            $cache->invalidate('canonical', 'canonical');

            // New registration under the same alias after invalidation.
            $cache->putResolved('alias', 'other-canonical', 'v2');

            // Invalidating a *different* canonical must not touch the new alias.
            $cache->invalidate('canonical', 'canonical');

            expect($cache->hasResolved('alias'))->toBeTrue()
                ->and($cache->getResolved('alias'))->toBe('v2');
        });
    });
});
