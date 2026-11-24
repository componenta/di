<?php

declare(strict_types=1);

namespace Componenta\DI\Benchmarks\Suite;

use Closure;
use Componenta\DI\Container;
use PhpBench\Attributes as Bench;

#[Bench\Revs(1)]
#[Bench\Iterations(15)]
final class MutationBench
{
    private Container $container;
    private ExternalProvider $provider;
    private Closure $decorator;
    private mixed $result;

    public function prepare(): void
    {
        $this->container = Fixtures::container(Fixtures::sections());
        $this->container->set('mutation.first', 1);
        $this->container->set('mutation.second', 2);
        $this->container->alias('mutation.alias', 'mutation.first');
        $this->container->get('mutation.alias');
        $this->provider = new ExternalProvider(['external.value' => 1]);
        $this->decorator = static fn(int $value): int => $value + 1;
    }

    public function verifySet(): void
    {
        Fixtures::check($this->container->get('mutation.alias') === 2, 'set() did not invalidate alias cache.');
    }

    public function verifyAlias(): void
    {
        Fixtures::check($this->container->get('mutation.alias') === 2, 'alias() did not retarget lookup.');
    }

    public function verifyDelegator(): void
    {
        Fixtures::check($this->container->get('mutation.alias') === 2, 'delegator() did not invalidate cached result.');
    }

    public function verifyExternal(): void
    {
        Fixtures::check(
            $this->container->has('external.value') && $this->container->get('external.value') === 1,
            'External registration failed.',
        );
    }

    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifySet')]
    public function benchReplaceShared(): void
    {
        $this->container->set('mutation.first', 2);
    }

    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyAlias')]
    public function benchRetargetAlias(): void
    {
        $this->container->alias('mutation.alias', 'mutation.second');
    }

    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyDelegator')]
    public function benchAppendDelegator(): void
    {
        $this->container->delegator('mutation.alias', $this->decorator);
    }

    #[Bench\BeforeMethods('prepare')]
    #[Bench\AfterMethods('verifyExternal')]
    public function benchAddExternalContainer(): void
    {
        $this->container->addContainer($this->provider);
    }

    public function prepareChangedExternal(): void
    {
        $this->prepare();
        $this->container->addContainer($this->provider);
        $this->container->get('external.value');
        Fixtures::check(!$this->container->has('external.added'), 'External addition fixture was already present.');
        $this->provider->entries['external.value'] = 2;
        $this->provider->entries['external.added'] = 3;
    }

    public function verifyNotification(): void
    {
        Fixtures::check(
            $this->container->get('external.value') === 2
            && $this->container->has('external.added')
            && $this->container->get('external.added') === 3,
            'External changes were not visible.',
        );
    }

    #[Bench\BeforeMethods('prepareChangedExternal')]
    #[Bench\AfterMethods('verifyNotification')]
    public function benchNotifyExternalChanged(): void
    {
        $this->container->notifyExternalEntriesChanged($this->provider, 'external.value', 'external.added');
    }

    public function prepareExternal(): void
    {
        $this->prepare();
        $this->container->addContainer($this->provider);
    }

    public function verifyExternalGet(): void
    {
        Fixtures::check($this->result === 1, 'Unexpected external get() result.');
    }

    public function verifyExternalHas(): void
    {
        Fixtures::check($this->result === true, 'Unexpected external has() result.');
    }

    #[Bench\BeforeMethods('prepareExternal')]
    #[Bench\AfterMethods('verifyExternalGet')]
    public function benchFirstExternalGet(): void
    {
        $this->result = $this->container->get('external.value');
    }

    #[Bench\BeforeMethods('prepareExternal')]
    #[Bench\AfterMethods('verifyExternalHas')]
    public function benchFirstExternalHas(): void
    {
        $this->result = $this->container->has('external.value');
    }

    #[Bench\BeforeMethods('prepareExternal')]
    #[Bench\AfterMethods('verifyExternalGet')]
    #[Bench\Revs(10000)]
    #[Bench\Iterations(7)]
    #[Bench\Warmup(100)]
    public function benchExternalGet(): void
    {
        $this->result = $this->container->get('external.value');
    }

    #[Bench\BeforeMethods('prepareExternal')]
    #[Bench\AfterMethods('verifyExternalHas')]
    #[Bench\Revs(10000)]
    #[Bench\Iterations(7)]
    #[Bench\Warmup(100)]
    public function benchExternalHas(): void
    {
        $this->result = $this->container->has('external.value');
    }
}
