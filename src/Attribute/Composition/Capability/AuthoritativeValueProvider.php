<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Composition\Capability;

/** Value source that caller-provided generic parameters must not shadow. */
interface AuthoritativeValueProvider extends ValueProvider {}
