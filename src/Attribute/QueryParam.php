<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Config\DefaultValue;
use Componenta\DI\Resolver\Parameter\Request\CastableInterface;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestParameter;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
readonly class QueryParam implements ExtractorInterface, CastableInterface
{
    public function __construct(
        public ?string $name = null,
        public mixed $default = DefaultValue::None,
        public ?string $cast = null,
    ) {}

    public function extract(ServerRequestInterface $request): mixed
    {
        $name = $this->name ?? $request->getAttribute(RequestParameter::PARAMETER_NAME_ATTRIBUTE);
        if (!is_string($name) || $name === '') {
            throw new \LogicException('Query parameter name must be a non-empty string');
        }

        $params = $request->getQueryParams();
        if (!array_key_exists($name, $params)) {
            if ($this->default === DefaultValue::None) {
                throw new \RuntimeException(sprintf('Required query parameter "%s" is missing', $name));
            }
            return $this->default;
        }
        return $params[$name];
    }
}
