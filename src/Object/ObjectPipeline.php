<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Parameter\Request\MappedRequestContext;
use ReflectionClass;

/** Single object-creation runtime shared by reflection and compiled entries. */
final class ObjectPipeline
{
    /** @var array<class-string,ObjectMetadata> */
    private array $metadata = [];
    private int $metadataRevision = -1;
    private readonly AttributeProcessor $attributes;

    public function __construct(
        private readonly AttributePlanBuilder $plans,
        private readonly InstanceCreator $instances,
        private readonly ProxyFactoryInterface $proxies,
        private readonly AttributeDefinitionRegistry $registry,
        ?AttributeProcessor $attributes = null,
    ) {
        $this->attributes = $attributes ?? new AttributeProcessor($registry, $plans);
    }

    /** @param class-string $class */
    public function prepare(string $class): void
    {
        $metadata = $this->metadata($class);
        $this->attributes->prepare($metadata->class);
    }

    /**
     * @param class-string $class
     * @param array<string|int,mixed> $params
     */
    public function create(string $class, array $params = []): object
    {
        $metadata = $this->metadata($class);
        $creation = new ObjectCreationContext(
            $metadata->class,
            MappedRequestContext::strip($params),
        );

        $this->attributes->process(
            $metadata->class,
            AttributePhase::BeforeInstantiation,
            $creation,
        );

        return match ($creation->strategy) {
            CreationStrategy::Eager => $this->eager($metadata, $params, $creation),
            CreationStrategy::Lazy => $this->lazy($metadata, $params, $creation),
            CreationStrategy::Proxy => $this->proxy($metadata, $params, $creation),
        };
    }

    /** @param class-string $class */
    private function metadata(string $class): ObjectMetadata
    {
        $revision = $this->registry->revision;
        if ($revision !== $this->metadataRevision) {
            $this->metadata = [];
            $this->metadataRevision = $revision;
        }

        if (isset($this->metadata[$class])) {
            return $this->metadata[$class];
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);
        $classPlan = $this->plans->build($reflection);

        $constructor = $reflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $this->plans->build($parameter);
            }
        }

        $this->attributes->prepare($reflection);

        return $this->metadata[$class] = new ObjectMetadata($reflection, $classPlan);
    }

    /** @param array<string|int,mixed> $params */
    private function eager(
        ObjectMetadata $metadata,
        array $params,
        ObjectCreationContext $creation,
    ): object {
        $entry = $creation->constructorEnabled
            ? $this->instances->create($metadata->class, $params)
            : $metadata->class->newInstanceWithoutConstructor();

        $creation->initialize($entry);
        $this->attributes->process(
            $metadata->class,
            AttributePhase::AfterInstantiation,
            $creation,
        );

        return $entry;
    }

    /** @param array<string|int,mixed> $params */
    private function lazy(
        ObjectMetadata $metadata,
        array $params,
        ObjectCreationContext $creation,
    ): object {
        return $this->proxies->makeLazy(
            $metadata->class->getName(),
            function (object $entry) use ($metadata, $params, $creation): void {
                if ($creation->constructorEnabled) {
                    $this->instances->initialize($entry, $metadata->class, $params);
                }

                $attempt = $creation->freshAttempt();
                $attempt->initialize($entry);
                $this->attributes->process(
                    $metadata->class,
                    AttributePhase::AfterInstantiation,
                    $attempt,
                );
            },
        );
    }

    /** @param array<string|int,mixed> $params */
    private function proxy(
        ObjectMetadata $metadata,
        array $params,
        ObjectCreationContext $creation,
    ): object {
        return $this->proxies->makeProxy(
            $metadata->class->getName(),
            fn(object $_proxy): object => $this->eager(
                $metadata,
                $params,
                $creation->freshAttempt(),
            ),
        );
    }
}
