<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

/**
 * Creates a new instance via factory.
 *
 * Instructs the resolver to instantiate using the DI factory.
 * The entry can be a class name or a container service ID.
 * Supports custom constructor parameters.
 *
 * If entry is null, the resolver will use:
 * 1. The parameter/property type (if it's a class or interface)
 * 2. The parameter/property name (otherwise)
 *
 * For lazy resolution combine with {@see Lazy} (lazy object) or
 * {@see Proxy} (virtual proxy).
 *
 * @example Basic usage with explicit entry
 * ```php
 * public function handle(
 *     #[Make(UserDTO::class)] UserDTO $user,
 * ) {}
 * ```
 *
 * @example Entry from type (entry is null)
 * ```php
 * public function handle(
 *     #[Make] UserDTO $user,  // entry = UserDTO::class from type
 *     #[Make] LoggerInterface $logger,  // entry = LoggerInterface::class
 * ) {}
 * ```
 *
 * @example Entry from name (non-class type)
 * ```php
 * public function handle(
 *     #[Make] mixed $config,  // entry = 'config' from parameter name
 * ) {}
 * ```
 *
 * @example With constructor parameters
 * ```php
 * class ReportService {
 *     #[Make(PdfRenderer::class, params: ['format' => 'A4', 'dpi' => 300])]
 *     private RendererInterface $renderer;
 *
 *     #[Make(params: ['timeout' => 30])]
 *     private HttpClient $client;  // entry = HttpClient::class from type
 * }
 * ```
 *
 * @example Combined with lazy resolution
 * ```php
 * public function process(
 *     #[Make(HeavyAnalyzer::class), Lazy]
 *     HeavyAnalyzer $analyzer,
 *
 *     #[Make, Proxy]
 *     CacheInterface $cache,  // entry = CacheInterface::class from type
 * ) {}
 * ```
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_PROPERTY)]
final readonly class Make
{
    /**
     * @param string|null $entry Class name or container service ID. Null = use type or name.
     * @param array<string, mixed> $params Parameters to pass to the factory.
     */
    public function __construct(
        public ?string $entry = null,
        public array $params = [],
    ) {}
}