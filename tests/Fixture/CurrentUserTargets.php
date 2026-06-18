<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use Componenta\DI\Attribute\CurrentUser;

final class CurrentUserTargets
{
    #[CurrentUser]
    public FakeUser $user;

    #[CurrentUser]
    public ?FakeUser $optionalUser;

    #[CurrentUser(FakeAdmin::class)]
    public FakeAdmin $admin;

    public FakeUser $unattributed;

    public function byParameters(
        #[CurrentUser] FakeUser $user,
        #[CurrentUser] ?FakeUser $optional,
        #[CurrentUser(FakeAdmin::class)] FakeAdmin $admin,
        FakeUser $plain,
    ): void {}
}
