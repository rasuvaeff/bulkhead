<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveEmptyClassMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPrivateMethodRector;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(deadCode: true, codeQuality: true)
    // The test suite is reflection-driven by design: #[Property] generator
    // methods and anonymous BulkheadStore doubles implement interface methods
    // (with parameters Rector cannot see as "used"), so the dead-code rules
    // would strip them. Exempt those rules on the test tree.
    ->withSkip([
        RemoveUnusedPrivateMethodRector::class => [__DIR__ . '/tests'],
        RemoveEmptyClassMethodRector::class => [__DIR__ . '/tests'],
        RemoveUnusedPublicMethodParameterRector::class => [__DIR__ . '/tests'],
        // `@var mixed` on the predis `eval()` reply is load-bearing: it
        // suppresses Psalm's MixedAssignment at the untyped client boundary.
        RemoveUselessVarTagRector::class,
    ]);
