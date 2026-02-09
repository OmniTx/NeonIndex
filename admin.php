<?php
/**
 * NeonIndex - Admin Panel
 * 
 * Administrative interface for managing files, settings, and user comments.
 * Includes file/folder creation, bulk operations, and configuration management.
 * 
 * @author OmniTx
 * @version 1.0.0
 * @license MIT
 */

// Use local session directory if default path is not writable
$sessionPath = ini_get('session.save_path');
if (empty($sessionPath) || !is_writable($sessionPath)) {
    $localSessionPath = __DIR__ . '/sessions';
    if (!is_dir($localSessionPath)) {
        @mkdir($localSessionPath, 0700, true);
    }
    if (is_writable($localSessionPath)) {
        session_save_path($localSessionPath);
    }
}

// Session garbage collection - cleanup after 48 hours (172800 seconds)
ini_set('session.gc_maxlifetime', 172800);
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);

session_start();

// Disable caching for dynamic content (required for LiteSpeed servers)
header('Cache-Control: no-cache, no-store, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header('X-LiteSpeed-Cache-Control: no-cache');

// =============================================================================
// CONFIGURATION
// =============================================================================

/**
 * Load environment variables from .env file
 */
function loadEnv($path)
{
    if (!file_exists($path))
        return [];
    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0)
            continue;
        if (strpos($line, '=') === false)
            continue;
        list($key, $value) = explode('=', $line, 2);
        $env[trim($key)] = trim($value);
    }
    return $env;
}

/**
 * Save environment variables to .env file
 */
function saveEnv($path, $env)
{
    $content = "# NeonIndex Configuration\n\n";
    foreach ($env as $key => $value) {
        $content .= "{$key}={$value}\n";
    }
    return file_put_contents($path, $content) !== false;
}

$envPath = __DIR__ . '/.env';
$config = loadEnv($envPath);

// Default configuration values
$config['ADMIN_PASSWORD'] = $config['ADMIN_PASSWORD'] ?? 'admin123';
$config['HIDDEN_FILES'] = $config['HIDDEN_FILES'] ?? '.env,admin.php,.htaccess,.git,comments.json,rate_limits.json,downloads.log';
$config['SITE_TITLE'] = $config['SITE_TITLE'] ?? 'NeonIndex';
$config['DEFAULT_THEME'] = $config['DEFAULT_THEME'] ?? 'dark';
$config['README_POSITION'] = $config['README_POSITION'] ?? 'bottom';
$config['SHOW_DOWNLOAD'] = $config['SHOW_DOWNLOAD'] ?? 'true';
$config['SHOW_RENAME'] = $config['SHOW_RENAME'] ?? 'true';
$config['SHOW_DELETE'] = $config['SHOW_DELETE'] ?? 'true';
$config['SHOW_UPLOAD'] = $config['SHOW_UPLOAD'] ?? 'true';
$config['SHOW_THEME_TOGGLE'] = $config['SHOW_THEME_TOGGLE'] ?? 'true';
$config['SHOW_COMMENTS'] = $config['SHOW_COMMENTS'] ?? 'true';

// Rate limiting & upload settings
$config['MAX_UPLOAD_SIZE'] = $config['MAX_UPLOAD_SIZE'] ?? '10';
$config['CHUNK_SIZE_MB'] = $config['CHUNK_SIZE_MB'] ?? '8';
$config['RATE_LIMIT_UPLOADS'] = $config['RATE_LIMIT_UPLOADS'] ?? '20';
$config['RATE_LIMIT_COMMENTS'] = $config['RATE_LIMIT_COMMENTS'] ?? '10';
$config['ENABLE_DOWNLOAD_LOG'] = $config['ENABLE_DOWNLOAD_LOG'] ?? 'false';

define('BASE_DIR', realpath(__DIR__ . '/uploads') ?: __DIR__ . '/uploads');
define('HIDDEN_FILES', array_map('trim', explode(',', $config['HIDDEN_FILES'])));
define('COMMENTS_FILE', __DIR__ . '/comments.json');
define('RATE_LIMIT_FILE', __DIR__ . '/rate_limits.json');
define('DOWNLOAD_LOG_FILE', __DIR__ . '/downloads.log');

// Ensure uploads directory exists
if (!is_dir(BASE_DIR)) {
    @mkdir(BASE_DIR, 0755, true);
}

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =============================================================================
// HELPER FUNCTIONS
// =============================================================================

/**
 * Check if user is authenticated as admin
 */
function isAuthenticated(): bool
{
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

/**
 * Sanitize and validate file path to prevent directory traversal
 */
function sanitizePath(string $path): ?string
{
    $path = urldecode($path);
    $realPath = realpath(BASE_DIR . DIRECTORY_SEPARATOR . $path);
    if ($realPath === false || strpos($realPath, BASE_DIR) !== 0)
        return null;
    return $realPath;
}

/**
 * URL encode path segments but keep slashes
 */
function safeUrlEncode(string $path): string
{
    $path = str_replace('\\', '/', $path);
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

/**
 * Format bytes to human-readable size
 */
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    return round($bytes / pow(1024, min($pow, 4)), 2) . ' ' . $units[min($pow, 4)];
}

/**
 * Check if file should be hidden from listing
 */
function isHiddenFile(string $filename): bool
{
    return in_array($filename, HIDDEN_FILES) || in_array(strtolower($filename), HIDDEN_FILES);
}

/**
 * Verify CSRF token
 */
function verifyCSRF(): bool
{
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Get all comments from JSON file
 */
function getComments(): array
{
    if (!file_exists(COMMENTS_FILE))
        return [];
    $data = json_decode(file_get_contents(COMMENTS_FILE), true);
    return is_array($data) ? $data : [];
}

/**
 * Save comments to JSON file
 */
function saveComments(array $comments): bool
{
    return file_put_contents(COMMENTS_FILE, json_encode($comments, JSON_PRETTY_PRINT)) !== false;
}

/**
 * Recursively get all files in directory
 */
function getAllFiles($dir, $base = '')
{
    $files = [];
    $items = @scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..')
            continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        $relative = $base ? $base . '/' . $item : $item;
        $files[] = [
            'path' => $relative,
            'name' => $item,
            'isDir' => is_dir($path),
            'size' => is_file($path) ? filesize($path) : 0
        ];
        if (is_dir($path)) {
            $files = array_merge($files, getAllFiles($path, $relative));
        }
    }
    return $files;
}

/**
 * Get all directories for dropdown
 */
function getDirectories($dir, $base = '')
{
    $dirs = ['' => '/ (Root)'];
    $items = @scandir($dir) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item[0] === '.')
            continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        $relative = $base ? $base . '/' . $item : $item;
        if (is_dir($path)) {
            $dirs[$relative] = '/' . $relative;
            $dirs = array_merge($dirs, getDirectories($path, $relative));
        }
    }
    return $dirs;
}

// =============================================================================
// REQUEST HANDLERS
// =============================================================================

$message = '';
$messageType = '';

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (($_POST['password'] ?? '') === $config['ADMIN_PASSWORD']) {
        $_SESSION['authenticated'] = true;
        header('Location: admin.php');
        exit;
    } else {
        $message = 'Invalid password!';
        $messageType = 'danger';
    }
}

// Handle Logout
if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION['authenticated'] = false;
    header('Location: index.php');
    exit;
}

// Handle Settings Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings' && isAuthenticated()) {
    $config['ADMIN_PASSWORD'] = $_POST['admin_password'] ?? $config['ADMIN_PASSWORD'];
    $config['HIDDEN_FILES'] = $_POST['hidden_files'] ?? $config['HIDDEN_FILES'];
    $config['SITE_TITLE'] = $_POST['site_title'] ?? $config['SITE_TITLE'];
    $config['DEFAULT_THEME'] = $_POST['default_theme'] ?? 'dark';
    $config['README_POSITION'] = $_POST['readme_position'] ?? 'bottom';
    $config['SHOW_DOWNLOAD'] = isset($_POST['show_download']) ? 'true' : 'false';
    $config['SHOW_RENAME'] = isset($_POST['show_rename']) ? 'true' : 'false';
    $config['SHOW_DELETE'] = isset($_POST['show_delete']) ? 'true' : 'false';
    $config['SHOW_UPLOAD'] = isset($_POST['show_upload']) ? 'true' : 'false';
    $config['SHOW_THEME_TOGGLE'] = isset($_POST['show_theme_toggle']) ? 'true' : 'false';
    $config['SHOW_COMMENTS'] = isset($_POST['show_comments']) ? 'true' : 'false';

    // Rate limiting & upload settings
    $config['MAX_UPLOAD_SIZE'] = $_POST['max_upload_size'] ?? $config['MAX_UPLOAD_SIZE'];
    $config['CHUNK_SIZE_MB'] = $_POST['chunk_size_mb'] ?? $config['CHUNK_SIZE_MB'];
    $config['RATE_LIMIT_UPLOADS'] = $_POST['rate_limit_uploads'] ?? $config['RATE_LIMIT_UPLOADS'];
    $config['RATE_LIMIT_COMMENTS'] = $_POST['rate_limit_comments'] ?? $config['RATE_LIMIT_COMMENTS'];
    $config['ENABLE_DOWNLOAD_LOG'] = isset($_POST['enable_download_log']) ? 'true' : 'false';

    if (saveEnv($envPath, $config)) {
        $message = 'Settings saved!';
        $messageType = 'success';
    } else {
        $message = 'Failed to save settings!';
        $messageType = 'danger';
    }
}

// Handle File Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_file' && isAuthenticated()) {
    $fileName = basename($_POST['file_name'] ?? '');
    $fileContent = $_POST['file_content'] ?? '';
    $targetDir = $_POST['target_dir'] ?? '';

    if ($fileName) {
        $targetPath = $targetDir ? BASE_DIR . DIRECTORY_SEPARATOR . $targetDir : BASE_DIR;
        $filePath = $targetPath . DIRECTORY_SEPARATOR . $fileName;

        if (!file_exists($filePath) && file_put_contents($filePath, $fileContent) !== false) {
            $message = "File '{$fileName}' created!";
            $messageType = 'success';
        } else {
            $message = 'Failed to create file (may already exist)!';
            $messageType = 'danger';
        }
    }
}

// Handle Directory Create
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_dir' && isAuthenticated()) {
    $dirName = basename($_POST['dir_name'] ?? '');
    $targetDir = $_POST['target_dir'] ?? '';

    if ($dirName) {
        $targetPath = $targetDir ? BASE_DIR . DIRECTORY_SEPARATOR . $targetDir : BASE_DIR;
        $dirPath = $targetPath . DIRECTORY_SEPARATOR . $dirName;

        if (!file_exists($dirPath) && @mkdir($dirPath, 0755, true)) {
            $message = "Folder '{$dirName}' created!";
            $messageType = 'success';
        } else {
            $message = 'Failed to create folder (may already exist)!';
            $messageType = 'danger';
        }
    }
}

// Handle Delete Comment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_comment' && isAuthenticated()) {
    $comments = getComments();
    $index = (int) ($_POST['comment_index'] ?? -1);
    if (isset($comments[$index])) {
        array_splice($comments, $index, 1);
        if (file_put_contents(COMMENTS_FILE, json_encode($comments, JSON_PRETTY_PRINT))) {
            $message = 'Comment deleted!';
            $messageType = 'success';
        }
    }
}

// Handle Clear Logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_logs' && isAuthenticated()) {
    $logFile = __DIR__ . '/downloads.log';
    if (file_exists($logFile)) {
        if (unlink($logFile)) {
            $message = 'Download logs cleared!';
            $messageType = 'success';
        } else {
            $message = 'Failed to clear logs!';
            $messageType = 'danger';
        }
    } else {
        $message = 'No logs to clear!';
        $messageType = 'info';
    }
}

// Handle Clear All Comments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_comments' && isAuthenticated()) {
    if (saveComments([])) {
        $message = 'All comments cleared!';
        $messageType = 'success';
    }
}

// Handle Single Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isAuthenticated()) {
    if (verifyCSRF() && isset($_POST['file'])) {
        $filePath = sanitizePath($_POST['file']);
        if ($filePath && $filePath !== BASE_DIR) {
            if (is_dir($filePath) ? @rmdir($filePath) : @unlink($filePath)) {
                $message = 'Deleted!';
                $messageType = 'success';
            } else {
                $message = 'Failed to delete (folder may not be empty)!';
                $messageType = 'danger';
            }
        }
    }
}

// Handle Rename
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename' && isAuthenticated()) {
    if (verifyCSRF() && isset($_POST['file'], $_POST['newname'])) {
        $filePath = sanitizePath($_POST['file']);
        $newName = basename($_POST['newname']);
        if ($filePath && $filePath !== BASE_DIR && $newName) {
            $newPath = dirname($filePath) . DIRECTORY_SEPARATOR . $newName;
            if (!file_exists($newPath) && @rename($filePath, $newPath)) {
                $message = 'Renamed!';
                $messageType = 'success';
            }
        }
    }
}

// Handle Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload' && isAuthenticated()) {
    if (verifyCSRF() && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $currentDir = isset($_POST['dir']) ? sanitizePath($_POST['dir']) : BASE_DIR;
        if (!$currentDir)
            $currentDir = BASE_DIR;
        $filename = basename($_FILES['file']['name']);
        if (move_uploaded_file($_FILES['file']['tmp_name'], $currentDir . DIRECTORY_SEPARATOR . $filename)) {
            $message = 'Uploaded!';
            $messageType = 'success';
        }
    }
}

// Handle Bulk Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete' && isAuthenticated()) {
    $files = $_POST['files'] ?? [];
    $deleted = 0;
    foreach ($files as $file) {
        $path = realpath(BASE_DIR . DIRECTORY_SEPARATOR . $file);
        if ($path && strpos($path, BASE_DIR) === 0 && $path !== BASE_DIR) {
            if (is_dir($path) ? @rmdir($path) : @unlink($path))
                $deleted++;
        }
    }
    $message = "Deleted {$deleted} item(s)!";
    $messageType = 'success';
}

// Handle Bulk Move
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_move' && isAuthenticated()) {
    $files = $_POST['files'] ?? [];
    $targetDir = $_POST['target_dir'] ?? '';
    $targetPath = $targetDir === '' ? BASE_DIR : realpath(BASE_DIR . DIRECTORY_SEPARATOR . $targetDir);

    if ($targetPath && is_dir($targetPath) && strpos($targetPath, BASE_DIR) === 0) {
        $moved = 0;
        foreach ($files as $file) {
            $sourcePath = realpath(BASE_DIR . DIRECTORY_SEPARATOR . $file);
            if ($sourcePath && strpos($sourcePath, BASE_DIR) === 0) {
                $newPath = $targetPath . DIRECTORY_SEPARATOR . basename($sourcePath);
                if (@rename($sourcePath, $newPath))
                    $moved++;
            }
        }
        $message = "Moved {$moved} item(s)!";
        $messageType = 'success';
    }
}

// =============================================================================
// DIRECTORY LISTING
// =============================================================================

$requestedPath = $_GET['dir'] ?? '';
$currentDir = sanitizePath($requestedPath) ?: BASE_DIR;

// Get items for current directory
$items = [];
if (is_dir($currentDir)) {
    foreach (scandir($currentDir) as $file) {
        if ($file === '.' || ($file === '..' && $currentDir === BASE_DIR))
            continue;
        $fullPath = $currentDir . DIRECTORY_SEPARATOR . $file;
        $items[] = [
            'name' => $file,
            'path' => str_replace(BASE_DIR . DIRECTORY_SEPARATOR, '', $fullPath),
            'isDir' => is_dir($fullPath),
            'size' => is_file($fullPath) ? filesize($fullPath) : 0,
            'modified' => filemtime($fullPath),
            'hidden' => isHiddenFile($file)
        ];
    }
    usort($items, fn($a, $b) => $b['isDir'] <=> $a['isDir'] ?: strcasecmp($a['name'], $b['name']));
}

// Breadcrumb navigation
$relativePath = trim(str_replace(BASE_DIR, '', $currentDir), DIRECTORY_SEPARATOR);
$breadcrumbs = [['name' => 'Root', 'path' => '']];
if ($relativePath) {
    $parts = explode(DIRECTORY_SEPARATOR, $relativePath);
    $acc = '';
    foreach ($parts as $part) {
        $acc .= ($acc ? '/' : '') . $part;
        $breadcrumbs[] = ['name' => $part, 'path' => $acc];
    }
}
$relativeCurrentDir = $relativePath;

// Get data for authenticated users
$allFiles = isAuthenticated() ? getAllFiles(BASE_DIR) : [];
$directories = isAuthenticated() ? getDirectories(BASE_DIR) : [];
$comments = isAuthenticated() ? getComments() : [];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $config['DEFAULT_THEME'] ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Admin - <?= htmlspecialchars($config['SITE_TITLE']) ?></title>

    <!-- SEO Meta Tags -->
    <meta name="description" content="Admin Panel - <?= htmlspecialchars($config['SITE_TITLE']) ?>">
    <meta name="robots" content="noindex, nofollow">
    <meta name="author" content="OmniTx">

    <!-- Theme Color -->
    <meta name="theme-color" content="#00FFC8">
    <meta name="msapplication-TileColor" content="#0B1215">

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="favicon.svg?v=2">

    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --neon-cyan: #00FFC8;
            --neon-glow: rgba(0, 255, 200, 0.3);
        }

        /* Dark Theme */
        [data-bs-theme="dark"] {
            --bs-body-bg: #0B1215;
            --bs-card-bg: rgba(16, 26, 30, 0.8);
            --bs-border-color: rgba(0, 255, 200, 0.2);
        }

        [data-bs-theme="dark"] .navbar {
            background: rgba(11, 18, 21, 0.85) !important;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--neon-glow);
        }

        [data-bs-theme="dark"] .card {
            background: var(--bs-card-bg);
            border: 1px solid var(--neon-glow);
            box-shadow: 0 0 30px rgba(0, 255, 200, 0.1);
        }

        [data-bs-theme="dark"] .glow {
            text-shadow: 0 0 20px var(--neon-glow);
            color: var(--neon-cyan) !important;
        }

        [data-bs-theme="dark"] .nav-pills .nav-link.active {
            background: var(--neon-cyan);
            color: #0B1215;
        }

        [data-bs-theme="dark"] .table-hover tbody tr:hover {
            background: rgba(0, 255, 200, 0.05);
        }

        /* Light Theme */
        [data-bs-theme="light"] .glow {
            color: #0d6efd !important;
        }

        [data-bs-theme="light"] .navbar {
            background: rgba(255, 255, 255, 0.85) !important;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-bottom: 1px solid #dee2e6;
        }

        [data-bs-theme="light"] .card {
            background: #ffffff;
            border: 1px solid #dee2e6;
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg sticky-top shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="admin.php">
                <i class="bi bi-gear-fill text-info fs-4"></i>
                <span class="fw-bold glow">Admin Panel</span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <?php if ($config['SHOW_THEME_TOGGLE'] === 'true'): ?>
                    <button class="btn btn-sm btn-outline-secondary" onclick="toggleTheme()" title="Toggle Theme">
                        <i class="bi bi-circle-half"></i>
                    </button>
                <?php endif; ?>
                <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
                <?php if (isAuthenticated()): ?>
                    <a href="?action=logout" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Alert Messages -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Login Form (Not Authenticated) -->
        <?php if (!isAuthenticated()): ?>
            <div class="row justify-content-center mt-5">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header text-center">
                            <i class="bi bi-lock me-2"></i>Admin Login
                        </div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="login">
                                <input type="password" name="password" class="form-control mb-3" placeholder="Password"
                                    required autofocus>
                                <button type="submit" class="btn btn-info w-100">Login</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Admin Panel (Authenticated) -->
        <?php else: ?>
            <!-- Tab Navigation -->
            <ul class="nav nav-pills mb-4 flex-wrap" role="tablist">
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="pill" href="#settings">
                        <i class="bi bi-gear me-1"></i>Settings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link active" data-bs-toggle="pill" href="#files">
                        <i class="bi bi-folder me-1"></i>Files
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="pill" href="#bulk">
                        <i class="bi bi-box me-1"></i>Bulk
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="pill" href="#creator">
                        <i class="bi bi-plus-circle me-1"></i>Create
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="pill" href="#comments">
                        <i class="bi bi-chat-dots me-1"></i>Comments
                        <span class="badge bg-info"><?= count($comments) ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="pill" href="#logs">
                        <i class="bi bi-journal-text me-1"></i>Logs
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-bs-toggle="pill" href="#server">
                        <i class="bi bi-hdd-network me-1"></i>Server Info
                    </a>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Settings Tab -->
                <div class="tab-pane fade" id="settings">
                    <div class="card">
                        <div class="card-header"><i class="bi bi-gear me-2"></i>Settings</div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="save_settings">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Site Title</label>
                                            <input type="text" name="site_title" class="form-control"
                                                value="<?= htmlspecialchars($config['SITE_TITLE']) ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Admin Password</label>
                                            <input type="text" name="admin_password" class="form-control"
                                                value="<?= htmlspecialchars($config['ADMIN_PASSWORD']) ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Hidden Files</label>
                                            <input type="text" name="hidden_files" class="form-control"
                                                value="<?= htmlspecialchars($config['HIDDEN_FILES']) ?>">
                                            <small class="text-muted">Comma-separated list</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Default Theme</label>
                                            <select name="default_theme" class="form-select">
                                                <option value="dark" <?= $config['DEFAULT_THEME'] === 'dark' ? 'selected' : '' ?>>Dark (Neon Cyan)</option>
                                                <option value="light" <?= $config['DEFAULT_THEME'] === 'light' ? 'selected' : '' ?>>Light</option>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">README Position</label>
                                            <select name="readme_position" class="form-select">
                                                <option value="top" <?= $config['README_POSITION'] === 'top' ? 'selected' : '' ?>>Top</option>
                                                <option value="bottom" <?= $config['README_POSITION'] === 'bottom' ? 'selected' : '' ?>>Bottom</option>
                                            </select>
                                        </div>
                                        
                                        <hr class="my-4">
                                        <h6 class="text-info mb-3">Limits & Logging</h6>
                                        
                                        <div class="mb-3">
                                            <label class="form-label">Max Upload Size (MB)</label>
                                            <input type="number" name="max_upload_size" class="form-control" 
                                                   value="<?= htmlspecialchars($config['MAX_UPLOAD_SIZE']) ?>" min="0">
                                            <small class="text-muted">Set to 0 for unlimited</small>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Chunk Size (MB)</label>
                                            <input type="number" name="chunk_size_mb" class="form-control" 
                                                   value="<?= htmlspecialchars($config['CHUNK_SIZE_MB']) ?>" min="1" max="50">
                                            <small class="text-muted">Size of chunks for large file uploads (1-50 MB)</small>
                                        </div>
                                        <div class="row">
                                            <div class="col-6 mb-3">
                                                <label class="form-label">Upload Limit/Hr</label>
                                                <input type="number" name="rate_limit_uploads" class="form-control" 
                                                       value="<?= htmlspecialchars($config['RATE_LIMIT_UPLOADS']) ?>" min="0">
                                            </div>
                                            <div class="col-6 mb-3">
                                                <label class="form-label">Comment Limit/Hr</label>
                                                <input type="number" name="rate_limit_comments" class="form-control" 
                                                       value="<?= htmlspecialchars($config['RATE_LIMIT_COMMENTS']) ?>" min="0">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Visible Features</label>
                                        <div class="bg-body-secondary rounded p-3">
                                            <div class="form-check">
                                                <input type="checkbox" name="show_download" class="form-check-input"
                                                    <?= $config['SHOW_DOWNLOAD'] === 'true' ? 'checked' : '' ?>>
                                                <label class="form-check-label">Download Button</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" name="show_rename" class="form-check-input"
                                                    <?= $config['SHOW_RENAME'] === 'true' ? 'checked' : '' ?>>
                                                <label class="form-check-label">Rename Button</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" name="show_delete" class="form-check-input"
                                                    <?= $config['SHOW_DELETE'] === 'true' ? 'checked' : '' ?>>
                                                <label class="form-check-label">Delete Button</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" name="show_upload" class="form-check-input"
                                                    <?= $config['SHOW_UPLOAD'] === 'true' ? 'checked' : '' ?>>
                                                <label class="form-check-label">Upload Form</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" name="show_theme_toggle" class="form-check-input"
                                                    <?= $config['SHOW_THEME_TOGGLE'] === 'true' ? 'checked' : '' ?>>
                                                <label class="form-check-label">Theme Toggle</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="checkbox" name="show_comments" class="form-check-input"
                                                    <?= $config['SHOW_COMMENTS'] === 'true' ? 'checked' : '' ?>>
                                                <label class="form-check-label">Comments Form</label>
                                            </div>
                                            <hr class="my-2">
                                            <div class="form-check">
                                                <input type="checkbox" name="enable_download_log" class="form-check-input"
                                                    <?= $config['ENABLE_DOWNLOAD_LOG'] === 'true' ? 'checked' : '' ?>>
                                                <label class="form-check-label">Enable Download Log</label>
                                            </div>
                                        </div>
                                        <button type="submit" class="btn btn-info mt-3 w-100">
                                            <i class="bi bi-save me-1"></i>Save Settings
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- File Manager Tab -->
                <div class="tab-pane fade show active" id="files">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div>
                                <i class="bi bi-folder me-2"></i>File Manager
                                <nav class="d-inline-block ms-3 small">
                                    <?php foreach ($breadcrumbs as $i => $crumb): ?>
                                        <?php if ($i > 0): ?> / <?php endif; ?>
                                        <a href="?dir=<?= safeUrlEncode($crumb['path']) ?>" class="text-decoration-none">
                                            <?= htmlspecialchars($crumb['name']) ?>
                                        </a>
                                    <?php endforeach; ?>
                                </nav>
                            </div>
                            <form method="POST" enctype="multipart/form-data" class="d-flex gap-2">
                                <input type="hidden" name="action" value="upload">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="dir" value="<?= htmlspecialchars($relativeCurrentDir) ?>">
                                <input type="file" name="file" required class="form-control form-control-sm"
                                    style="max-width: 180px;">
                                <button type="submit" class="btn btn-sm btn-info"><i class="bi bi-upload"></i></button>
                            </form>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Size</th>
                                        <th>Modified</th>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $item): ?>
                                        <tr>
                                            <td>
                                                <?php if ($item['isDir']): ?>
                                                    <i class="bi bi-folder-fill text-warning me-2"></i>
                                                    <a href="?dir=<?= safeUrlEncode($item['path']) ?>" class="text-decoration-none">
                                                        <?= htmlspecialchars($item['name']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <i class="bi bi-file-earmark text-secondary me-2"></i>
                                                    <?= htmlspecialchars($item['name']) ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-muted"><?= $item['isDir'] ? '—' : formatBytes($item['size']) ?></td>
                                            <td class="text-muted"><?= date('M d, H:i', $item['modified']) ?></td>
                                            <td>
                                                <?= $item['hidden'] ? '<span class="badge bg-danger">Hidden</span>' : '<span class="badge bg-success">Visible</span>' ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if (!$item['isDir'] && $item['name'] !== '..'): ?>
                                                    <a href="uploads/<?= htmlspecialchars($item['path']) ?>" download
                                                        class="btn btn-sm btn-outline-secondary">
                                                        <i class="bi bi-download"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($item['name'] !== '..'): ?>
                                                    <button class="btn btn-sm btn-outline-secondary"
                                                        onclick="showRename('<?= htmlspecialchars(addslashes($item['path'])) ?>', '<?= htmlspecialchars(addslashes($item['name'])) ?>')">
                                                        <i class="bi bi-pencil"></i>
                                                    </button>
                                                    <button class="btn btn-sm btn-outline-danger"
                                                        onclick="showDelete('<?= htmlspecialchars(addslashes($item['path'])) ?>', '<?= htmlspecialchars(addslashes($item['name'])) ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Bulk Operations Tab -->
                <div class="tab-pane fade" id="bulk">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-box me-2"></i>Bulk Operations</span>
                            <div>
                                <button class="btn btn-sm btn-outline-secondary"
                                    onclick="document.querySelectorAll('.bulk-cb').forEach(c=>c.checked=true)">Select
                                    All</button>
                                <button class="btn btn-sm btn-outline-secondary"
                                    onclick="document.querySelectorAll('.bulk-cb').forEach(c=>c.checked=false)">Deselect</button>
                            </div>
                        </div>
                        <div class="card-body border-bottom d-flex gap-2 flex-wrap align-items-center">
                            <span class="text-muted">With selected:</span>
                            <button class="btn btn-sm btn-outline-danger" onclick="bulkDelete()">
                                <i class="bi bi-trash me-1"></i>Delete
                            </button>
                            <select id="moveTarget" class="form-select form-select-sm" style="max-width: 150px;">
                                <?php foreach ($directories as $path => $label): ?>
                                    <option value="<?= htmlspecialchars($path) ?>">
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-info" onclick="bulkMove()">
                                <i class="bi bi-box-arrow-right me-1"></i>Move
                            </button>
                        </div>
                        <div style="max-height: 400px; overflow-y: auto;">
                            <form id="bulkForm" method="POST">
                                <input type="hidden" name="action" id="bulkAction" value="">
                                <input type="hidden" name="target_dir" id="targetDir" value="">
                                <table class="table table-hover mb-0">
                                    <thead class="sticky-top bg-body">
                                        <tr>
                                            <th width="30"></th>
                                            <th>Path</th>
                                            <th>Type</th>
                                            <th class="text-end">Size</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($allFiles as $file): ?>
                                            <tr>
                                                <td>
                                                    <input type="checkbox" name="files[]"
                                                        value="<?= htmlspecialchars($file['path']) ?>"
                                                        class="form-check-input bulk-cb">
                                                </td>
                                                <td class="<?= $file['isDir'] ? 'text-warning' : '' ?>">
                                                    <?= htmlspecialchars($file['path']) ?>
                                                </td>
                                                <td>
                                                    <?= $file['isDir'] ? '<i class="bi bi-folder"></i>' : '<i class="bi bi-file-earmark"></i>' ?>
                                                </td>
                                                <td class="text-end text-muted">
                                                    <?= $file['isDir'] ? '—' : formatBytes($file['size']) ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </form>
                        </div>
                        <div class="card-footer text-muted small">
                            Total: <?= count($allFiles) ?> items
                        </div>
                    </div>
                </div>

                <!-- Create File/Folder Tab -->
                <div class="tab-pane fade" id="creator">
                    <div class="row">
                        <!-- Create File -->
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header"><i class="bi bi-file-plus me-2"></i>Create File</div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="create_file">
                                        <div class="mb-3">
                                            <label class="form-label">File Name</label>
                                            <input type="text" name="file_name" class="form-control"
                                                placeholder="example.txt" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Target Directory</label>
                                            <select name="target_dir" class="form-select">
                                                <?php foreach ($directories as $path => $label): ?>
                                                    <option value="<?= htmlspecialchars($path) ?>">
                                                        <?= htmlspecialchars($label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Content</label>
                                            <textarea name="file_content" class="form-control" rows="5"
                                                placeholder="File content..."></textarea>
                                        </div>
                                        <button type="submit" class="btn btn-info w-100">
                                            <i class="bi bi-file-plus me-1"></i>Create File
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <!-- Create Folder -->
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header"><i class="bi bi-folder-plus me-2"></i>Create Folder</div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="create_dir">
                                        <div class="mb-3">
                                            <label class="form-label">Folder Name</label>
                                            <input type="text" name="dir_name" class="form-control" placeholder="new-folder"
                                                required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Target Directory</label>
                                            <select name="target_dir" class="form-select">
                                                <?php foreach ($directories as $path => $label): ?>
                                                    <option value="<?= htmlspecialchars($path) ?>">
                                                        <?= htmlspecialchars($label) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn btn-info w-100">
                                            <i class="bi bi-folder-plus me-1"></i>Create Folder
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Comments Tab -->
                <div class="tab-pane fade" id="comments">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-chat-dots me-2"></i>User Comments (<?= count($comments) ?>)</span>
                            <?php if (!empty($comments)): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Clear ALL comments?')">
                                    <input type="hidden" name="action" value="clear_comments">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash me-1"></i>Clear All
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <?php if (empty($comments)): ?>
                            <div class="card-body text-center text-muted py-5">
                                <i class="bi bi-chat-square-text display-1"></i>
                                <p class="mt-3">No comments yet</p>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush" style="max-height: 500px; overflow-y: auto;">
                                <?php foreach ($comments as $i => $comment): ?>
                                    <div class="list-group-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <strong><?= htmlspecialchars($comment['name'] ?? 'Anonymous') ?></strong>
                                                <small
                                                    class="text-muted ms-2"><?= htmlspecialchars($comment['email'] ?? '') ?></small>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <small
                                                    class="text-muted"><?= date('M d, Y H:i', strtotime($comment['date'] ?? 'now')) ?></small>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="action" value="delete_comment">
                                                    <input type="hidden" name="comment_index" value="<?= $i ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i
                                                            class="bi bi-x"></i></button>
                                                </form>
                                            </div>
                                        </div>
                                        <p class="mb-0 mt-2"><?= nl2br(htmlspecialchars($comment['message'] ?? '')) ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                </div>

                <!-- Logs Tab -->
                <div class="tab-pane fade" id="logs">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div><i class="bi bi-journal-text me-2"></i>Download Logs</div>
                            <form method="POST" onsubmit="return confirm('Clear all logs?');">
                                <input type="hidden" name="action" value="clear_logs">
                                <button type="submit" class="btn btn-sm btn-danger">
                                    <i class="bi bi-trash me-1"></i>Clear Logs
                                </button>
                            </form>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>IP Address</th>
                                            <th>File</th>
                                            <th>User Agent</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $logFile = __DIR__ . '/downloads.log';
                                        if (file_exists($logFile)) {
                                            $logs = array_reverse(file($logFile));
                                            if (empty($logs)) {
                                                echo "<tr><td colspan='4' class='text-center py-3 text-muted'>No logs found</td></tr>";
                                            }
                                            foreach ($logs as $log) {
                                                if (trim($log) && preg_match('/^\[(.*?)\] IP: (.*?) \| File: (.*?) \| UA: (.*)$/', $log, $matches)) {
                                                    echo "<tr>";
                                                    echo "<td class='text-nowrap'>" . htmlspecialchars($matches[1]) . "</td>";
                                                    echo "<td>" . htmlspecialchars($matches[2]) . "</td>";
                                                    echo "<td>" . htmlspecialchars($matches[3]) . "</td>";
                                                    echo "<td class='small text-muted'>" . htmlspecialchars($matches[4]) . "</td>";
                                                    echo "</tr>";
                                                }
                                            }
                                        } else {
                                            echo "<tr><td colspan='4' class='text-center py-3 text-muted'>No logs found</td></tr>";
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Server Info Tab -->
                <div class="tab-pane fade" id="server">
                    <div class="row">
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header"><i class="bi bi-hdd-network me-2"></i>Environment</div>
                                <div class="list-group list-group-flush">
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>PHP Version</span>
                                        <span class="badge bg-secondary"><?= phpversion() ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Server Software</span>
                                        <small class="text-muted"><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') ?></small>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Server IP</span>
                                        <span class="badge bg-secondary"><?= $_SERVER['SERVER_ADDR'] ?? 'Unknown' ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Client IP</span>
                                        <span class="badge bg-secondary"><?= $_SERVER['REMOTE_ADDR'] ?? 'Unknown' ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6 mb-4">
                            <div class="card h-100">
                                <div class="card-header"><i class="bi bi-gear-wide-connected me-2"></i>Configuration</div>
                                <div class="list-group list-group-flush">
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Upload Max Filesize</span>
                                        <span class="badge bg-info"><?= ini_get('upload_max_filesize') ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Post Max Size</span>
                                        <span class="badge bg-info"><?= ini_get('post_max_size') ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Memory Limit</span>
                                        <span class="badge bg-info"><?= ini_get('memory_limit') ?></span>
                                    </div>
                                    <div class="list-group-item d-flex justify-content-between align-items-center">
                                        <span>Max Execution Time</span>
                                        <span class="badge bg-info"><?= ini_get('max_execution_time') ?>s</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>Requirement Checks</div>
                                <div class="table-responsive">
                                    <table class="table mb-0">
                                        <thead>
                                            <tr>
                                                <th>Check</th>
                                                <th>Status</th>
                                                <th>Message</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <!-- PHP Version Check -->
                                            <?php $phpOk = version_compare(PHP_VERSION, '7.4.0', '>='); ?>
                                            <tr>
                                                <td>PHP Version >= 7.4</td>
                                                <td><?= $phpOk ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?></td>
                                                <td class="small text-muted"><?= $phpOk ? 'Current: ' . PHP_VERSION : 'Update PHP to 7.4+' ?></td>
                                            </tr>
                                            
                                            <!-- Extensions Check -->
                                            <?php 
                                            $exts = ['json', 'mbstring', 'fileinfo', 'gd'];
                                            foreach ($exts as $ext): 
                                                $loaded = extension_loaded($ext);
                                            ?>
                                            <tr>
                                                <td><?= $ext ?> Extension</td>
                                                <td><?= $loaded ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?></td>
                                                <td class="small text-muted"><?= $loaded ? 'Installed' : 'Install ' . $ext . ' extension' ?></td>
                                            </tr>
                                            <?php endforeach; ?>

                                            <!-- Writable Checks -->
                                            <?php 
                                            $paths = [
                                                'Uploads Dir' => BASE_DIR,
                                                'Config File' => __DIR__ . '/.env',
                                                'Comments Data' => COMMENTS_FILE,
                                                'Rate Limits' => RATE_LIMIT_FILE ?? __DIR__ . '/rate_limits.json',
                                                'Downloads Log' => DOWNLOAD_LOG_FILE ?? __DIR__ . '/downloads.log'
                                            ];
                                            foreach ($paths as $name => $path):
                                                $exists = file_exists($path);
                                                $writable = is_writable($exists ? $path : dirname($path));
                                            ?>
                                            <tr>
                                                <td><?= $name ?> Writable</td>
                                                <td><?= $writable ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?></td>
                                                <td class="small text-muted"><?= $writable ? 'Writable' : 'Check permissions' ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Rename Modal -->
    <div class="modal fade" id="renameModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Rename</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="rename">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="file" id="renameFile">
                        <input type="text" name="newname" id="renameName" class="form-control" required>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info">Rename</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="file" id="deleteFile">
                        <p>Are you sure you want to delete <strong id="deleteFileName"></strong>?</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Theme Toggle
        function toggleTheme() {
            const html = document.documentElement;
            const next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-bs-theme', next);
            localStorage.setItem('neonindex_theme', next);
        }

        // Modal Helpers
        function showRename(path, name) {
            document.getElementById('renameFile').value = path;
            document.getElementById('renameName').value = name;
            new bootstrap.Modal(document.getElementById('renameModal')).show();
        }

        function showDelete(path, name) {
            document.getElementById('deleteFile').value = path;
            document.getElementById('deleteFileName').textContent = name;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        // Bulk Operations
        function bulkDelete() {
            const count = document.querySelectorAll('.bulk-cb:checked').length;
            if (!count) return alert('Select files first');
            if (!confirm(`Delete ${count} item(s)?`)) return;
            document.getElementById('bulkAction').value = 'bulk_delete';
            document.getElementById('bulkForm').submit();
        }

        function bulkMove() {
            const count = document.querySelectorAll('.bulk-cb:checked').length;
            if (!count) return alert('Select files first');
            document.getElementById('bulkAction').value = 'bulk_move';
            document.getElementById('targetDir').value = document.getElementById('moveTarget').value;
            document.getElementById('bulkForm').submit();
        }

        // Load saved theme on page load
        (function () {
            const saved = localStorage.getItem('neonindex_theme');
            if (saved) document.documentElement.setAttribute('data-bs-theme', saved);
        })();
    </script>
</body>

</html>