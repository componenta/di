<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Config\DefaultValue;
use Componenta\DI\Resolver\Parameter\Request\CastableInterface;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
readonly class Header implements ExtractorInterface, CastableInterface
{
    public function __construct(
        public string $name,
        public mixed $default = DefaultValue::None,
        public ?string $cast = null,
    ) {}

    public function extract(ServerRequestInterface $request): mixed
    {
        $value = $request->hasHeader($this->name)
            ? $request->getHeaderLine($this->name)
            : null;

        if ($value === null) {
            if ($this->default === DefaultValue::None) {
                throw new \RuntimeException(
                    sprintf('Required header "%s" is missing', $this->name)
                );
            }

            return $this->default;
        }

        return $value;
    }
}