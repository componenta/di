<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Config\DefaultValue;
use Componenta\DI\Resolver\Parameter\Request\CastableInterface;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
readonly class RequestAttribute implements ExtractorInterface, CastableInterface
{
    public function __construct(
        public ?string $name = null,
        public mixed $default = DefaultValue::None,
        public ?string $cast = null,
    ) {}

    public function extract(ServerRequestInterface $request): mixed
    {
        $name = $this->name ?? $request->getAttribute(RequestResolver::PARAMETER_NAME_ATTRIBUTE);

        if ($name === null) {
            throw new \LogicException('Request attribute name cannot be null');
        }

        if (!array_key_exists($name, $request->getAttributes())) {
            if ($this->default === DefaultValue::None) {
                throw new \RuntimeException(
                    sprintf('Required request attribute "%s" is missing', $name)
                );
            }

            return $this->default;
        }

        return $request->getAttribute($name);
    }
}
