<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Config\DefaultValue;
use Componenta\DI\Resolver\Parameter\Request\CastableInterface;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
readonly class Cookie implements ExtractorInterface, CastableInterface
{
    public function __construct(
        public string $name,
        public mixed $default = DefaultValue::None,
        public ?string $cast = null,
    ) {}

    public function extract(ServerRequestInterface $request): mixed
    {
        $cookies = $request->getCookieParams();

        if (!array_key_exists($this->name, $cookies)) {
            if ($this->default === DefaultValue::None) {
                throw new \RuntimeException(
                    sprintf('Required cookie "%s" is missing', $this->name)
                );
            }

            return $this->default;
        }

        return $cookies[$this->name];
    }
}
