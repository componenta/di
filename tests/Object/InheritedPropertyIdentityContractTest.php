<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Object\InheritedPropertyIdentityContract;

use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Tests\Support\ContainerBuilder;
use stdClass;

class DiContractPropertyOwner
{
    #[EntryId('identity.parent')]
    private object $ChildValue;

    public function __construct()
    {
        $this->ChildValue = new stdClass();
    }

    public function parentValue(): object
    {
        return $this->ChildValue;
    }
}

final class DiContractPropertyOwnerChild extends DiContractPropertyOwner
{
    #[EntryId('identity.child')]
    private object $Value;

    public function __construct()
    {
        parent::__construct();
        $this->Value = new stdClass();
    }

    public function childValue(): object
    {
        return $this->Value;
    }
}

class DiContractPropertyParent
{
    #[EntryId('identity.parent')]
    private object $ChildValue;

    public function __construct()
    {
        $this->ChildValue = new stdClass();
    }

    public function parentValue(): object
    {
        return $this->ChildValue;
    }
}

final class ValueDiContractPropertyParent extends DiContractPropertyParent
{
    #[EntryId('identity.child')]
    private object $Child;

    public function __construct()
    {
        parent::__construct();
        $this->Child = new stdClass();
    }

    public function childValue(): object
    {
        return $this->Child;
    }
}

test('inherited property injection keeps owner and property name boundaries distinct', function (string $class): void {
    if (!class_exists($class)) {
        throw new LogicException('Expected an inherited-property fixture.');
    }
    $parent = new stdClass();
    $child = new stdClass();
    $container = (new ContainerBuilder())
        ->addService('identity.parent', $parent)
        ->addService('identity.child', $child)
        ->build();

    foreach ([1, 2] as $_attempt) {
        $entry = $container->make($class);
        if (!$entry instanceof DiContractPropertyOwnerChild && !$entry instanceof ValueDiContractPropertyParent) {
            throw new LogicException('Expected the inherited-property owner.');
        }
        expect($entry->parentValue())->toBe($parent)
            ->and($entry->childValue())->toBe($child);
    }
})->with([
    'class name suffix' => DiContractPropertyOwnerChild::class,
    'class name prefix' => ValueDiContractPropertyParent::class,
]);
