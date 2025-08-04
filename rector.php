<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Exception\Configuration\InvalidConfigurationException;

try {
    return RectorConfig::configure()
        ->withPaths([
            __DIR__ . '/src',
            __DIR__ . '/tests',
        ])
        ->withPhpVersion(80200)
        ->withPhpSets(php82: true)
        ->withComposerBased(phpunit: true)
        ->withImportNames(removeUnusedImports: true)
        ->withPreparedSets(
            deadCode: true,
            codeQuality: true,
            codingStyle: true,
            earlyReturn: true,
            phpunitCodeQuality: true,
        );
} catch (InvalidConfigurationException $e) {
    echo "Configuration error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
