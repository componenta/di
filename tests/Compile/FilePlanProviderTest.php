<?php

declare(strict_types=1);

use Componenta\DI\Compile\FilePlanProvider;
use Componenta\DI\Compile\PlanCompiler;

describe('Compile\FilePlanProvider', function () {
    it('loads generated plan cache files lazily', function () {
        $file = tempnam(sys_get_temp_dir(), 'di-plans-');
        file_put_contents($file, '<?php return ' . var_export([
            'version' => PlanCompiler::CACHE_VERSION,
            PlanCompiler::CONFIG_KEY => [
                'param' => ['Service' => ['__construct' => [0 => 'kind']]],
                'prop' => ['Service' => ['property' => 'kind']],
            ],
        ], true) . ';');

        try {
            $provider = new FilePlanProvider($file);

            expect($provider->plans())
                ->toBe([
                    'param' => ['Service' => ['__construct' => [0 => 'kind']]],
                    'prop' => ['Service' => ['property' => 'kind']],
                ])
                ->and($provider->parameterPlan('Service', '__construct'))
                ->toBe([0 => 'kind'])
                ->and($provider->propertyPlan('Service', 'property'))
                ->toBe('kind')
                ->and($provider->plans())
                ->toBe([
                    'param' => ['Service' => ['__construct' => [0 => 'kind']]],
                    'prop' => ['Service' => ['property' => 'kind']],
                ]);
        } finally {
            @unlink($file);
        }
    });

    it('falls back to empty plans when the sidecar is missing or stale', function () {
        $missing = new FilePlanProvider(sys_get_temp_dir() . '/missing-di-plans-' . uniqid() . '.php');

        $file = tempnam(sys_get_temp_dir(), 'di-plans-');
        file_put_contents($file, '<?php return ' . var_export([
            'version' => PlanCompiler::CACHE_VERSION + 1,
            PlanCompiler::CONFIG_KEY => ['param' => ['Service' => []]],
        ], true) . ';');

        try {
            expect($missing->plans())
                ->toBe([])
                ->and((new FilePlanProvider($file))->plans())
                ->toBe([]);
        } finally {
            @unlink($file);
        }
    });

    it('loads indexed plan shards by class without requiring the full plan map', function () {
        $dir = sys_get_temp_dir() . '/di-plan-provider-' . bin2hex(random_bytes(4));
        $shardDir = $dir . '/di-plans.cache.d';
        mkdir($shardDir, 0o755, true);

        $main = $dir . '/di-plans.cache.php';
        $relativeShard = 'di-plans.cache.d/plan-00.php';

        file_put_contents($main, '<?php return ' . var_export([
            'version' => PlanCompiler::CACHE_VERSION,
            'format' => 'indexed',
            'index' => [
                'param' => ['Service' => $relativeShard],
                'prop' => ['Service' => $relativeShard],
            ],
        ], true) . ';');

        file_put_contents($dir . '/' . $relativeShard, '<?php return ' . var_export([
            'version' => PlanCompiler::CACHE_VERSION,
            PlanCompiler::CONFIG_KEY => [
                'param' => ['Service' => ['__construct' => [0 => 'kind']]],
                'prop' => ['Service' => ['property' => 'kind']],
            ],
        ], true) . ';');

        try {
            $provider = new FilePlanProvider($main);

            expect($provider->parameterPlan('Service', '__construct'))
                ->toBe([0 => 'kind'])
                ->and($provider->propertyPlan('Service', 'property'))
                ->toBe('kind')
                ->and($provider->parameterPlan('Missing', '__construct'))
                ->toBeNull()
                ->and($provider->plans())
                ->toBe([
                    'param' => ['Service' => ['__construct' => [0 => 'kind']]],
                    'prop' => ['Service' => ['property' => 'kind']],
                ]);
        } finally {
            @unlink($dir . '/' . $relativeShard);
            @unlink($main);
            @rmdir($shardDir);
            @rmdir($dir);
        }
    });
});
