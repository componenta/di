<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry\SetUp;

use ArrayAccess;
use Componenta\DI\Attribute\Config;
use Componenta\DI\Exception\InvalidConfigurationException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\Resolver\ConfigValueExtractor;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Throwable;

/** Unwraps #[Config] values inside SetUp parameters. */
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

            if (!is_array($configData) && !$configData instanceof ArrayAccess) {
                throw new InvalidConfigurationException(sprintf(
                    'Configuration service "%s" must be an array or %s; got %s.',
                    Config::KEY,
                    ArrayAccess::class,
                    get_debug_type($configData),
                ));
            }

            /** @var array<string, mixed>|ArrayAccess<string, mixed> $configData */
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
