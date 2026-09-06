<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute;

use Componenta\Config\ConfigPath;
use Componenta\Config\DefaultValue;
use Componenta\DI\Resolver\Parameter\Request\CastableInterface;
use Componenta\DI\Resolver\Parameter\Request\ParameterNameAwareExtractorInterface;
use Psr\Http\Message\ServerRequestInterface;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
readonly class PayloadParam implements ParameterNameAwareExtractorInterface, CastableInterface
{
    public function __construct(
        public string|ConfigPath|null $name = null,
        public mixed $default = DefaultValue::None,
        public ?string $cast = null,
    ) {}

    public function extract(ServerRequestInterface $request): mixed
    {
        return $this->extractNamed($request, null);
    }

    public function extractForParameter(
        ServerRequestInterface $request,
        string $parameterName,
    ): mixed {
        return $this->extractNamed($request, $parameterName);
    }

    private function extractNamed(
        ServerRequestInterface $request,
        ?string $parameterName,
    ): mixed {
        $body = $this->parsedBody($request);

        if ($this->name instanceof ConfigPath) {
            /** @var list<string> $segments */
            $segments = array_values($this->name->toArray());
            $result = self::path($body, $segments);
            if ($result['found']) {
                return $result['value'];
            }
            if ($this->default === DefaultValue::None) {
                throw new \RuntimeException(sprintf(
                    'Required payload parameter "%s" is missing',
                    $this->name->value,
                ));
            }
            return $this->default;
        }

        $name = $this->name ?? $parameterName;
        if (!is_string($name) || $name === '') {
            throw new \LogicException('Payload parameter name must be a non-empty string');
        }

        if (!array_key_exists($name, $body)) {
            if ($this->default === DefaultValue::None) {
                throw new \RuntimeException(sprintf('Required payload parameter "%s" is missing', $name));
            }
            return $this->default;
        }
        return $body[$name];
    }

    /** @return array<string|int,mixed> */
    private function parsedBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if ($body === null) {
            return [];
        }
        return is_array($body) ? $body : get_object_vars($body);
    }

    /**
     * @param array<string|int,mixed> $data
     * @param list<string> $segments
     * @return array{found:bool,value:mixed}
     */
    private static function path(array $data, array $segments): array
    {
        $current = $data;
        foreach ($segments as $index => $segment) {
            if (!array_key_exists($segment, $current)) {
                return ['found' => false, 'value' => null];
            }
            $value = $current[$segment];
            if ($index === array_key_last($segments)) {
                return ['found' => true, 'value' => $value];
            }
            if (!is_array($value)) {
                return ['found' => false, 'value' => null];
            }
            /** @var array<string|int,mixed> $value */
            $current = $value;
        }
        return ['found' => true, 'value' => $data];
    }
}
