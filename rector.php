<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withImportNames(removeUnusedImports: true)
    ->withCache(
        cacheDirectory: 'build/rector',
        cacheClass: FileCacheStorage::class,
    )
    // Package-appropriate Laravel sets only — skipping app-specific sets
    // like LARAVEL_FACTORIES / LARAVEL_LEGACY_FACTORIES_TO_CLASSES that
    // assume an `app/Models` namespace.
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: false, // would change protected → private on our extensible command/controller helpers
        earlyReturn: true,
        codingStyle: true,
    )
    ->withPhpSets();
