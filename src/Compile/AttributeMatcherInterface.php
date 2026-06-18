<?php

declare(strict_types=1);

namespace Componenta\DI\Compile;

use ReflectionParameter;
use ReflectionProperty;

/**
 * Compile-time companion to a parameter or property resolver.
 *
 * The {@see PlanCompiler} walks every registered matcher in priority order
 * and asks "do you claim this target?". The first matcher to return a
 * non-null kind wins - the kind is stored in the compiled plan, and the
 * runtime dispatcher routes the call back to the resolver under the same
 * kind via {@see PlanDispatcher::bind()}.
 *
 * Resolvers that cannot decide statically (e.g. anything that needs runtime
 * context to know whether they apply) simply do not implement this interface
 * - they remain in the runtime chain and never participate in plans. That
 * keeps the compile pipeline safely Open/Closed: adding a new resolver with
 * its own attribute is a matter of implementing this interface and
 * registering the resolver as usual.
 *
 * Implementations MUST be free of runtime state in {@see claimTarget()} -
 * the call happens offline, possibly in a separate process, and only sees
 * Reflection. Container state, request data, env vars are not available.
 */
interface AttributeMatcherInterface
{
    /**
     * Stable, globally-unique token identifying this resolver in compiled
     * plans. Convention: dotted string scoped to the owner package, e.g.
     * `'componenta.di.env'`, `'app.jwt_claim'`. The dispatcher uses this token
     * verbatim as the array key.
     */
    public function planKind(): string;

    /**
     * Returns this resolver's {@see planKind()} when it would handle the
     * given target at runtime, null otherwise.
     *
     * Optimistic matches are fine: if the resolver might still return null
     * at runtime (e.g. autowire when the entry isn't registered),
     * {@see \Componenta\DI\Resolver\Parameter\ParametersResolver} falls back to
     * the full chain. Compile-time false positives cost a single extra
     * dispatch; compile-time false negatives lose the fast path entirely.
     */
    public function claimTarget(ReflectionParameter|ReflectionProperty $target): ?string;
}
