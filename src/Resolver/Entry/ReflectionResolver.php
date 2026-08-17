<?php

declare(strict_types=1);

namespace Componenta\DI\Resolver\Entry;

use Componenta\DI\Exception\NotFoundException;
use Componenta\DI\Exception\ResolutionException;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributeExecutionPlan;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\AttributeProcessor;
use Componenta\DI\Resolver\Attribute\CreationStrategy;
use Componenta\Reflection\Reflection;
use Psr\Container\ContainerExceptionInterface;
use ReflectionClass;
use Throwable;

/** Reflection fallback for entries without an explicit or generated factory. */
final class ReflectionResolver implements EntryResolverInterface
{
    /** @var array<string, true> */
    private array $missingClasses = [];

    public function __construct(
        private readonly InstanceCreator $instanceCreator,
        private readonly AttributeProcessor $attributes,
        private readonly ProxyFactoryInterface $proxyFactory,
    ) {}

    public function can(string $id): bool
    {
        return ($class = $this->reflect($id)) !== null
            && EntryClassEligibility::allows($class);
    }

    /** @throws NotFoundException|ResolutionException */
    public function resolve(string $id, array $context = []): object
    {
        $reflector = $this->reflect($id);
        if ($reflector === null || !EntryClassEligibility::allows($reflector)) {
            throw NotFoundException::forService($id);
        }

        try {
            $plan = $this->attributes->plan($reflector);
            if ($plan->isEmpty()) {
                return $this->instanceCreator->create($reflector, $context);
            }

            $creation = new ObjectCreationContext(
                class: $reflector,
                parameters: $context,
            );

            $this->attributes->processPlan(
                $plan,
                AttributePhase::BeforeInstantiation,
                $creation,
            );

            return match ($creation->strategy) {
                CreationStrategy::Eager => $this->buildEager($creation, $plan),
                CreationStrategy::Lazy => $this->buildLazy($creation, $plan),
                CreationStrategy::Proxy => $this->buildVirtualProxy($creation, $plan),
            };
        } catch (ContainerExceptionInterface|ResolutionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($id, $e);
        }
    }

    private function buildEager(
        ObjectCreationContext $context,
        AttributeExecutionPlan $plan,
    ): object {
        try {
            $entry = $context->constructorEnabled
                ? $this->instanceCreator->create(
                    $context->class,
                    $context->resolutionParameters(),
                )
                : $context->class->newInstanceWithoutConstructor();

            $this->complete($context, $entry, $plan);

            return $entry;
        } catch (ContainerExceptionInterface|ResolutionException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw ResolutionException::forService($context->class->getName(), $e);
        }
    }

    private function buildLazy(
        ObjectCreationContext $context,
        AttributeExecutionPlan $plan,
    ): object {
        return $this->proxyFactory->makeLazy(
            $context->class->getName(),
            function (object $entry) use ($context, $plan): void {
                $attempt = $context->freshAttempt();

                try {
                    if ($attempt->constructorEnabled) {
                        $this->instanceCreator->initialize(
                            $entry,
                            $attempt->class,
                            $attempt->resolutionParameters(),
                        );
                    }

                    $this->complete($attempt, $entry, $plan);
                } catch (ContainerExceptionInterface|ResolutionException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    throw ResolutionException::forService(
                        $attempt->class->getName(),
                        $e,
                    );
                }
            },
        );
    }

    private function buildVirtualProxy(
        ObjectCreationContext $context,
        AttributeExecutionPlan $plan,
    ): object {
        return $this->proxyFactory->makeProxy(
            $context->class->getName(),
            fn(object $proxy): object => $this->buildEager($context->freshAttempt(), $plan),
        );
    }

    private function complete(
        ObjectCreationContext $context,
        object $entry,
        AttributeExecutionPlan $plan,
    ): void {
        $context->initialize($entry);

        $this->attributes->processPlan(
            $plan,
            AttributePhase::AfterInstantiation,
            $context,
        );
    }

    /** @return ReflectionClass<object>|null */
    private function reflect(string $id): ?ReflectionClass
    {
        $key = strtolower(ltrim($id, '\\'));

        if (isset($this->missingClasses[$key])) {
            return null;
        }

        $class = Reflection::class($id);

        if ($class === null) {
            $this->missingClasses[$key] = true;
        }

        return $class;
    }
}
