#!/usr/bin/env php
<?php

/**
 * Check for hardcoded constant values in PHP files.
 *
 * This script scans all PHP files in the project and identifies places where
 * constant values from MaskConstants and TestConstants are hardcoded instead
 * of using the actual constant references.
 *
 * Usage: php check_for_constants.php [--verbose]
 */

declare(strict_types=1);

// ANSI color codes for better readability
const COLOR_RED = "\033[31m";
const COLOR_GREEN = "\033[32m";
const COLOR_YELLOW = "\033[33m";
const COLOR_BLUE = "\033[34m";
const COLOR_MAGENTA = "\033[35m";
const COLOR_CYAN = "\033[36m";
const COLOR_RESET = "\033[0m";
const COLOR_BOLD = "\033[1m";

$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);

echo "\n";
echo sprintf("%s%s+%s+\n%s", COLOR_BOLD, COLOR_CYAN, str_repeat("=", 62), COLOR_RESET);
echo sprintf(
    "%s%s|  Constant Value Duplication Checker%s|\n%s",
    COLOR_BOLD,
    COLOR_CYAN,
    str_repeat(" ", 26),
    COLOR_RESET
);
echo sprintf("%s%s+%s+\n%s", COLOR_BOLD, COLOR_CYAN, str_repeat("=", 62), COLOR_RESET);
echo "\n";

// Load constant files
$maskConstantsFile = __DIR__ . '/src/MaskConstants.php';
$testConstantsFile = __DIR__ . '/tests/TestConstants.php';

if (!file_exists($maskConstantsFile)) {
    echo sprintf(
        "%sError: MaskConstants file not found at: $maskConstantsFile\n%s",
        COLOR_RED,
        COLOR_RESET
    );
    exit(1);
}

if (!file_exists($testConstantsFile)) {
    echo sprintf(
        "%sError: TestConstants file not found at: $testConstantsFile\n%s",
        COLOR_RED,
        COLOR_RESET
    );
    exit(1);
}

echo COLOR_BLUE . "Loading constants from:\n" . COLOR_RESET;
echo "  - src/MaskConstants.php\n";
echo "  - tests/TestConstants.php\n\n";

// Load composer autoloader to enable namespace imports
require_once __DIR__ . '/vendor/autoload.php';

use Ivuorinen\MonologGdprFilter\MaskConstants;
use Tests\TestConstants;

try {
    $maskReflection = new ReflectionClass(MaskConstants::class);
    $maskConstants = $maskReflection->getConstants();

    $testReflection = new ReflectionClass(TestConstants::class);
    $testConstants = $testReflection->getConstants();
} catch (ReflectionException $e) {
    echo sprintf("%sError loading constants: %s\n%s", COLOR_RED, $e->getMessage(), COLOR_RESET);
    exit(1);
}

echo sprintf(
    "%s✓ Loaded %s constants from MaskConstants\n%s",
    COLOR_GREEN,
    count($maskConstants),
    COLOR_RESET
);
echo sprintf(
    "%s✓ Loaded %s constants from TestConstants\n%s",
    COLOR_GREEN,
    count($testConstants),
    COLOR_RESET
);
echo sprintf(
    "%sℹ Note: TestConstants only checked in tests/ directory\n\n%s",
    COLOR_BLUE,
    COLOR_RESET
);

// Combine all constants for searching
$allConstants = [
    'MaskConstants' => $maskConstants,
    'TestConstants' => $testConstants,
];

// Find all PHP files to scan
$phpFiles = [];
$directories = [
    __DIR__ . '/src',
    __DIR__ . '/tests',
];

foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            // Skip the constant definition files themselves
            $realPath = $file->getRealPath();
            if ($realPath === $maskConstantsFile || $realPath === $testConstantsFile) {
                continue;
            }
            $phpFiles[] = $file->getRealPath();
        }
    }
}

echo COLOR_BLUE . "Scanning " . count($phpFiles)
    . " PHP files for hardcoded constant values...\n\n" . COLOR_RESET;

// Track findings
$findings = [];
$filesChecked = 0;
$totalMatches = 0;

// Scan each file for hardcoded constant values
foreach ($phpFiles as $filePath) {
    $filesChecked++;
    $content = file_get_contents($filePath);
    $lines = explode("\n", $content);

    // Determine if this is a test file
    $isTestFile = str_contains($filePath, '/tests/');

    foreach ($allConstants as $className => $constants) {
        // Skip TestConstants for non-test files (src/ directory)
        if ($className === 'TestConstants' && !$isTestFile) {
            continue;
        }

        foreach ($constants as $constantName => $constantValue) {
            // Skip non-string constants and empty values
            if (!is_string($constantValue) || strlen($constantValue) === 0) {
                continue;
            }

            // Skip very generic values that would produce too many false positives
            $skipGeneric = [
                'test', 'value', 'field', 'path', 'key',
                'data', 'name', 'id', 'type', 'error'
            ];
            if (
                in_array(strtolower($constantValue), $skipGeneric)
                && strlen($constantValue) < 10
            ) {
                continue;
            }

            // Additional filtering for src/ files - skip common internal identifiers
            if (!$isTestFile) {
                // In src/ files, skip values commonly used as array keys or internal identifiers
                $srcSkipValues = [
                    'masked', 'original', 'remove', 'message', 'password', 'email',
                    'user_id', 'sensitive_data', 'audit', 'security', 'application',
                    'Cannot be null or empty for REPLACE type',
                    'Rate limiting key cannot be empty',
                    'Test message'
                ];
                if (in_array($constantValue, $srcSkipValues)) {
                    continue;
                }
            }

            // Create search patterns for both single and double-quoted strings
            $patterns = [
                "'" . str_replace("'", "\\'", $constantValue) . "'",
                '"' . str_replace('"', '\\"', $constantValue) . '"',
            ];

            foreach ($patterns as $pattern) {
                $lineNumber = 0;
                foreach ($lines as $line) {
                    $lineNumber++;

                    // Skip lines that already use the constant
                    if (str_contains($line, $className . '::' . $constantName)) {
                        continue;
                    }

                    // Skip lines that are comments
                    $trimmedLine = trim($line);
                    if (
                        str_starts_with($trimmedLine, '//')
                        || str_starts_with($trimmedLine, '*')
                        || str_starts_with($trimmedLine, '/*')
                    ) {
                        continue;
                    }

                    if (str_contains($line, $pattern)) {
                        $relativePath = str_replace(__DIR__ . '/', '', $filePath);

                        if (!isset($findings[$relativePath])) {
                            $findings[$relativePath] = [];
                        }

                        $findings[$relativePath][] = [
                            'line' => $lineNumber,
                            'constant' => $className . '::' . $constantName,
                            'value' => $constantValue,
                            'content' => trim($line),
                        ];

                        $totalMatches++;
                    }
                }
            }
        }
    }
}

echo COLOR_BOLD . str_repeat("=", 64) . "\n" . COLOR_RESET;
echo COLOR_BOLD . "Scan Results\n" . COLOR_RESET;
echo COLOR_BOLD . str_repeat("=", 64) . "\n\n" . COLOR_RESET;

if (empty($findings)) {
    echo COLOR_GREEN . COLOR_BOLD . "✓ No hardcoded constant values found!\n\n" . COLOR_RESET;
    echo COLOR_GREEN . "All files are using proper constant references. "
        . "Great job! 🎉\n\n" . COLOR_RESET;
    exit(0);
}

echo sprintf(
    "%s%s⚠ Found %d potential hardcoded constant value(s) in %s file(s)\n\n%s",
    COLOR_YELLOW,
    COLOR_BOLD,
    $totalMatches,
    count($findings),
    COLOR_RESET
);

// Display findings grouped by file
foreach ($findings as $file => $matches) {
    echo sprintf(
        "%s%s📄 %s%s (%s match%s)\n",
        COLOR_BOLD,
        COLOR_MAGENTA,
        $file,
        COLOR_RESET,
        count($matches),
        count($matches) > 1 ? "es" : ""
    );
    echo COLOR_BOLD . str_repeat("─", 64) . "\n" . COLOR_RESET;

    foreach ($matches as $match) {
        echo sprintf("%s  Line %s: %s", COLOR_CYAN, $match['line'], COLOR_RESET);
        echo sprintf("Use %s%s%s", COLOR_YELLOW, $match['constant'], COLOR_RESET);
        echo sprintf(" instead of %s'%s'%s\n", COLOR_RED, addslashes($match['value']), COLOR_RESET);

        if ($verbose) {
            echo sprintf(
                "%s    Context: %s%s",
                COLOR_BLUE,
                COLOR_RESET,
                substr($match['content'], 0, 100)
            );
            if (strlen($match['content']) > 100) {
                echo "...";
            }
            echo "\n";
        }
    }

    echo "\n";
}

echo COLOR_BOLD . str_repeat("=", 64) . "\n\n" . COLOR_RESET;

echo COLOR_YELLOW . "Summary:\n" . COLOR_RESET;
echo sprintf("  • Files checked: %d\n", $filesChecked);
echo sprintf("  • Files with issues: %s\n", count($findings));
echo sprintf("  • Total matches: %d\n\n", $totalMatches);

echo sprintf(
    "%sTip: Use --verbose flag to see line context for each match\n%s",
    COLOR_BLUE,
    COLOR_RESET
);
echo sprintf("%sExample: php check_for_constants.php --verbose\n\n%s", COLOR_BLUE, COLOR_RESET);

exit(1);
