<?php

declare(strict_types=1);

require_once __DIR__ . '/../Fixture/reflection_helpers.php';

use Componenta\DI\Resolver\TypeHints;
use Componenta\DI\Tests\Fixture\TypedParameters;

use function Componenta\DI\Tests\Fixture\typedParam;

describe('Resolver\\TypeHints::classOf()', function () {
    it('returns null for a null type', function () {
        expect(TypeHints::classOf(null))->toBeNull();
    });

    it('returns null for built-in types', function () {
        $param = typedParam('primitives', 0, TypedParameters::class); // int $count

        expect(TypeHints::classOf($param->getType()))->toBeNull();
    });

    it('returns the class/interface name for non-builtin named types', function () {
        $param = typedParam('byType', 0, TypedParameters::class); // LoggerInterface $logger

        expect(TypeHints::classOf($param->getType()))->toBe(\Psr\Log\LoggerInterface::class);
    });

    it('returns null for union types (intentionally unsupported)', function () {
        $param = typedParam('byUnion', 0, TypedParameters::class); // LoggerInterface|stdClass

        expect(TypeHints::classOf($param->getType()))->toBeNull();
    });

    it('returns null for untyped parameters', function () {
        $param = typedParam('untyped', 0, TypedParameters::class);

        expect(TypeHints::classOf($param->getType()))->toBeNull();
    });
});
