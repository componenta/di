<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\EntryId;
use Psr\Log\LoggerInterface;

final class EntryIdTargets
{
    #[EntryId('logger.file')]
    public LoggerInterface $logger;

    public LoggerInterface $unattributed;

    public function byParameters(
        #[EntryId('cache.redis')] object $cache,
        object $plain,
    ): void {}
}
