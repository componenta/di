<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

/**
 * Specifies a method to call after object instantiation.
 *
 * This attribute can be applied multiple times to define
 * a sequence of initialization steps. Methods are called
 * in the order they are declared.
 *
 * Method parameters are resolved through the standard
 * parameter resolution chain, with optional explicit
 * overrides via the $params array.
 *
 * @example Single setup method
 * ```php
 * #[SetUp('initialize')]
 * class UserService {
 *     public function initialize(LoggerInterface $logger): void {
 *         $this->logger = $logger;
 *     }
 * }
 * ```
 *
 * @example Multiple setup methods
 * ```php
 * #[SetUp('setLogger')]
 * #[SetUp('configure', ['timeout' => 30])]
 * #[SetUp('boot')]
 * class PaymentGateway {
 *     public function setLogger(LoggerInterface $logger): void {}
 *     public function configure(int $timeout, bool $debug = false): void {}
 *     public function boot(): void {}
 * }
 * ```
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
readonly class SetUp
{
    /**
     * @param string $method Method name to call after instantiation.
     * @param array<string, mixed> $params Explicit parameters (merged with resolved).
     */
    public function __construct(
        public string $method,
        public array $params = [],
    ) {}
}
