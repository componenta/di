<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Entry;

use Componenta\DI\Internal\RuntimeCollaboratorOwnership;

/** Attachment is reserved for container construction. @internal */
abstract class CompositeScope
{
    abstract protected function attach(RuntimeCollaboratorOwnership $ownership): AttachedEntryBindings;
}
