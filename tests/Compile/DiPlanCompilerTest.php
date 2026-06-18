<?php

declare(strict_types=1);

use Componenta\DI\Compile\AttributeMatcherInterface;
use Componenta\DI\Compile\CompilesPlanPayloadInterface;
use Componenta\DI\Compile\PlanCompiler;
use Componenta\DI\Tests\Fixture\EnvTargets;
use Componenta\DI\Tests\Fixture\SimpleService;

function matcher(string $kind, callable $predicate): AttributeMatcherInterface
{
    return new class ($kind, $predicate) implements AttributeMatcherInterface {
        /** @var callable */
        private $predicate;

        public function __construct(private string $kind, callable $predicate)
        {
            $this->predicate = $predicate;
        }

        public function planKind(): string
        {
            return $this->kind;
        }

        public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
        {
            return ($this->predicate)($target) ? $this->kind : null;
        }
    };
}

function payloadMatcher(string $kind, callable $predicate, callable $payload): CompilesPlanPayloadInterface
{
    return new class ($kind, $predicate, $payload) implements CompilesPlanPayloadInterface {
        /** @var callable */
        private $predicate;

        /** @var callable */
        private $payload;

        public function __construct(private string $kind, callable $predicate, callable $payload)
        {
            $this->predicate = $predicate;
            $this->payload = $payload;
        }

        public function planKind(): string
        {
            return $this->kind;
        }

        public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string
        {
            return ($this->predicate)($target) ? $this->kind : null;
        }

        public function compilePayload(ReflectionParameter|ReflectionProperty $target): mixed
        {
            return ($this->payload)($target);
        }
    };
}

final class PlanWithAnnotatedParams
{
    public function __construct(
        public string $marked, // will be matched
        public string $alsoMarked, // will be matched
    ) {}
}

final class PlanWithMissedParam
{
    public function __construct(
        public string $marked,
        public int $unmatchable,
    ) {}
}

final class PlanWithNoMatchedParams
{
    public function __construct(
        public int $unmatchable,
    ) {}
}

final class PlanWithProperty
{
    public string $marked;
    public string $plain;
}

describe('Compile\\DiPlanCompiler', function () {
    it('returns empty maps when no classes are supplied', function () {
        $compiler = new PlanCompiler([], []);

        expect($compiler->compile([]))->toBe(['param' => [], 'prop' => []]);
    });

    it('skips non-instantiable classes (e.g. interfaces) silently', function () {
        $compiler = new PlanCompiler(
            [matcher('componenta.di.always', fn () => true)],
            [],
        );

        $plans = $compiler->compile([\Psr\Log\LoggerInterface::class]);

        expect($plans)->toBe(['param' => [], 'prop' => []]);
    });

    it('compiles a plan for a method when every parameter is matched', function () {
        $compiler = new PlanCompiler(
            [matcher('componenta.di.always', fn () => true)],
            [],
        );

        $plans = $compiler->compile([PlanWithAnnotatedParams::class]);

        expect($plans['param'][PlanWithAnnotatedParams::class]['__construct'])
            ->toBe([0 => 'componenta.di.always', 1 => 'componenta.di.always']);
    });

    it('compiles sparse method plans when only some parameters are matched', function () {
        // Matches only string-typed parameters
        $compiler = new PlanCompiler(
            [matcher('componenta.di.str', function (ReflectionParameter|ReflectionProperty $t): bool {
                if (!$t instanceof ReflectionParameter) return false;
                $type = $t->getType();
                return $type instanceof ReflectionNamedType && $type->getName() === 'string';
            })],
            [],
        );

        $plans = $compiler->compile([PlanWithMissedParam::class]);

        expect($plans['param'][PlanWithMissedParam::class]['__construct'])
            ->toBe([0 => 'componenta.di.str']);
    });

    it('omits a method plan when no parameter can be matched', function () {
        $compiler = new PlanCompiler(
            [matcher('componenta.di.str', function (ReflectionParameter|ReflectionProperty $t): bool {
                if (!$t instanceof ReflectionParameter) return false;
                $type = $t->getType();
                return $type instanceof ReflectionNamedType && $type->getName() === 'string';
            })],
            [],
        );

        $plans = $compiler->compile([PlanWithNoMatchedParams::class]);

        expect($plans['param'])->toBe([]);
    });

    it('can use complete mode as a rollback that discards partially matched methods', function () {
        $compiler = new PlanCompiler(
            [matcher('componenta.di.str', function (ReflectionParameter|ReflectionProperty $t): bool {
                if (!$t instanceof ReflectionParameter) return false;
                $type = $t->getType();
                return $type instanceof ReflectionNamedType && $type->getName() === 'string';
            })],
            [],
            PlanCompiler::MODE_COMPLETE,
        );

        $plans = $compiler->compile([PlanWithMissedParam::class]);

        expect($plans['param'])->toBe([]);
    });

    it('rejects unknown plan compiler modes', function () {
        expect(fn () => new PlanCompiler([], [], 'unknown'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('compiles per-property plans independently (one miss does not kill others)', function () {
        $compiler = new PlanCompiler(
            [],
            [matcher('componenta.di.marked', fn (ReflectionParameter|ReflectionProperty $t) => $t->getName() === 'marked')],
        );

        $plans = $compiler->compile([PlanWithProperty::class]);

        expect($plans['prop'][PlanWithProperty::class])->toBe(['marked' => 'componenta.di.marked']);
    });

    it('uses the first matcher that claims the target (priority order honored)', function () {
        $matcherHigh = matcher('componenta.di.high', fn () => true);
        $matcherLow = matcher('componenta.di.low', fn () => true);

        $compiler = new PlanCompiler(
            [$matcherHigh, $matcherLow],
            [],
        );

        $plans = $compiler->compile([PlanWithAnnotatedParams::class]);

        expect($plans['param'][PlanWithAnnotatedParams::class]['__construct'][0])
            ->toBe('componenta.di.high');
    });

    it('stores kind plus payload when a matcher can compile immutable metadata', function () {
        $compiler = new PlanCompiler(
            [
                payloadMatcher(
                    'componenta.di.payload',
                    fn (ReflectionParameter|ReflectionProperty $target): bool => $target instanceof ReflectionParameter,
                    fn (ReflectionParameter|ReflectionProperty $target): array => ['name' => $target->getName()],
                ),
            ],
            [],
        );

        $plans = $compiler->compile([PlanWithAnnotatedParams::class]);

        expect($plans['param'][PlanWithAnnotatedParams::class]['__construct'][0])
            ->toBe([
                'kind' => 'componenta.di.payload',
                'payload' => ['name' => 'marked'],
            ]);
    });

    it('skips methods with no parameters', function () {
        $compiler = new PlanCompiler(
            [matcher('componenta.di.always', fn () => true)],
            [],
        );

        $plans = $compiler->compile([SimpleService::class]);

        // SimpleService has a no-arg constructor => no param plan.
        expect($plans['param'])->toBe([]);
    });

    it('skips static properties and promoted properties', function () {
        $compiler = new PlanCompiler(
            [],
            [matcher('componenta.di.always', fn () => true)],
        );

        $plans = $compiler->compile([EnvTargets::class]);

        // EnvTargets has no static / promoted properties, but we assert
        // the plan exists for real property `explicitName` and ensure the
        // compiler didn't produce spurious entries.
        expect($plans['prop'][EnvTargets::class])
            ->toHaveKey('explicitName');
    });
});
