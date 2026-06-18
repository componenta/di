<?php

declare(strict_types=1);

use Componenta\DI\Attribute\Config;
use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\Entry\SetUp\ConfigUnwrapper;
use Psr\Container\ContainerInterface;

function configUnwrapperContainer(mixed $value): ContainerInterface
{
    return new class ($value) implements ContainerInterface {
        public function __construct(private mixed $value) {}

        public function get(string $id): mixed
        {
            if ($id !== Config::KEY) {
                throw NotFoundException::forService($id);
            }
            if ($this->value instanceof Throwable) {
                throw $this->value;
            }
            return $this->value;
        }

        public function has(string $id): bool
        {
            return $id === Config::KEY && !($this->value instanceof Throwable);
        }
    };
}

describe('Resolver\\Entry\\SetUp\\ConfigUnwrapper', function () {
    it('recognises only Config attribute instances', function () {
        $unwrapper = new ConfigUnwrapper(configUnwrapperContainer([]));

        expect($unwrapper->supports(new Config()))->toBeTrue()
            ->and($unwrapper->supports(new stdClass()))->toBeFalse()
            ->and($unwrapper->supports('raw'))->toBeFalse();
    });

    it('reads a literal key from the configuration', function () {
        $unwrapper = new ConfigUnwrapper(configUnwrapperContainer(['db_host' => 'localhost']));

        expect($unwrapper->unwrap(new Config('db_host'), 'ignored'))->toBe('localhost');
    });

    it('falls back to the SetUp key when Config::$path is null', function () {
        $unwrapper = new ConfigUnwrapper(configUnwrapperContainer(['timeout' => 30]));

        expect($unwrapper->unwrap(new Config(), 'timeout'))->toBe(30);
    });

    it('wraps OutOfBoundsException from the extractor into ResolutionException', function () {
        $unwrapper = new ConfigUnwrapper(configUnwrapperContainer([]));

        try {
            $unwrapper->unwrap(new Config('absent'), 'key');
        } catch (ResolutionException $e) {
            expect($e->getPrevious())->toBeInstanceOf(OutOfBoundsException::class);
            return;
        }

        self::fail('expected ResolutionException');
    });

    it('lets PSR-11 container exceptions propagate unchanged', function () {
        $unwrapper = new ConfigUnwrapper(configUnwrapperContainer(NotFoundException::forService(Config::KEY)));

        expect(fn () => $unwrapper->unwrap(new Config('k'), 'key'))
            ->toThrow(NotFoundException::class);
    });
});
