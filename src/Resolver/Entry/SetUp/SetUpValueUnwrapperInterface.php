<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

/** Resolves one declarative value used inside #[SetUp] parameters. */
interface SetUpValueUnwrapperInterface
{
    public function supports(mixed $value): bool;

    public function unwrap(mixed $value, string $key): mixed;
}
