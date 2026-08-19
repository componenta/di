<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

/** Raised while immutable DI attribute metadata is being composed into a plan. */
final class AttributeCompositionException extends InvalidConfigurationException {}
