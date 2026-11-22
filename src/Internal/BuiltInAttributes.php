<?php

declare(strict_types=1);

namespace Componenta\DI\Internal;

use Componenta\Config\Config;
use Componenta\Config\ContainerValue;
use Componenta\DI\Attribute\Cast;
use Componenta\DI\Attribute\Composition\AttributeDefinition;
use Componenta\DI\Attribute\Composition\AttributeDefinitionRegistry;
use Componenta\DI\Attribute\Composition\AttributePlanBuilder;
use Componenta\DI\Attribute\Composition\Capability\AuthoritativeValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ConstructorPolicy;
use Componenta\DI\Attribute\Composition\Capability\CreationStrategy;
use Componenta\DI\Attribute\Composition\Capability\InvocationOnlyValueProvider;
use Componenta\DI\Attribute\Composition\Capability\LifecycleHook;
use Componenta\DI\Attribute\Composition\Capability\ValueProvider;
use Componenta\DI\Attribute\Composition\Capability\ValueTransformer;
use Componenta\DI\Attribute\Composition\Rule\ProxyCompositionRule;
use Componenta\DI\Attribute\Config as ConfigAttribute;
use Componenta\DI\Attribute\Cookie;
use Componenta\DI\Attribute\CurrentRequest;
use Componenta\DI\Attribute\CurrentUri;
use Componenta\DI\Attribute\EntryId;
use Componenta\DI\Attribute\Env;
use Componenta\DI\Attribute\Header;
use Componenta\DI\Attribute\Init;
use Componenta\DI\Attribute\Inject;
use Componenta\DI\Attribute\Lazy;
use Componenta\DI\Attribute\Make;
use Componenta\DI\Attribute\MapCookies;
use Componenta\DI\Attribute\MapHeaders;
use Componenta\DI\Attribute\MapQueryString;
use Componenta\DI\Attribute\MapRequest;
use Componenta\DI\Attribute\MapRequestAttributes;
use Componenta\DI\Attribute\MapRequestPayload;
use Componenta\DI\Attribute\MapServerParams;
use Componenta\DI\Attribute\MapUploadedFiles;
use Componenta\DI\Attribute\NoConstructor;
use Componenta\DI\Attribute\PayloadParam;
use Componenta\DI\Attribute\Proxy;
use Componenta\DI\Attribute\QueryParam;
use Componenta\DI\Attribute\RequestAttribute;
use Componenta\DI\Attribute\ServerParam;
use Componenta\DI\Attribute\SetUp;
use Componenta\DI\Attribute\Transform;
use Componenta\DI\Attribute\UploadedFile;
use Componenta\DI\Container;
use Componenta\DI\Internal\Resolver\Entry\ObjectResolutionParameterStore;
use Componenta\DI\Internal\Resolver\Parameter\Request\LazyCasterProvider;
use Componenta\DI\Internal\Resolver\Parameter\Request\LazyFactory;
use Componenta\DI\Internal\Resolver\Parameter\Request\LazyValidationProvider;
use Componenta\DI\ProxyFactoryInterface;
use Componenta\DI\Resolver\Attribute\AttributePhase;
use Componenta\DI\Resolver\Attribute\Handler\CastHandler;
use Componenta\DI\Resolver\Attribute\Handler\ConfigHandler;
use Componenta\DI\Resolver\Attribute\Handler\EntryIdHandler;
use Componenta\DI\Resolver\Attribute\Handler\EnvHandler;
use Componenta\DI\Resolver\Attribute\Handler\InitHandler;
use Componenta\DI\Resolver\Attribute\Handler\InjectHandler;
use Componenta\DI\Resolver\Attribute\Handler\LazyHandler;
use Componenta\DI\Resolver\Attribute\Handler\MakeHandler;
use Componenta\DI\Resolver\Attribute\Handler\NoConstructorHandler;
use Componenta\DI\Resolver\Attribute\Handler\RequestAttributeHandler;
use Componenta\DI\Resolver\Attribute\Handler\TransformHandler;
use Componenta\DI\Resolver\Entry\SetUp\ConfigUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\ContainerValueUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\EntryIdUnwrapper;
use Componenta\DI\Resolver\Entry\SetUp\EnvUnwrapper;
use Componenta\DI\Resolver\Entry\SetUpRunner;

/**
 * Registers the built-in attribute policies with their shared handlers.
 *
 * @internal
 * @phpstan-type Handlers array{
 *     cast:CastHandler,
 *     config:ConfigHandler,
 *     entryId:EntryIdHandler,
 *     env:EnvHandler,
 *     make:MakeHandler,
 *     request:RequestAttributeHandler,
 * }
 */
final class BuiltInAttributes
{
    /** @param Handlers $handlers */
    public static function register(
        AttributeDefinitionRegistry $registry,
        Container $container,
        Config $containerConfig,
        array $handlers,
        ObjectResolutionParameterStore $resolutionParameters,
        AttributeCallbackInvoker $attributeCallbacks,
    ): void {
        $cast = $handlers['cast'];
        $config = $handlers['config'];
        $entryId = $handlers['entryId'];
        $env = $handlers['env'];
        $make = $handlers['make'];
        $request = $handlers['request'];

        foreach ([
            [ConfigAttribute::class, $config, [ValueProvider::class]],
            [Env::class, $env, [ValueProvider::class]],
            [EntryId::class, $entryId, [ValueProvider::class]],
            [CurrentRequest::class, $request, [
                AuthoritativeValueProvider::class,
                InvocationOnlyValueProvider::class,
            ]],
            [CurrentUri::class, $request, [
                AuthoritativeValueProvider::class,
                InvocationOnlyValueProvider::class,
            ]],
            [Make::class, $make, [ValueProvider::class]],
            [Inject::class, new InjectHandler($container), [ValueProvider::class]],
            [Init::class, new InitHandler($container), [ValueProvider::class]],
        ] as [$attribute, $handler, $capabilities]) {
            $registry->register(new AttributeDefinition(
                $attribute,
                $handler,
                $capabilities,
            ));
        }

        foreach ([
            Header::class,
            Cookie::class,
            QueryParam::class,
            PayloadParam::class,
            RequestAttribute::class,
            ServerParam::class,
            UploadedFile::class,
            MapRequest::class,
            MapQueryString::class,
            MapRequestPayload::class,
            MapHeaders::class,
            MapCookies::class,
            MapRequestAttributes::class,
            MapServerParams::class,
            MapUploadedFiles::class,
        ] as $attribute) {
            $registry->register(new AttributeDefinition(
                $attribute,
                handler: $request,
                capabilities: [ValueProvider::class],
            ));
        }

        $registry->register(new AttributeDefinition(
            Cast::class,
            $cast,
            [ValueTransformer::class],
            after: [ValueProvider::class],
        ));
        $registry->register(new AttributeDefinition(
            Transform::class,
            new TransformHandler($attributeCallbacks),
            [ValueTransformer::class],
            after: [ValueProvider::class],
        ));
        $registry->register(new AttributeDefinition(
            Lazy::class,
            new LazyHandler(),
            [CreationStrategy::class],
            phase: AttributePhase::BeforeInstantiation,
        ));
        $registry->register(new AttributeDefinition(
            Proxy::class,
            $make,
            [CreationStrategy::class],
            before: [ValueTransformer::class],
            after: [Make::class],
            rules: [new ProxyCompositionRule()],
            phase: AttributePhase::Both,
        ));
        $registry->register(new AttributeDefinition(
            NoConstructor::class,
            new NoConstructorHandler(),
            [ConstructorPolicy::class],
            phase: AttributePhase::BeforeInstantiation,
        ));
        $registry->register(new AttributeDefinition(
            SetUp::class,
            new SetUpRunner(
                $container,
                $resolutionParameters,
                $attributeCallbacks,
                new ContainerValueUnwrapper(new ContainerValue($container, $containerConfig)),
                new EntryIdUnwrapper($container),
                new ConfigUnwrapper($container),
                new EnvUnwrapper($container),
            ),
            [LifecycleHook::class],
            phase: AttributePhase::AfterInstantiation,
        ));
    }

    /** @return Handlers */
    public static function handlers(
        Container $container,
        ProxyFactoryInterface $proxyFactory,
        AttributePlanBuilder $plans,
        AttributeCallbackInvoker $attributeCallbacks,
    ): array {
        return [
            'cast' => new CastHandler($container),
            'config' => new ConfigHandler($container),
            'entryId' => new EntryIdHandler($container),
            'env' => new EnvHandler($container),
            'make' => new MakeHandler($container, $proxyFactory, $plans),
            'request' => new RequestAttributeHandler(
                new LazyFactory($container),
                new LazyCasterProvider($container),
                new LazyValidationProvider($container),
                $attributeCallbacks,
            ),
        ];
    }
}
