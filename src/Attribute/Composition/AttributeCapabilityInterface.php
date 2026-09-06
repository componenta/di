<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition;

/**
 * Semantic capability contributed by one or more DI attributes.
 *
 * Capabilities are open extension points: third-party packages may define
 * their own marker interfaces and register composition policies for them.
 */
interface AttributeCapabilityInterface {}
