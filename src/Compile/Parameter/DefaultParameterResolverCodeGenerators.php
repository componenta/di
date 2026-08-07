<?php

declare(strict_types=1);

namespace Componenta\DI\Compile\Parameter;

use Componenta\DI\Compile\Parameter\Generator\ArrayResolverCodeGenerator;
use Componenta\DI\Compile\Parameter\Generator\ArrayTypedResolverCodeGenerator;
use Componenta\DI\Compile\Parameter\Generator\AutowireByTypeResolverCodeGenerator;
use Componenta\DI\Compile\Parameter\Generator\DefaultValueResolverCodeGenerator;
use Componenta\DI\Compile\Parameter\Generator\NullableResolverCodeGenerator;
use Componenta\DI\Compile\Parameter\Generator\RuntimeParameterResolverCodeGenerator;
use Componenta\DI\Resolver\CastableResolver;
use Componenta\DI\Resolver\ConfigAttributeResolver;
use Componenta\DI\Resolver\CurrentUserResolver;
use Componenta\DI\Resolver\EntryIdResolver;
use Componenta\DI\Resolver\EnvResolver;
use Componenta\DI\Resolver\MakeAttributeResolver;
use Componenta\DI\Resolver\Parameter\ArrayResolver;
use Componenta\DI\Resolver\Parameter\ArrayTypedResolver;
use Componenta\DI\Resolver\Parameter\AutowireByTypeResolver;
use Componenta\DI\Resolver\Parameter\DefaultValueResolver;
use Componenta\DI\Resolver\Parameter\NullableResolver;
use Componenta\DI\Resolver\Parameter\Request\RequestResolver;

/** Creates the built-in resolver-to-generator registrations. */
final class DefaultParameterResolverCodeGenerators
{
    public static function create(): ParameterResolverCodeGeneratorRegistry
    {
        $registry = new ParameterResolverCodeGeneratorRegistry();

        $registry->register(ArrayResolver::class, new ArrayResolverCodeGenerator());
        $registry->register(ArrayTypedResolver::class, new ArrayTypedResolverCodeGenerator());
        $registry->register(AutowireByTypeResolver::class, new AutowireByTypeResolverCodeGenerator());
        $registry->register(DefaultValueResolver::class, new DefaultValueResolverCodeGenerator());
        $registry->register(NullableResolver::class, new NullableResolverCodeGenerator());

        $runtime = new RuntimeParameterResolverCodeGenerator(terminal: true);

        $registry->register(CastableResolver::class, $runtime);
        $registry->register(CurrentUserResolver::class, $runtime);
        $registry->register(RequestResolver::class, $runtime);
        $registry->register(MakeAttributeResolver::class, $runtime);
        $registry->register(EnvResolver::class, $runtime);
        $registry->register(EntryIdResolver::class, $runtime);
        $registry->register(ConfigAttributeResolver::class, $runtime);

        return $registry;
    }
}
