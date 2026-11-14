<?php

declare(strict_types=1);

namespace Componenta\DI\Exception;

use InvalidArgumentException;
use Throwable;

/** Reports native retirement failures after the registration mutation committed. */
final class MutationRetirementException extends InvalidConfigurationException
{
    /** @param non-empty-list<Throwable> $causes Synchronously delivered causes, in release order. */
    public function __construct(
        public readonly ?string $id,
        public readonly array $causes,
    ) {
        if ($causes === [] || !array_is_list($causes)) {
            throw new InvalidArgumentException('Mutation retirement requires a non-empty ordered list of causes.');
        }
        foreach ($causes as $cause) {
            if (!$cause instanceof Throwable) {
                throw new InvalidArgumentException('Mutation retirement causes must implement Throwable.');
            }
        }
        parent::__construct(
            $id === null
                ? 'The DI mutation committed, but releasing retired values failed.'
                : sprintf('The DI mutation for id "%s" committed, but releasing retired values failed.', $id),
            previous: $causes[0],
        );
    }
}
