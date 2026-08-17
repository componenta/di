<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

/**
 * Marks a parameter attribute that explicitly declares the value source.
 *
 * During HTTP DTO mapping, transformed request data must not bind a constructor
 * parameter carrying such an attribute. A same-named mapped field is treated
 * as a source conflict rather than as an explicit DI override.
 */
interface ParameterSourceAttributeInterface {}
