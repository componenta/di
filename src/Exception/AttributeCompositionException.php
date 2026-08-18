<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use InvalidArgumentException;

/** Raised while immutable DI attribute metadata is being composed into a plan. */
final class AttributeCompositionException extends InvalidArgumentException implements ExceptionInterface {}
