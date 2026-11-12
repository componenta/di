<?php

declare(strict_types=1);

namespace Componenta\DI\Tests\Contract\ClassDefinitionValueLifetime;

use Componenta\DI\Definition\ClassDefinition;
use Componenta\DI\Definition\Definition;
use Componenta\DI\Tests\Support\ContainerBuilder;
use LogicException;
use stdClass;

final class Product
{
    public object $methodDependency;
    public object $methodDefault;
    /** @var array<array-key, mixed> */
    public array $methodTail;
    /** @var array<array-key, mixed> */
    public array $tail;

    public function __construct(
        public object $dependency,
        public string $input,
        public object $default = new stdClass(),
        mixed ...$tail,
    ) {
        $this->tail = $tail;
    }

    public function configure(object $dependency, object $default = new stdClass(), mixed ...$tail): void
    {
        $this->methodDependency = $dependency;
        $this->methodDefault = $default;
        $this->methodTail = $tail;
    }
}

test('fresh class definitions reevaluate references and object defaults without retaining runtime arguments', function (bool $positionalTail): void {
    $initial = new stdClass();
    $replacement = new stdClass();
    $override = new stdClass();
    $container = (new ContainerBuilder())
        ->addService('dependency', $initial)
        ->addDefinition('product', ClassDefinition::create(Product::class)
            ->constructor(['dependency' => Definition::reference('dependency')])
            ->call('configure', ['dependency' => Definition::reference('dependency')] + ($positionalTail ? [2 => 'method-tail'] : []))
            ->autowire())
        ->build();
    $previous = null;

    foreach (['first', 'second', 'override'] as $input) {
        if ($input === 'second') {
            $container->set('dependency', $replacement);
        }
        $params = ['input' => $input] + ($positionalTail ? [3 => $input . '-tail'] : []);
        if ($input === 'override') {
            $params['dependency'] = $override;
        }
        $product = $container->make('product', $params);
        if (!$product instanceof Product) {
            throw new LogicException('Expected the configured product.');
        }
        $expected = $input === 'first' ? $initial : $replacement;

        expect($product->dependency)->toBe($input === 'override' ? $override : $expected)
            ->and($product->methodDependency)->toBe($expected)
            ->and($product->input)->toBe($input)
            ->and($product->tail)->toBe($positionalTail ? [$input . '-tail'] : [])
            ->and($product->methodTail)->toBe($positionalTail ? ['method-tail'] : [])
            ->and($product->default)->toBeInstanceOf(stdClass::class)
            ->and($product->methodDefault)->toBeInstanceOf(stdClass::class)
            ->and($product->default)->not->toBe($product->methodDefault);
        if ($previous !== null) {
            expect($product->default)->not->toBe($previous->default)
                ->and($product->methodDefault)->not->toBe($previous->methodDefault);
        }
        $previous = $product;
    }
})->with(['native omitted defaults' => false, 'defaults before positional variadics' => true]);
