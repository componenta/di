<?php

declare(strict_types=1);

namespace Componenta\DI;

/**
 * Componenta package discovery provider.
 *
 * DI v5 installs its built-in parameter resolvers and attribute definitions in
 * ContainerBuilder, so package discovery no longer needs to duplicate runtime
 * registrations. The provider remains to preserve Componenta composer-plugin
 * discovery and the public package integration contract from v4.
 */
final class ConfigProvider extends \Componenta\Config\ConfigProvider {}
