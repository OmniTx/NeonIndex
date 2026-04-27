<?php

declare(strict_types=1);

/**
 * NeonIndex Bootstrap
 * Autoloader and application initialization
 */

// Define base path - Use __DIR__ directly since bootstrap.php is in root
define('NEONINDEX_ROOT', __DIR__);

// Autoloader - Flat structure (all classes in NeonIndex\Service namespace are in src/ root)
spl_autoload_register(function (string $class): void {
    // Project namespace prefix
    $prefix = 'NeonIndex\\Service\\';
    
    // Base directory for the namespace prefix
    $baseDir = NEONINDEX_ROOT . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR;
    
    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    // Get the relative class name (just the class name, no subdirectories)
    $relativeClass = substr($class, $len);
    
    // Build file path (all classes are directly in src/)
    $file = $baseDir . $relativeClass . '.php';
    
    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Session configuration
$sessionPath = ini_get('session.save_path');
if (empty($sessionPath) || !is_writable($sessionPath)) {
    $localSessionPath = NEONINDEX_ROOT . '/sessions';
    if (!is_dir($localSessionPath)) {
        @mkdir($localSessionPath, 0700, true);
    }
    if (is_writable($localSessionPath)) {
        session_save_path($localSessionPath);
    }
}

// Session garbage collection - cleanup after 48 hours
ini_set('session.gc_maxlifetime', '172800');
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Disable caching for dynamic content
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache');

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
