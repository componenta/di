<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Fixture;

use BadMethodCallException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Minimal PSR-7 ServerRequestInterface fake - covers only the accessors /
 * mutators used by the RequestResolver + RequestMapper family of tests.
 *
 * Everything else throws {@see BadMethodCallException} so an accidental call
 * fails loudly instead of returning silent defaults. Each `with*()` returns
 * a clone (PSR-7 immutability contract).
 */
final class FakeServerRequest implements ServerRequestInterface
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    private UriInterface $uri;

    /** @param array<string, mixed> $attributes */
    public function __construct(
        private string $method = 'GET',
        string|UriInterface $uri = '/',
        private array $queryParams = [],
        private array $cookieParams = [],
        private array $serverParams = [],
        private array $uploadedFiles = [],
        private array $attributes = [],
        private mixed $parsedBody = null,
    ) {
        $this->uri = $uri instanceof UriInterface ? $uri : new FakeUri($uri);
    }

    public function getMethod(): string { return $this->method; }
    public function getUri(): UriInterface { return $this->uri; }

    public function getQueryParams(): array { return $this->queryParams; }
    public function getCookieParams(): array { return $this->cookieParams; }
    public function getServerParams(): array { return $this->serverParams; }
    public function getUploadedFiles(): array { return $this->uploadedFiles; }
    public function getAttributes(): array { return $this->attributes; }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    public function getParsedBody(): null|array|object { return $this->parsedBody; }

    public function getHeaders(): array { return $this->headers; }

    public function hasHeader(string $name): bool { return isset($this->headers[$name]); }

    public function getHeader(string $name): array { return $this->headers[$name] ?? []; }

    public function getHeaderLine(string $name): string
    {
        return implode(', ', $this->getHeader($name));
    }

    public function withQueryParams(array $query): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->queryParams = $query;
        return $clone;
    }

    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->cookieParams = $cookies;
        return $clone;
    }

    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->uploadedFiles = $uploadedFiles;
        return $clone;
    }

    public function withAttribute(string $name, mixed $value): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    public function withoutAttribute(string $name): ServerRequestInterface
    {
        $clone = clone $this;
        unset($clone->attributes[$name]);
        return $clone;
    }

    public function withParsedBody($data): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->parsedBody = $data;
        return $clone;
    }

    public function withHeader(string $name, $value): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->headers[$name] = is_array($value) ? array_values($value) : [(string) $value];
        return $clone;
    }

    public function withAddedHeader(string $name, $value): ServerRequestInterface
    {
        $clone = clone $this;
        $values = is_array($value) ? $value : [$value];
        $clone->headers[$name] = array_merge($clone->headers[$name] ?? [], array_map('strval', $values));
        return $clone;
    }

    public function withoutHeader(string $name): ServerRequestInterface
    {
        $clone = clone $this;
        unset($clone->headers[$name]);
        return $clone;
    }
    // Unused by tests - fail loudly if called.

    public function getProtocolVersion(): string
    {
        throw new BadMethodCallException('getProtocolVersion() unused by fixture');
    }

    public function withProtocolVersion(string $version): ServerRequestInterface
    {
        throw new BadMethodCallException('withProtocolVersion() unused by fixture');
    }

    public function getBody(): StreamInterface
    {
        throw new BadMethodCallException('getBody() unused by fixture');
    }

    public function withBody(StreamInterface $body): ServerRequestInterface
    {
        throw new BadMethodCallException('withBody() unused by fixture');
    }

    public function getRequestTarget(): string
    {
        throw new BadMethodCallException('getRequestTarget() unused by fixture');
    }

    public function withRequestTarget(string $requestTarget): ServerRequestInterface
    {
        throw new BadMethodCallException('withRequestTarget() unused by fixture');
    }

    public function withMethod(string $method): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->method = $method;
        return $clone;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): ServerRequestInterface
    {
        $clone = clone $this;
        $clone->uri = $uri;
        return $clone;
    }
}
