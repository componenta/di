<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use Componenta\DI\Attribute\Config;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\ConfigValueExtractor;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * Unwraps {@see Config} value-objects inside SetUp params by reading the value
 * from the configuration entry registered in the container.
 *
 * Delegation-only: lookup lives in {@see ConfigValueExtractor}. Native
 * {@see \InvalidArgumentException} / {@see \OutOfBoundsException} raised by
 * the extractor are wrapped into {@see ResolutionException}; PSR-11 and DI
 * exceptions pass through unchanged.
 */
final readonly class ConfigUnwrapper implements SetUpValueUnwrapperInterface
{
    private ConfigValueExtractor $extractor;

    public function __construct(
        private ContainerInterface $container,
        ?ConfigValueExtractor $extractor = null,
    ) {
        $this->extractor = $extractor ?? new ConfigValueExtractor();
    }

    public function supports(mixed $value): bool
    {
        return $value instanceof Config;
    }

    public function unwrap(mixed $value, string $key): mixed
    {
        /** @var Config $value */
        try {
            $configData = $this->container->get(Config::KEY);

            return $this->extractor->extract($configData, $value, $key);
        } catch (ContainerExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ResolutionException(
                sprintf(
                    'Cannot unwrap #[SetUp] param "%s" (#[Config] attribute): %s',
                    $key,
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }
    }
}
