<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition\Capability;

/**
 * Value-transforming attributes still own the target's single value-resolution
 * slot. This preserves v4 resolver semantics while allowing composition rules
 * to reason about the more specific transformer role.
 */
interface ValueTransformer extends ValueProvider {}
