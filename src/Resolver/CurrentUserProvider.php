<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

use Fiber;
use WeakMap;

/** Default current-user storage isolated by the active Fiber execution context. */
final class CurrentUserProvider implements CurrentUserProviderInterface
{
    private ?object $mainUser;

    /** @var WeakMap<object, object> */
    private WeakMap $fiberUsers;

    /** Sentinel representing an explicitly unauthenticated Fiber context. */
    private readonly object $nullUser;

    public function __construct(?object $user = null)
    {
        $this->mainUser = $user;
        $this->fiberUsers = new WeakMap();
        $this->nullUser = new \stdClass();
    }

    public function getUser(): ?object
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            return $this->mainUser;
        }

        if (!isset($this->fiberUsers[$fiber])) {
            $user = $this->mainUser;
            $this->fiberUsers[$fiber] = $user ?? $this->nullUser;
            return $user;
        }

        $user = $this->fiberUsers[$fiber];
        return $user === $this->nullUser ? null : $user;
    }

    public function setUser(?object $user): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            $this->mainUser = $user;
            return;
        }

        $this->fiberUsers[$fiber] = $user ?? $this->nullUser;
    }
}
