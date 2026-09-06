<?php

declare(strict_types=1);

namespace Componenta\DI;

/** Resolves callable representations and executes them through DI parameter resolution. */
interface CallableExecutorInterface extends CallableInvokerInterface, CallableResolverInterface {}
