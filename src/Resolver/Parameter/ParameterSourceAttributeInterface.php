<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter;

/**
 * Marks a parameter attribute that explicitly declares the value source.
 *
 * During HTTP DTO mapping, transformed request data must not provide a key
 * that generic explicit-value resolvers could bind to such a parameter. This
 * includes the parameter name and its declared class/interface type names.
 */
interface ParameterSourceAttributeInterface {}
