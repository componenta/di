<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\PayloadObjectPath;

use Componenta\Config\ConfigPath;
use Componenta\DI\Attribute\PayloadParam;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;

use function Componenta\DI\Tests\Support\container;

test('payload paths traverse nested JSON objects and preserve explicit null', function (bool $arrayRoot): void {
    $body = json_decode('{"profile":{"name":"Ada","middle":null},"profile.name":"literal"}', flags: JSON_THROW_ON_ERROR);
    if (!is_object($body)) {
        throw new \LogicException('Expected an object payload.');
    }
    $request = (new ServerRequest('POST', '/'))->withParsedBody($arrayRoot ? get_object_vars($body) : $body);

    $result = container()->call(
        static fn(
            #[PayloadParam(new ConfigPath('profile.name'))] string $name,
            #[PayloadParam(new ConfigPath('profile.middle'), default: 'fallback')] ?string $middle,
            #[PayloadParam(new ConfigPath('profile.missing'), default: 'fallback')] string $missing,
            #[PayloadParam('profile.name')] string $literal,
        ): array => [$name, $middle, $missing, $literal],
        [ServerRequestInterface::class => $request],
    );

    expect($result)->toBe(['Ada', null, 'fallback', 'literal']);
})->with([false, true]);

test('payload paths traverse mixed arrays and objects without converting the extracted value', function (): void {
    $profile = (object) ['name' => 'Ada'];
    $request = (new ServerRequest('POST', '/'))->withParsedBody((object) [
        'groups' => [(object) ['members' => [['profile' => $profile]]]],
    ]);

    $result = container()->call(
        static fn(
            #[PayloadParam(new ConfigPath('groups.0.members.0.profile'))] object $profile,
        ): object => $profile,
        [ServerRequestInterface::class => $request],
    );

    expect($result)->toBe($profile);
});

test('payload paths only traverse accessible object data without magic property reads', function (): void {
    $profile = new class () {
        public int $reads = 0;
        private string $name = 'private';

        public function __get(string $name): string
        {
            ++$this->reads;
            return $this->name;
        }
    };
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['profile' => $profile]);

    expect((new PayloadParam(new ConfigPath('profile.name'), default: 'fallback'))->extract($request))
        ->toBe('fallback')
        ->and($profile->reads)->toBe(0);
});

test('nested object payloads follow the same public property hook semantics as root objects', function (): void {
    $profile = new class () {
        public int $reads = 0;
        public string $name {
            get {
                ++$this->reads;
                return 'Ada';
            }
        }
    };
    $request = (new ServerRequest('POST', '/'))->withParsedBody(['profile' => $profile]);

    expect((new PayloadParam(new ConfigPath('profile.name')))->extract($request))->toBe('Ada')
        ->and($profile->reads)->toBe(1);
});
