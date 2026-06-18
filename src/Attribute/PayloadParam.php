<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Config\DefaultValue;
use Componenta\Config\ConfigPath;
use Componenta\DI\Resolver\Parameter\Request\CastableInterface;
use Componenta\DI\Resolver\Parameter\Request\ExtractorInterface;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;

use function Componenta\Array\array_path_or_fail;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
readonly class PayloadParam implements ExtractorInterface, CastableInterface
{
    public function __construct(
        public string|ConfigPath|null $name = null,
        public mixed $default = DefaultValue::None,
        public ?string $cast = null,
    ) {}

    public function extract(ServerRequestInterface $request): mixed
    {
        $body = $this->getParsedBody($request);

        if ($this->name instanceof ConfigPath) {
            try {
                return array_path_or_fail($body, $this->name->value);
            } catch (InvalidArgumentException) {
                if ($this->default === DefaultValue::None) {
                    throw new \RuntimeException(
                        sprintf('Required payload parameter "%s" is missing', $this->name->value)
                    );
                }

                return $this->default;
            }
        }

        $name = $this->name ?? $request->getAttribute(RequestResolver::PARAMETER_NAME_ATTRIBUTE);

        if ($name === null) {
            throw new \LogicException('Payload parameter name cannot be null');
        }

        if (!array_key_exists($name, $body)) {
            if ($this->default === DefaultValue::None) {
                throw new \RuntimeException(
                    sprintf('Required payload parameter "%s" is missing', $name)
                );
            }

            return $this->default;
        }

        return $body[$name];
    }

    private function getParsedBody(ServerRequestInterface $request): array
    {
        if (($body = $request->getParsedBody()) === null) {
            return [];
        }

        return is_array($body) ? $body : get_object_vars($body);
    }
}
