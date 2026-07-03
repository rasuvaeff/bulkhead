<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Identical\FlipTypeControlToUseExclusiveTypeRector;
use Rector\CodeQuality\Rector\Identical\SimplifyBoolIdenticalTrueRector;
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
        // Golden rule 3 (AGENTS.md): the APCu lock's `apcu_add(...) === true`
        // must stay an explicit Identical — it is the anchor of a targeted
        // infection ignore, and the explicit form is the documented,
        // fork-stress-verified compare-and-set. Do not let Rector fold it to
        // a truthy check.
        SimplifyBoolIdenticalTrueRector::class => [__DIR__ . '/src/ApcuBulkheadStore.php'],
        // `$x === null` reads clearer than `!$x instanceof FQCN` in test setup
        // guards; keep the null comparison.
        FlipTypeControlToUseExclusiveTypeRector::class,
    ]);
