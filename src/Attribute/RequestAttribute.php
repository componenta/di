<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Config\DefaultValue;
use Componenta\DI\Resolver\Parameter\Request\CastableInterface;
use Componenta\DI\Resolver\Parameter\Request\ParameterNameAwareExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
readonly class RequestAttribute implements ParameterNameAwareExtractorInterface, CastableInterface
{
    public function __construct(
        public ?string $name = null,
        public mixed $default = DefaultValue::None,
        public ?string $cast = null,
    ) {}

    public function extract(ServerRequestInterface $request): mixed
    {
        return $this->extractNamed($request, $this->name);
    }

    public function extractForParameter(
        ServerRequestInterface $request,
        string $parameterName,
    ): mixed {
        return $this->extractNamed($request, $this->name ?? $parameterName);
    }

    private function extractNamed(ServerRequestInterface $request, ?string $name): mixed
    {
        if (!is_string($name) || $name === '') {
            throw new \LogicException('Request attribute name must be a non-empty string');
        }

        if (!array_key_exists($name, $request->getAttributes())) {
            if ($this->default === DefaultValue::None) {
                throw new \RuntimeException(sprintf('Required request attribute "%s" is missing', $name));
            }
            return $this->default;
        }
        return $request->getAttribute($name);
    }
}
