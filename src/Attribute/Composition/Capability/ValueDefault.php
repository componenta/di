<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition\Capability;

use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;

/** Supplies an attribute-owned fallback value before generic value fallbacks. */
interface ValueDefault extends AttributeCapabilityInterface {}
