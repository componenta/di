<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

/**
 * Specifies explicit service ID for resolution from DI container.
 *
 * Used when the service ID differs from the parameter/property type,
 * or when injecting by interface with a specific implementation.
 *
 * @example Parameter injection
 * ```php
 * public function __construct(
 *     #[EntryId('logger.file')] LoggerInterface $logger,
 *     #[EntryId('cache.redis')] CacheInterface $cache,
 * ) {}
 * ```
 *
 * @example Property injection
 * ```php
 * class UserService {
 *     #[EntryId('mailer.smtp')]
 *     private MailerInterface $mailer;
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
readonly class EntryId
{
    /**
     * @param string $value The service identifier in the DI container.
     */
    public function __construct(
        public string $value,
    ) {}
}
