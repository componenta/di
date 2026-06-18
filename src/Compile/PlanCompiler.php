<?php

declare(strict_types=1);

namespace Componenta\DI\Compile;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * Offline planner: walks a list of {@see AttributeMatcherInterface} matchers
 * (in priority order) and asks each to claim every eligible method parameter
 * and property of every passed class. Result is a sparse
 * `class -> method/prop -> kind-token` or `kind + payload` map ready to be
 * serialised into `config.cache.php`.
 *
 * Open/Closed: adding a new resolver with its own attribute means
 * implementing {@see AttributeMatcherInterface} on it - the compiler picks
 * it up via the same registration path as the built-ins, no changes here.
 *
 * Parameter scope: all **own** public methods (constructor, `__invoke`,
 * explicit service methods, etc.). Inherited methods are skipped - their
 * plans live under the declaring class because
 * `ReflectionParameter::getDeclaringClass()` returns the originator, which
 * is the lookup key used by `ParametersResolver` at runtime. This widens
 * coverage to framework dispatch paths (controllers' `__invoke`, interceptor
 * targets) without duplicating plans across the inheritance chain.
 *
 * Parameter plans are sparse by default: only parameters claimed by a matcher
 * appear in the output. Unclaimed parameters keep using the runtime chain, so
 * a route scalar does not prevent an adjacent autowired service from taking
 * the fast path. The legacy complete-method mode is still available as a
 * rollback path and discards a method plan when any parameter is unclaimed.
 * Properties follow the sparse rule independently in both modes.
 */
final class PlanCompiler
{
    public const int CACHE_VERSION = 1;

    /**
     * Section key under `dependencies` in `config.cache.php` where the
     * compiled output is stored.
     */
    public const string CONFIG_KEY = 'di_plans';

    /**
     * Dependency-section key pointing to a sidecar file with compiled plans.
     */
    public const string FILE_CONFIG_KEY = 'di_plans_file';

    /**
     * Dependency-section key controlling how method parameter plans are emitted.
     */
    public const string MODE_CONFIG_KEY = 'di_plans_mode';

    /**
     * Compile every claimed parameter independently.
     */
    public const string MODE_SPARSE = 'sparse';

    /**
     * Legacy rollback mode: compile a method only when every parameter is claimed.
     */
    public const string MODE_COMPLETE = 'complete';

    /** @var list<AttributeMatcherInterface> */
    private readonly array $paramMatchers;

    /** @var list<AttributeMatcherInterface> */
    private readonly array $propMatchers;

    /**
     * Matchers must be supplied in **priority order** - the first match
     * wins. The two slots exist because the parameter and property chains
     * have different default orderings, even when the same resolver class
     * appears in both (its `claimTarget()` correctly distinguishes the two
     * via the union type).
     *
     * @param list<AttributeMatcherInterface> $paramMatchers
     * @param list<AttributeMatcherInterface> $propMatchers
     */
    public function __construct(
        array $paramMatchers,
        array $propMatchers,
        private readonly string $mode = self::MODE_SPARSE,
    ) {
        if (!in_array($mode, [self::MODE_SPARSE, self::MODE_COMPLETE], true)) {
            throw new InvalidArgumentException(sprintf(
                'DI plan compiler mode must be "%s" or "%s"; got "%s".',
                self::MODE_SPARSE,
                self::MODE_COMPLETE,
                $mode,
            ));
        }

        $this->paramMatchers = array_values($paramMatchers);
        $this->propMatchers  = array_values($propMatchers);
    }

    /**
     * Output shape:
     * ```
     * [
     *   'param' => [ FQCN => [ method => [ pos => kind|entry, ... ], ... ], ... ],
     *   'prop'  => [ FQCN => [ name => kind|entry, ... ], ... ],
     * ]
     * ```
     *
     * @param iterable<class-string> $classes
     * @return array{param: array<class-string, array<string, array<int, string|array{kind: string, payload: mixed}>>>, prop: array<class-string, array<string, string|array{kind: string, payload: mixed}>>}
     */
    public function compile(iterable $classes): array
    {
        $paramPlans = [];
        $propPlans  = [];

        foreach ($classes as $class) {
            $reflector = $this->reflect($class);
            if ($reflector === null || !$reflector->isInstantiable()) {
                continue;
            }

            foreach ($this->eligibleMethods($reflector) as $method) {
                $plan = $this->compileMethod($method);

                if ($plan !== null) {
                    $paramPlans[$class][$method->getName()] = $plan;
                }
            }

            $props = $this->compileProperties($reflector);
            if ($props !== []) {
                $propPlans[$class] = $props;
            }
        }

        return ['param' => $paramPlans, 'prop' => $propPlans];
    }

    /**
     * Yields own public, non-static instance methods that have at least
     * one parameter and are therefore candidates for a plan. Inherited
     * methods are excluded - their plans live on the declaring class
     * (see class-level docblock). Static methods are skipped: they are
     * typically framework factories taking a container, which the plan
     * fast-path cannot accelerate usefully.
     *
     * @return iterable<ReflectionMethod>
     */
    private function eligibleMethods(ReflectionClass $reflector): iterable
    {
        foreach ($reflector->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $reflector->getName()) {
                continue;
            }

            if ($method->getParameters() === []) {
                continue;
            }

            yield $method;
        }
    }

    /**
     * @return array<int, string|array{kind: string, payload: mixed}>|null
     */
    private function compileMethod(ReflectionMethod $method): ?array
    {
        $plan = [];

        foreach ($method->getParameters() as $param) {
            $entry = $this->matchOne($this->paramMatchers, $param);

            if ($entry === null) {
                if ($this->mode === self::MODE_COMPLETE) {
                    return null;
                }

                continue;
            }

            $plan[$param->getPosition()] = $entry;
        }

        return $plan === [] ? null : $plan;
    }

    /**
     * @return array<string, string|array{kind: string, payload: mixed}>
     */
    private function compileProperties(ReflectionClass $reflector): array
    {
        $out = [];

        foreach ($reflector->getProperties() as $prop) {
            if ($prop->isStatic() || $prop->isPromoted()) {
                continue;
            }

            $entry = $this->matchOne($this->propMatchers, $prop);
            if ($entry !== null) {
                $out[$prop->getName()] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param list<AttributeMatcherInterface> $matchers
     * @return string|array{kind: string, payload: mixed}|null
     */
    private function matchOne(array $matchers, ReflectionParameter|ReflectionProperty $target): string|array|null
    {
        foreach ($matchers as $m) {
            $kind = $m->claimTarget($target);
            if ($kind !== null) {
                if (!$m instanceof CompilesPlanPayloadInterface) {
                    return $kind;
                }

                return [
                    'kind' => $kind,
                    'payload' => $m->compilePayload($target),
                ];
            }
        }
        return null;
    }

    private function reflect(string $class): ?ReflectionClass
    {
        try {
            return new ReflectionClass($class);
        } catch (\ReflectionException) {
            return null;
        }
    }
}
