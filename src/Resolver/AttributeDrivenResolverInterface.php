<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

/**
 * Marker for resolvers whose entire decision is driven by an attribute on the
 * target (parameter or property).
 *
 * Both {@see \Componenta\DI\Resolver\Parameter\ParametersResolver} and
 * {@see \Componenta\DI\Resolver\Property\PropertiesResolver} use this marker to skip
 * such resolvers when the target carries no attributes at all - saving the
 * per-resolver cost of constructing a {@see Target\ParameterTarget}/{@see Target\PropertyTarget}
 * adapter and walking the WeakMap-backed metadata cache only to find nothing.
 *
 * Resolvers that also do something on attribute-less targets (e.g.
 * {@see \Componenta\DI\Resolver\Parameter\Request\RequestResolver}, which falls
 * back to type-based UriInterface resolution) MUST NOT implement this
 * marker - they must remain in the chain unconditionally.
 */
interface AttributeDrivenResolverInterface
{
}
