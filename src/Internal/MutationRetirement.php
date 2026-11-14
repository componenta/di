<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\DI\Exception\MutationRetirementException;
use LogicException;
use Throwable;

/** Holds retired roots until a mutation has completed every state write. @internal */
final class MutationRetirement
{
    /** @var list<mixed> */
    private array $values = [];
    private bool $released = false;

    public function __construct(public readonly ?string $id) {}

    public function retain(mixed ...$values): void
    {
        if ($this->released) {
            throw new LogicException('Cannot retain values after mutation retirement has begun.');
        }
        foreach ($values as $value) {
            $this->values[] = $value;
        }
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $values = array_reverse($this->values);
        $this->released = true;
        $this->values = [];
        $causes = [];

        while ($values !== []) {
            try {
                // The unused return drops this root inside the protected boundary.
                array_pop($values);
            } catch (Throwable $cause) {
                $causes[] = $cause;
            }
        }

        if ($causes !== []) {
            throw new MutationRetirementException($this->id, $causes);
        }
    }

}
