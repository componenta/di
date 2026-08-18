<?php

declare(strict_types=1);

namespace Componenta\DI\Attribute\Handler\Builtin;

use Componenta\DI\Attribute\Handler\LifecycleHookHandlerInterface;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ResolutionContext;
use Componenta\DI\Resolver\Entry\SetUp\SetUpValueUnwrapperInterface;
use LogicException;
use ReflectionClass;

/** Executes repeatable post-population #[SetUp] hooks in declaration order. */
final readonly class SetUpLifecycleHandler implements LifecycleHookHandlerInterface
{
    /** @var list<SetUpValueUnwrapperInterface> */
    private array $valueUnwrappers;

    public function __construct(
        private CallableExecutorInterface $executor,
        SetUpValueUnwrapperInterface ...$valueUnwrappers,
    ) {
        $this->valueUnwrappers = array_values($valueUnwrappers);
    }

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
                explicit: array_replace($context->explicit, $this->unwrapParams($attribute->params)),
                mapped: $context->mapped,
                trusted: $context->trusted,
            ),
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function unwrapParams(array $params): array
    {
        if ($this->valueUnwrappers === []) {
            return $params;
        }

        $resolved = [];
        foreach ($params as $key => $value) {
            $resolved[$key] = $this->unwrap($value, (string) $key);
        }
        return $resolved;
    }

    private function unwrap(mixed $value, string $key): mixed
    {
        foreach ($this->valueUnwrappers as $unwrapper) {
            if ($unwrapper->supports($value)) {
                return $unwrapper->unwrap($value, $key);
            }
        }
        return $value;
    }
}
