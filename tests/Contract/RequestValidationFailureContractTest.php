<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\RequestValidationFailure;

use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\ConfigKey;
use Componenta\DI\Exception\ResolutionException;
use Componenta\Validation\Exception\ValidationException;
use Componenta\Validation\Provider\ValidationProviderInterface;
use Componenta\Validation\Rule\Required;
use Componenta\Validation\Validator;
use Componenta\Validation\ValidatorInterface;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

use function Componenta\DI\Tests\Support\container;

final class ValidatedInput
{
    public static int $constructed = 0;

    public function __construct(public string $name)
    {
        ++self::$constructed;
    }
}

test('failed HTTP validation prevents DTO construction and preserves the validation exception', function (): void {
    ValidatedInput::$constructed = 0;
    $validation = new class () implements ValidationProviderInterface {
        public function provide(string $entryId): ?ValidatorInterface
        {
            return $entryId === ValidatedInput::class
                ? new Validator(['name' => new Required()])
                : null;
        }
    };
    $container = container([ConfigKey::SERVICES => [ValidationProviderInterface::class => $validation]]);
    $request = (new ServerRequest('GET', '/'))->withQueryParams(['name' => '']);
    $handler = static fn(#[MapQueryString] ValidatedInput $input): ValidatedInput => $input;
    $failure = null;

    try {
        try {
            $container->call($handler, [ServerRequestInterface::class => $request]);
        } catch (ResolutionException $exception) {
            $failure = $exception;
        }

        expect($failure)->toBeInstanceOf(ResolutionException::class);
        if (!$failure instanceof ResolutionException) {
            throw new LogicException('Expected request validation to fail.');
        }
        expect($failure->getPrevious())->toBeInstanceOf(ValidationException::class)
            ->and(ValidatedInput::$constructed)->toBe(0);

        $result = $container->call($handler, [
            ServerRequestInterface::class => $request->withQueryParams(['name' => 'accepted']),
        ]);
        expect($result)->toBeInstanceOf(ValidatedInput::class);
        if (!$result instanceof ValidatedInput) {
            throw new LogicException('Expected a validated DTO.');
        }
        expect($result->name)->toBe('accepted')
            ->and(ValidatedInput::$constructed)->toBe(1);
    } finally {
        ValidatedInput::$constructed = 0;
    }
});
