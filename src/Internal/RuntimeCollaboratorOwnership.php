<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Exception\InvalidConfigurationException;
use stdClass;
use WeakMap;

/** Claims the mutable collaborators of one container at attachment. @internal */
final class RuntimeCollaboratorOwnership
{
    /** @var WeakMap<object, true>|null */
    private static ?WeakMap $owners = null;

    public readonly object $authority;

    public function __construct(
        private readonly AliasResolver $aliases,
        private readonly EntryCache $cache,
        private readonly DelegatorRegistry $delegators,
        private readonly CycleGuard $cycleGuard,
        private readonly ?ExternalContainerRegistry $externalContainers,
        private readonly int $validatedAliasRevision,
    ) {
        // The alias authority contains no reference to the container.
        $this->authority = new stdClass();
    }

    public function attach(bool &$rootAttached): void
    {
        $this->aliases->attach($this, $rootAttached);
    }

    /** @param WeakMap<AliasResolver, object> $aliasOwners */
    public function claim(WeakMap $aliasOwners, bool &$rootAttached): void
    {
        $owners = self::$owners ??= new WeakMap();
        $aliases = $this->aliases;
        $cache = $this->cache;
        $delegators = $this->delegators;
        $cycleGuard = $this->cycleGuard;
        $externalContainers = $this->externalContainers;
        $authority = $this->authority;

        // Loading a collaborator can change the still-standalone alias graph.
        $validatedAliasRevision = $this->validatedAliasRevision;
        while ($aliases->revision !== $validatedAliasRevision) {
            $validatedAliasRevision = $aliases->validateProtectedRoutes();
        }

        // Replacing object locals in a loop can run cyclic destructors. Hold
        // each key through preflight and commit, including the root flag, before
        // returning and letting PHP release this frame's references.
        if ($rootAttached) {
            throw new InvalidConfigurationException('A composite can be attached to only one container.');
        }

        $conflict = match (true) {
            isset($aliasOwners[$aliases]) => $aliases,
            isset($owners[$cache]) => $cache,
            isset($owners[$delegators]) => $delegators,
            isset($owners[$cycleGuard]) => $cycleGuard,
            $externalContainers !== null && isset($owners[$externalContainers]) => $externalContainers,
            default => null,
        };
        if ($conflict !== null) {
            throw new InvalidConfigurationException(sprintf(
                'Runtime collaborator "%s" already belongs to a container.',
                $conflict::class,
            ));
        }

        $owners[$cache] = true;
        $owners[$delegators] = true;
        $owners[$cycleGuard] = true;
        if ($externalContainers !== null) {
            $owners[$externalContainers] = true;
        }
        $aliasOwners[$aliases] = $authority;
        $rootAttached = true;
    }
}
