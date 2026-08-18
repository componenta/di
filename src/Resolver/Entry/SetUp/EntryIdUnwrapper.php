<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use Componenta\DI\Attribute\EntryId;
use Psr\Container\ContainerInterface;

/** Resolves #[EntryId] descriptors used as #[SetUp] parameter values. */
final readonly class EntryIdUnwrapper implements SetUpValueUnwrapperInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function supports(mixed $value): bool
    {
        return $value instanceof EntryId;
    }

    public function unwrap(mixed $value, string $key): mixed
    {
        /** @var EntryId $value */
        return $this->container->get($value->value);
    }
}
