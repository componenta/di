<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixture/reflection_helpers.php';

use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;

use function Componenta\DI\Tests\Fixture\typedParam;

describe('Parameter\\ArrayTypedResolver', function () {
    it('returns null when the parameter has no type', function () {
        $param = typedParam('untyped', 0);

        expect((new ArrayTypedResolver())->resolveParameter($param, [new stdClass()]))->toBeNull();
    });

    it('returns null for built-in types even with matching scalars in the array', function () {
        $param = typedParam('primitives', 0); // int $count

        expect((new ArrayTypedResolver())->resolveParameter($param, [42]))->toBeNull();
    });

    it('resolves by type-name key', function () {
        $param = typedParam('byType', 0);
        $logger = new class () implements \Psr\Log\LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public function log($level, \Stringable|string $message, array $context = []): void {}
        };

        $result = (new ArrayTypedResolver())->resolveParameter($param, [
            \Psr\Log\LoggerInterface::class => $logger,
        ]);

        expect($result)->toBe([0, $logger]);
    });

    it('resolves by instanceof when no type-key match is present', function () {
        $param = typedParam('byType', 0);
        $logger = new class () implements \Psr\Log\LoggerInterface {
            use \Psr\Log\LoggerTrait;
            public function log($level, \Stringable|string $message, array $context = []): void {}
        };

        $result = (new ArrayTypedResolver())->resolveParameter($param, [$logger]);

        expect($result)->toBe([0, $logger]);
    });

    it('returns null when no provided value satisfies the declared class type', function () {
        $param = typedParam('byType', 0);

        expect((new ArrayTypedResolver())->resolveParameter($param, [new stdClass()]))->toBeNull();
    });

    it('walks union types in declaration order, picking the first matching', function () {
        $param = typedParam('byUnion', 0); // LoggerInterface|stdClass
        $std = new stdClass();

        $result = (new ArrayTypedResolver())->resolveParameter($param, [$std]);

        expect($result)->toBe([0, $std]);
    });
});
