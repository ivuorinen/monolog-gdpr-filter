<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromStrictConstructorRector;
use Rector\CodeQuality\Rector\FuncCall\SimplifyRegexPatternRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/examples',
        __DIR__ . '/config',
    ])
    ->withPhpSets(
        php82: true,
    )
    ->withSets([
        // Only use very conservative, safe rule sets
        SetList::CODE_QUALITY,      // Safe code quality improvements
        SetList::TYPE_DECLARATION,  // Type declarations (generally safe)
    ])
    ->withSkip([
        // Skip risky transformations that can break existing functionality

        // Skip readonly class conversion - can break existing usage
        ReadOnlyClassRector::class,

        // Skip automatic property typing - can break existing flexibility
        TypedPropertyFromStrictConstructorRector::class,

        // Skip regex pattern simplification - can break regex behavior ([0-9] vs \d with unicode)
        SimplifyRegexPatternRector::class,

        // Skip entire directories for certain transformations
        '*/tests/*' => [
            // Don't modify test methods or assertions - they have specific requirements
        ],

        // Skip specific files that are sensitive
        __DIR__ . '/src/GdprProcessor.php' => [
            // Don't modify the main processor class structure
        ],

        // Skip Laravel integration files - they have specific requirements
        __DIR__ . '/src/Laravel/*' => [
            // Don't modify Laravel-specific code
        ],
    ])
    ->withImportNames(
        importNames: true,
        importDocBlockNames: false, // Don't modify docblock imports - can break documentation
        importShortClasses: false,  // Don't import short class names - can cause conflicts
        removeUnusedImports: true,  // This is generally safe
    )
    // Conservative PHP version targeting
    ->withPhpVersion(80200)
    // Don't use prepared sets - they're too aggressive
    ->withPreparedSets(
        deadCode: false,           // Disable dead code removal
        codingStyle: false,        // Disable coding style changes
        earlyReturn: false,        // Disable early return changes
        phpunitCodeQuality: false, // Disable PHPUnit modifications
        strictBooleans: false,     // Disable strict boolean changes
        privatization: false,      // Disable privatization changes
        naming: false,             // Disable naming changes
        typeDeclarations: false,   // Disable type declaration changes
    );
