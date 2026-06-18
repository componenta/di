<?php

declare(strict_types=1);

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Resolver\Entry\SetUp\EntryIdUnwrapper;
use Psr\Container\ContainerInterface;

function entryIdUnwrapperContainer(array $entries): ContainerInterface
{
    return new class ($entries) implements ContainerInterface {
        public function __construct(private array $entries) {}

        public function get(string $id): mixed
        {
            if (!array_key_exists($id, $this->entries)) {
                throw NotFoundException::forService($id);
            }
            return $this->entries[$id];
        }

        public function has(string $id): bool
        {
            return array_key_exists($id, $this->entries);
        }
    };
}

describe('Resolver\\Entry\\SetUp\\EntryIdUnwrapper', function () {
    it('recognises only EntryId instances', function () {
        $unwrapper = new EntryIdUnwrapper(entryIdUnwrapperContainer([]));

        expect($unwrapper->supports(new EntryId('svc')))->toBeTrue()
            ->and($unwrapper->supports('svc'))->toBeFalse()
            ->and($unwrapper->supports(null))->toBeFalse();
    });

    it('fetches the entry from the container using EntryId::$value', function () {
        $target = new stdClass();
        $unwrapper = new EntryIdUnwrapper(entryIdUnwrapperContainer(['cache.redis' => $target]));

        expect($unwrapper->unwrap(new EntryId('cache.redis'), 'ignored-key'))->toBe($target);
    });

    it('propagates NotFoundException when the entry is not registered', function () {
        $unwrapper = new EntryIdUnwrapper(entryIdUnwrapperContainer([]));

        expect(fn () => $unwrapper->unwrap(new EntryId('absent'), 'k'))
            ->toThrow(NotFoundException::class);
    });
});
