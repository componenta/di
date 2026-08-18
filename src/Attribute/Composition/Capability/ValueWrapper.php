<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition\Capability;

use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;

/** Wraps or replaces value resolution without claiming the exclusive provider slot. */
interface ValueWrapper extends AttributeCapabilityInterface {}
