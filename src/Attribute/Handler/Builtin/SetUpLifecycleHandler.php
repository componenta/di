<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\DI\Attribute\Handler\LifecycleHookHandlerInterface;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ResolutionContext;
use LogicException;
use ReflectionClass;

final readonly class SetUpLifecycleHandler implements LifecycleHookHandlerInterface
{
    public function __construct(private CallableExecutorInterface $executor) {}

    public function run(
        object $attribute,
        object $entry,
        ReflectionClass $class,
        ResolutionContext $context,
    ): void {
        if (!$attribute instanceof SetUp) {
            throw new LogicException('SetUpLifecycleHandler received an unsupported attribute.');
        }

        if (!$class->hasMethod($attribute->method)) {
            throw new LogicException(sprintf('SetUp method %s::%s() does not exist.', $class->getName(), $attribute->method));
        }

        $method = $class->getMethod($attribute->method);
        if (!$method->isPublic() || $method->isStatic() || $method->isAbstract()) {
            throw new LogicException(sprintf(
                'SetUp method %s::%s() must be public, concrete and non-static.',
                $class->getName(),
                $attribute->method,
            ));
        }

        $this->executor->execute(
            [$entry, $attribute->method],
            new ResolutionContext(
                explicit: array_replace($context->explicit, $attribute->params),
                mapped: $context->mapped,
                trusted: $context->trusted,
            ),
        );
    }
}
