<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

echo "=== NEONINDEX DIAGNOSTICS ===\n\n";

// PHP Version
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";

// Check VERSION file
$versionFile = __DIR__ . '/VERSION';
if (file_exists($versionFile)) {
    echo "VERSION File Content: " . trim(file_get_contents($versionFile)) . "\n";
} else {
    echo "VERSION File does not exist!\n";
}

// Check MarkdownParser.php file
$parserFile = __DIR__ . '/src/MarkdownParser.php';
if (file_exists($parserFile)) {
    echo "MarkdownParser.php exists.\n";
    $content = file_get_contents($parserFile);
    echo "MarkdownParser.php Length: " . strlen($content) . " bytes\n";
    echo "MarkdownParser.php md5: " . md5($content) . "\n";
    
    // Check if new parser is present
    if (strpos($content, 'NEONINDEX_CODEBLOCK') !== false) {
        echo "MarkdownParser.php version check: NEW implementation (v2.3.2+) is on disk.\n";
    } else {
        echo "MarkdownParser.php version check: OLD implementation is on disk! Overwrite failed!\n";
    }
} else {
    echo "MarkdownParser.php does not exist!\n";
}

// OPcache status
$opcacheEnabled = function_exists('opcache_get_status') && opcache_get_status(false);
echo "OPcache Enabled: " . ($opcacheEnabled ? 'Yes' : 'No') . "\n";
if ($opcacheEnabled) {
    echo "Resetting OPcache...\n";
    $reset = opcache_reset();
    echo "opcache_reset() result: " . ($reset ? 'Success' : 'Failed') . "\n";
}

// Test Markdown Parser
require_once __DIR__ . '/bootstrap.php';
if (class_exists('NeonIndex\Service\MarkdownParser')) {
    $parser = new NeonIndex\Service\MarkdownParser();
    $testMd = "# Hello World\n\nThis is **bold** and `code`.\n\n```php\necho 'test';\n```";
    $html = $parser->parse($testMd);
    echo "\n=== PARSER TEST OUTPUT ===\n";
    echo $html . "\n";
    echo "==========================\n";
} else {
    echo "Class NeonIndex\\Service\\MarkdownParser does not exist!\n";
}
