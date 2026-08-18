<?php

declare(strict_types=1);

namespace Componenta\DI\Value\Fallback;

use Componenta\DI\Resolver\Target\ValueTargetInterface;
use Componenta\DI\Value\ValueContext;
use Componenta\DI\Value\ValueFallbackInterface;
use Componenta\DI\Value\ValueResult;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/** Framework-owned context such as the current PSR-7 request. */
final readonly class TrustedValueFallback implements ValueFallbackInterface
{
    public function supports(ValueTargetInterface $target): bool
    {
        return $target->typeNames !== [] || $target->name !== '';
    }

    public function resolve(ValueTargetInterface $target, ValueContext $context): ?ValueResult
    {
        $trusted = $context->resolution->trusted;

        if (array_key_exists($target->name, $trusted)) {
            return new ValueResult($trusted[$target->name]);
        }

        foreach ($target->typeNames as $typeName) {
            if (array_key_exists($typeName, $trusted)) {
                return new ValueResult($trusted[$typeName]);
            }
        }

        $request = $context->resolution->request();
        if ($request === null) {
            return null;
        }

        if (in_array(ServerRequestInterface::class, $target->typeNames, true)) {
            return new ValueResult($request);
        }

        if (in_array(UriInterface::class, $target->typeNames, true)) {
            return new ValueResult($request->getUri());
        }

        return null;
    }
}
