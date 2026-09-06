<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Parameter\Request;

use Psr\Http\Message\ServerRequestInterface;

/** Extractor that can use the declaring DI parameter name without mutating the PSR-7 request. */
interface ParameterNameAwareExtractorInterface extends ExtractorInterface
{
    public function extractForParameter(
        ServerRequestInterface $request,
        string $parameterName,
    ): mixed;
}
