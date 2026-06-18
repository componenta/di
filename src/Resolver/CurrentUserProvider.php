<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver;

final class CurrentUserProvider implements CurrentUserProviderInterface
{
    public function __construct(
        private ?object $user = null
    ) {
    }

    public function getUser(): ?object
    {
        return $this->user;
    }

    public function setUser(?object $user): void
    {
        $this->user = $user;
    }
}