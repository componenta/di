<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler;

/** Optional semantic version included in compiled-factory fingerprints. */
interface VersionedAttributeHandlerInterface extends AttributeHandlerInterface
{
    public function semanticVersion(): int|string;
}
