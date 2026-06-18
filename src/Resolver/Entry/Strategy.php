<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

/**
 * Resolution strategy chosen per entry - drives whether the resolver
 * returns a lazy object, a virtual proxy, or builds eagerly.
 *
 * Internal to the resolver chain; not exposed via the public DI API.
 */
enum Strategy
{
    /** Eager - construct immediately. Default when no attribute is present. */
    case Eager;

    /** Lazy object - same class identity, in-place initialization. */
    case Lazy;

    /** Virtual proxy - forwarding subtype. */
    case VirtualProxy;
}
