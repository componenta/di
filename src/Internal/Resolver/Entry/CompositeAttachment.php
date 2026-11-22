<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Internal\RuntimeCollaboratorOwnership;
use Componenta\DI\Resolver\Entry\CompositeResolver;

/** Joins a composite and the collaborators of its owning container. @internal */
abstract class CompositeAttachment extends CompositeScope
{
    public static function toContainer(CompositeResolver $resolver, RuntimeCollaboratorOwnership $ownership): AttachedEntryBindings
    {
        return $resolver->attach($ownership);
    }
}
