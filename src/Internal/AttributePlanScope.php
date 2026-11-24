<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use WeakReference;

/** @internal */
abstract class AttributePlanScope
{
    /** @var WeakReference<BootstrapResolutionGuard>|null */
    protected ?WeakReference $bootstrap = null;

    protected function __construct(protected readonly AttributePlans $planning) {}

    private function __clone() {}
}
