<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition\Capability;

use Componenta\DI\Attribute\Composition\AttributeCapabilityInterface;

/** Attribute that transforms a value resolved by an explicit/source handler. */
interface ValueTransformer extends AttributeCapabilityInterface {}
