<?php

declare(strict_types=1);

/**
 * GDPR Pattern Tester - Interactive Demo
 *
 * This is a simple web interface for testing GDPR masking patterns.
 * Run with: php -S localhost:8080 demo/index.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Ivuorinen\MonologGdprFilter\Demo\PatternTester;

// Auto-load the PatternTester class
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Ivuorinen\\MonologGdprFilter\\Demo\\')) {
        $file = __DIR__ . '/' . substr($class, strlen('Ivuorinen\\MonologGdprFilter\\Demo\\')) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});

$tester = new PatternTester();

// Handle API requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE'])) {
    if (str_contains($_SERVER['CONTENT_TYPE'], 'application/json')) {
        header('Content-Type: application/json');

        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            echo json_encode(['error' => 'Invalid JSON input']);
            exit;
        }

        $action = $input['action'] ?? 'test';

        $result = match ($action) {
            'test_patterns' => $tester->testPatterns(
                $input['text'] ?? '',
                $input['patterns'] ?? []
            ),
            'test_processor' => $tester->testProcessor(
                $input['message'] ?? '',
                $input['context'] ?? [],
                $input['patterns'] ?? [],
                $input['field_paths'] ?? []
            ),
            'test_strategies' => $tester->testStrategies(
                $input['message'] ?? '',
                $input['context'] ?? [],
                $input['patterns'] ?? []
            ),
            'validate_pattern' => $tester->validatePattern($input['pattern'] ?? ''),
            'get_defaults' => ['patterns' => $tester->getDefaultPatterns()],
            default => ['error' => 'Unknown action'],
        };

        echo json_encode($result, JSON_PRETTY_PRINT);
        exit;
    }
}

// Serve the HTML template
$templatePath = __DIR__ . '/templates/playground.html';
if (file_exists($templatePath)) {
    readfile($templatePath);
} else {
    // Fallback inline template
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GDPR Pattern Tester</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background: #f5f5f5;
        }
        h1 { color: #333; }
        .container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .panel {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .full-width { grid-column: 1 / -1; }
        label { display: block; margin-bottom: 5px; font-weight: 600; }
        textarea, input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: monospace;
            font-size: 14px;
        }
        textarea { min-height: 150px; resize: vertical; }
        button {
            background: #007bff;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        button:hover { background: #0056b3; }
        .result {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-top: 10px;
            font-family: monospace;
            white-space: pre-wrap;
            word-break: break-all;
        }
        .error { background: #f8d7da; color: #721c24; }
        .success { background: #d4edda; color: #155724; }
        .match { background: #fff3cd; padding: 2px 4px; border-radius: 2px; }
        .patterns-list {
            max-height: 200px;
            overflow-y: auto;
            font-size: 12px;
        }
        .pattern-item {
            padding: 5px;
            border-bottom: 1px solid #eee;
        }
        .pattern-item code {
            background: #e9ecef;
            padding: 2px 4px;
            border-radius: 2px;
        }
    </style>
</head>
<body>
    <h1>GDPR Pattern Tester</h1>
    <p>Test regex patterns for masking sensitive data in log messages.</p>

    <div class="container">
        <div class="panel">
            <h2>Sample Text</h2>
            <label for="sampleText">Enter text containing sensitive data:</label>
            <textarea id="sampleText">User john.doe@example.com logged in from 192.168.1.100.
Credit card: 4532-1234-5678-9012
SSN: 123-45-6789
Phone: +1 (555) 123-4567</textarea>
        </div>

        <div class="panel">
            <h2>Custom Patterns</h2>
            <label for="patterns">JSON patterns (pattern => replacement):</label>
            <textarea id="patterns">{
    "/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}/": "[EMAIL]",
    "/\\b\\d{3}-\\d{2}-\\d{4}\\b/": "***-**-****",
    "/\\b\\d{4}[\\s-]?\\d{4}[\\s-]?\\d{4}[\\s-]?\\d{4}\\b/": "****-****-****-****"
}</textarea>
            <button onclick="loadDefaults()">Load Default Patterns</button>
        </div>

        <div class="panel full-width">
            <button onclick="testPatterns()">Test Patterns</button>
            <button onclick="testProcessor()">Test Full Processor</button>
        </div>

        <div class="panel full-width">
            <h2>Results</h2>
            <div id="results" class="result">Results will appear here...</div>
        </div>

        <div class="panel">
            <h2>Default Patterns</h2>
            <div id="defaultPatterns" class="patterns-list">Loading...</div>
        </div>

        <div class="panel">
            <h2>Audit Log</h2>
            <div id="auditLog" class="result">Audit log will appear here...</div>
        </div>
    </div>

    <script>
        async function api(action, data = {}) {
            const response = await fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action, ...data })
            });
            return response.json();
        }

        async function testPatterns() {
            const text = document.getElementById('sampleText').value;
            let patterns;
            try {
                patterns = JSON.parse(document.getElementById('patterns').value);
            } catch (e) {
                showResult({ error: 'Invalid JSON in patterns: ' + e.message });
                return;
            }

            const result = await api('test_patterns', { text, patterns });
            showResult(result);
        }

        async function testProcessor() {
            const message = document.getElementById('sampleText').value;
            let patterns;
            try {
                patterns = JSON.parse(document.getElementById('patterns').value);
            } catch (e) {
                patterns = {};
            }

            const result = await api('test_processor', { message, patterns });
            showResult(result);
            if (result.audit_log) {
                document.getElementById('auditLog').textContent =
                    JSON.stringify(result.audit_log, null, 2);
            }
        }

        async function loadDefaults() {
            const result = await api('get_defaults');
            if (result.patterns) {
                document.getElementById('patterns').value =
                    JSON.stringify(result.patterns, null, 4);
            }
        }

        function showResult(result) {
            const el = document.getElementById('results');
            if (result.error || (result.errors && result.errors.length)) {
                el.className = 'result error';
            } else {
                el.className = 'result success';
            }
            el.textContent = JSON.stringify(result, null, 2);
        }

        // Load defaults on page load
        (async function() {
            const result = await api('get_defaults');
            if (result.patterns) {
                const container = document.getElementById('defaultPatterns');
                container.innerHTML = Object.entries(result.patterns)
                    .map(([pattern, replacement]) =>
                        `<div class="pattern-item"><code>${pattern}</code> → <code>${replacement}</code></div>`
                    ).join('');
            }
        })();
    </script>
</body>
</html>
<?php
}
