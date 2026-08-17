<?php

declare(strict_types=1);

use Componenta\Caster\ConfigProvider as CasterConfigProvider;
use Componenta\Config\Config;
use Componenta\DI\Attribute\CurrentUser;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\ConfigProvider;
use Componenta\DI\Container;
use Componenta\DI\ContainerBuilder;
use Componenta\DI\Resolver\CurrentUserProviderInterface;
use Componenta\DI\Tests\Fixture\FakeServerRequest;
use Psr\Http\Message\ServerRequestInterface;

final readonly class CurrentUserTrustActor
{
    public function __construct(public string $source) {}
}

final readonly class CurrentUserTrustDto
{
    public function __construct(
        #[CurrentUser]
        public CurrentUserTrustActor $actor,
        public string $value,
    ) {}
}

function currentUserTrustContainer(CurrentUserTrustActor $authenticated): Container
{
    $provider = new class () extends \Componenta\Config\ConfigProvider {
        protected function getProviders(): array
        {
            return [
                new CasterConfigProvider(),
                new ConfigProvider(),
            ];
        }
    };

    $container = ContainerBuilder::configure(new Config($provider()))->build();
    $currentUser = $container->get(CurrentUserProviderInterface::class);
    $currentUser->setUser($authenticated);

    return $container;
}

it('does not let explicit parameter values shadow CurrentUser by name position or type', function (): void {
    $authenticated = new CurrentUserTrustActor('authenticated');
    $spoofed = new CurrentUserTrustActor('spoofed');
    $container = currentUserTrustContainer($authenticated);
    $callable = static fn(
        #[CurrentUser]
        CurrentUserTrustActor $actor,
    ): CurrentUserTrustActor => $actor;

    $byName = $container->call($callable, ['actor' => $spoofed]);
    $byPosition = $container->call($callable, [0 => $spoofed]);
    $byType = $container->call($callable, [CurrentUserTrustActor::class => $spoofed]);

    expect($byName)->toBe($authenticated)
        ->and($byPosition)->toBe($authenticated)
        ->and($byType)->toBe($authenticated);
});

it('does not let mapped request data shadow CurrentUser inside a DTO', function (): void {
    $authenticated = new CurrentUserTrustActor('authenticated');
    $spoofed = new CurrentUserTrustActor('spoofed');
    $container = currentUserTrustContainer($authenticated);
    $request = new FakeServerRequest(parsedBody: [
        'actor' => $spoofed,
        'value' => 'payload-value',
    ]);

    $dto = $container->call(
        static fn(
            #[MapRequestPayload]
            CurrentUserTrustDto $command,
        ): CurrentUserTrustDto => $command,
        [ServerRequestInterface::class => $request],
    );

    expect($dto->actor)->toBe($authenticated)
        ->and($dto->actor)->not->toBe($spoofed)
        ->and($dto->value)->toBe('payload-value');
});
