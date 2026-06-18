<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Attribute;

/**
 * Marks parameter or property to be injected with current authenticated user.
 *
 * @example Parameter injection
 * ```php
 * public function handle(#[CurrentUser] User $user): void
 * {
 *     // $user is the currently authenticated user
 * }
 * ```
 *
 * @example Optional user (nullable)
 * ```php
 * public function handle(#[CurrentUser] ?User $user): void
 * {
 *     // $user is null if not authenticated
 * }
 * ```
 *
 * @example Property injection
 * ```php
 * class ProfileController {
 *     #[CurrentUser]
 *     private User $user;
 * }
 * ```
 *
 * @example With type constraint
 * ```php
 * public function adminAction(
 *     #[CurrentUser(Admin::class)] Admin $admin,
 * ): void {}
 * ```
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
final readonly class CurrentUser
{
    /**
     * @param class-string|null $type Optional type constraint for user object.
     */
    public function __construct(
        public ?string $type = null,
    ) {}
}