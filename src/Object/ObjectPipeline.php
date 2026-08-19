<?php

declare(strict_types=1);

namespace Componenta\DI\Object;

use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Entry\InstanceCreator;
use Componenta\DI\Resolver\Entry\ObjectCreationContext;
use Componenta\DI\Resolver\Target\ParameterTarget;
use ReflectionClass;

use function Componenta\DI\is_entry_class_eligible;

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

    /** @param class-string|ReflectionClass<object> $class */
    public function prepare(string|ReflectionClass $class): void
    {
        $this->metadata($class);
    }

    /** @param class-string|ReflectionClass<object> $class */
    public function canCreate(string|ReflectionClass $class): bool
    {
        $metadata = $this->metadata($class);
        if (!is_entry_class_eligible($metadata->class)) {
            return false;
        }
        if ($metadata->class->isInstantiable()) {
            return true;
        }

        return $metadata->classPlan->has(ConstructorPolicy::class);
    }

    /** @param class-string|ReflectionClass<object> $class */
    public function canUsePlainConstructorFastPath(string|ReflectionClass $class): bool
    {
        $metadata = $this->metadata($class);
        return $metadata->class->isInstantiable()
            && !$metadata->hasAttributeHandlers;
    }

    /** @param class-string|ReflectionClass<object> $class */
    public function canDirectInstantiate(string|ReflectionClass $class): bool
    {
        $metadata = $this->metadata($class);
        return $this->canCreate($metadata->class)
            && $this->canUsePlainConstructorFastPath($metadata->class)
            && ($metadata->constructor === null || $metadata->constructorTargets === []);
    }

    /**
     * @param class-string|ReflectionClass<object> $class
     * @return list<ParameterTarget>
     */
    public function constructorTargets(string|ReflectionClass $class): array
    {
        return $this->metadata($class)->constructorTargets;
    }

    /**
     * @param class-string|ReflectionClass<object> $class
     * @param array<string|int,mixed> $params
     */
    public function create(string|ReflectionClass $class, array $params = []): object
    {
        $metadata = $this->metadata($class);
        if (!$metadata->hasAttributeHandlers) {
            return $this->instances->createPrepared(
                $metadata->class,
                $metadata->constructor,
                $metadata->constructorTargets,
                $params,
            );
        }

        $creation = new ObjectCreationContext($metadata->class, $params);

        $this->attributes->process(
            $metadata->class,
            AttributePhase::BeforeInstantiation,
            $creation,
        );

        return match ($creation->strategy) {
            CreationStrategy::Eager => $this->eager($metadata, $creation),
            CreationStrategy::Lazy => $this->lazy($metadata, $creation),
            CreationStrategy::Proxy => $this->proxy($metadata, $creation),
        };
    }

    /** @param class-string|ReflectionClass<object> $class */
    private function metadata(string|ReflectionClass $class): ObjectMetadata
    {
        $revision = $this->registry->revision;
        if ($revision !== $this->metadataRevision) {
            $this->metadata = [];
            $this->metadataRevision = $revision;
        }

        $name = $class instanceof ReflectionClass
            ? $class->getName()
            : ltrim($class, '\\');
        if (isset($this->metadata[$name])) {
            return $this->metadata[$name];
        }

        /** @var ReflectionClass<object> $reflection */
        $reflection = $class instanceof ReflectionClass
            ? $class
            : new ReflectionClass($class);
        $name = $reflection->getName();
        $classPlan = $this->plans->build($reflection);
        $constructor = $reflection->getConstructor();
        $constructorTargets = $constructor === null
            ? []
            : $this->instances->targets($constructor);

        foreach ($constructorTargets as $target) {
            $this->plans->build($target->reflection);
        }

        $hasAttributeHandlers = $this->attributes->hasHandlers($reflection);

        return $this->metadata[$name] = new ObjectMetadata(
            $reflection,
            $classPlan,
            $constructor,
            $constructorTargets,
            $hasAttributeHandlers,
        );
    }

    private function eager(
        ObjectMetadata $metadata,
        ObjectCreationContext $creation,
    ): object {
        $entry = $creation->constructorEnabled
            ? $this->instances->createPrepared(
                $metadata->class,
                $metadata->constructor,
                $metadata->constructorTargets,
                $creation->resolutionParameters(),
            )
            : $metadata->class->newInstanceWithoutConstructor();

        $creation->initialize($entry);
        $this->attributes->process(
            $metadata->class,
            AttributePhase::AfterInstantiation,
            $creation,
        );

        return $entry;
    }

    private function lazy(
        ObjectMetadata $metadata,
        ObjectCreationContext $creation,
    ): object {
        return $this->proxies->makeLazy(
            $metadata->class->getName(),
            function (object $entry) use ($metadata, $creation): void {
                $attempt = $creation->freshAttempt();
                if ($attempt->constructorEnabled) {
                    $this->instances->initializePrepared(
                        $entry,
                        $metadata->constructor,
                        $metadata->constructorTargets,
                        $attempt->resolutionParameters(),
                    );
                }

                $attempt->initialize($entry);
                $this->attributes->process(
                    $metadata->class,
                    AttributePhase::AfterInstantiation,
                    $attempt,
                );
            },
        );
    }

    private function proxy(
        ObjectMetadata $metadata,
        ObjectCreationContext $creation,
    ): object {
        return $this->proxies->makeProxy(
            $metadata->class->getName(),
            fn(object $_proxy): object => $this->eager(
                $metadata,
                $creation->freshAttempt(),
            ),
        );
    }
}
