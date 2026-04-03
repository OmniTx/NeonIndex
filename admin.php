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

/**
 * Recursively delete a directory and its contents
 */
function recursiveDelete($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return @unlink($dir);
    
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!recursiveDelete($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return @rmdir($dir);
}

/**
 * Check if file is editable text
 */
function isEditable($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['txt', 'md', 'json', 'xml', 'html', 'css', 'js', 'php', 'sql', 'log', 'env', 'yml', 'yaml', 'ini', 'conf', 'gitignore', 'htaccess']);
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
            if (recursiveDelete($filePath)) {
                $message = 'Deleted!';
                $messageType = 'success';
            } else {
                $message = 'Failed to delete!';
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
            if (recursiveDelete($path))
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

// Handle Get File Content
if (($_GET['action'] ?? '') === 'get_content' && isAuthenticated()) {
    $filePath = sanitizePath($_GET['file'] ?? '');
    if ($filePath && is_file($filePath)) {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'content' => file_get_contents($filePath),
            'name' => basename($filePath)
        ]);
        exit;
    }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'File not found']);
    exit;
}

// Handle Save File Content
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_file' && isAuthenticated()) {
    if (verifyCSRF()) {
        $filePath = sanitizePath($_POST['file'] ?? '');
        $content = $_POST['content'] ?? '';
        if ($filePath && is_file($filePath)) {
            if (file_put_contents($filePath, $content) !== false) {
                $message = 'File saved!';
                $messageType = 'success';
            } else {
                $message = 'Failed to save file!';
                $messageType = 'danger';
            }
        }
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
    <title>Admin - <?= htmlspecialchars($config['SITE_TITLE']) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#FF9D00">
    <link rel="icon" type="image/svg+xml" href="favicon.svg?v=2">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        *{box-sizing:border-box}
        :root{--accent:#FF9D00;--glow:rgba(255,157,0,.35);--r:14px;--font:'Inter',system-ui,sans-serif;--ease:cubic-bezier(.4,0,.2,1)}
        body{font-family:var(--font);min-height:100vh;overflow-x:hidden}
        [data-bs-theme="dark"]{--bg:#0c0e16;--sf:rgba(18,21,32,.88);--tx:#e4e6ed;--mu:rgba(255,255,255,.4);--bd:rgba(255,255,255,.07);background:var(--bg);color:var(--tx)}
        [data-bs-theme="light"]{--bg:#f4f5f7;--sf:#fff;--tx:#1a1d26;--mu:rgba(0,0,0,.45);--bd:rgba(0,0,0,.08);background:var(--bg);color:var(--tx)}
        .topbar{position:sticky;top:0;z-index:1030;padding:.7rem 1.25rem;display:flex;align-items:center;justify-content:space-between}
        [data-bs-theme="dark"] .topbar{background:rgba(12,14,22,.75);backdrop-filter:blur(20px);border-bottom:1px solid var(--bd)}
        [data-bs-theme="light"] .topbar{background:rgba(255,255,255,.85);backdrop-filter:blur(16px);border-bottom:1px solid var(--bd)}
        .brand{display:flex;align-items:center;gap:.5rem;text-decoration:none;font-weight:700;font-size:1.05rem;color:var(--accent)}
        .glow{color:var(--accent)!important;text-shadow:0 0 16px var(--glow)}
        [data-bs-theme="light"] .glow{text-shadow:none}
        .sidebar{position:fixed;top:0;left:0;bottom:0;width:210px;padding:.75rem;padding-top:64px;border-right:1px solid var(--bd);overflow-y:auto;z-index:1020}
        [data-bs-theme="dark"] .sidebar{background:var(--sf)}
        [data-bs-theme="light"] .sidebar{background:var(--sf)}
        .sidebar .nav-link{display:flex;align-items:center;gap:.6rem;padding:.6rem .9rem;border-radius:var(--r);color:var(--mu);font-weight:500;font-size:.85rem;transition:all .2s var(--ease);margin-bottom:2px;cursor:pointer;text-decoration:none}
        .sidebar .nav-link i{font-size:1.05rem;width:18px;text-align:center}
        .sidebar .nav-link.active{color:#fff!important;background:var(--accent);box-shadow:0 3px 14px var(--glow);font-weight:600}
        [data-bs-theme="light"] .sidebar .nav-link{color:#666}
        [data-bs-theme="light"] .sidebar .nav-link.active{color:#fff!important;background:var(--accent)}
        .sidebar .nav-link:hover:not(.active){background:rgba(255,157,0,.08);color:var(--accent)}
        .main{margin-left:210px;padding:1.25rem;padding-top:calc(52px + 1.25rem);min-height:100vh}
        .card{border-radius:var(--r);overflow:hidden;transition:transform .2s var(--ease),box-shadow .2s var(--ease)}
        [data-bs-theme="dark"] .card{background:var(--sf);border:1px solid var(--bd);box-shadow:0 4px 20px rgba(0,0,0,.2)}
        [data-bs-theme="light"] .card{background:var(--sf);border:1px solid var(--bd);box-shadow:0 1px 8px rgba(0,0,0,.05)}
        .card-header{font-weight:600;padding:.9rem 1.15rem;background:transparent;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:.4rem;font-size:.9rem}
        .btn-accent{background:linear-gradient(135deg,#FF9D00,#e06800);border:none;color:#fff!important;font-weight:600;border-radius:var(--r);padding:.5rem 1.3rem;box-shadow:0 3px 10px var(--glow);transition:all .2s var(--ease)}
        .btn-accent:hover{transform:translateY(-1px);box-shadow:0 5px 18px var(--glow);background:linear-gradient(135deg,#ffaa20,#ff7a00)}
        .btn-ghost{background:transparent;border:1px solid var(--bd);color:var(--tx);border-radius:10px;padding:.35rem .75rem;font-size:.8rem;transition:all .15s}
        .btn-ghost:hover{border-color:var(--accent);color:var(--accent)}
        .form-control,.form-select{border-radius:10px;padding:.55rem .8rem;font-size:.875rem}
        [data-bs-theme="dark"] .form-control,[data-bs-theme="dark"] .form-select{background:rgba(0,0,0,.2);border-color:var(--bd);color:#fff}
        [data-bs-theme="dark"] .form-control:focus,[data-bs-theme="dark"] .form-select:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--glow);background:rgba(0,0,0,.35)}
        .form-label{font-weight:500;font-size:.8rem;margin-bottom:.3rem;color:var(--mu)}
        .form-check-input:checked{background-color:var(--accent);border-color:var(--accent)}
        .form-check-input:focus{box-shadow:0 0 0 3px var(--glow)}
        .toggle-row{display:flex;align-items:center;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid var(--bd)}
        .toggle-row:last-child{border-bottom:none}
        .toggle-row label{font-size:.85rem;font-weight:500;cursor:pointer}
        .table{margin:0;vertical-align:middle;--bs-table-bg:transparent}
        .table th{text-transform:uppercase;font-size:.7rem;letter-spacing:.07em;font-weight:600;color:var(--mu);padding:.75rem .9rem;border-bottom:1px solid var(--bd)}
        .table td{padding:.65rem .9rem;border-bottom:1px solid var(--bd);font-size:.85rem}
        [data-bs-theme="dark"] .table{color:var(--tx)}
        .badge{font-weight:500;padding:.3em .6em;border-radius:6px;font-size:.72rem}
        .alert{border-radius:var(--r);border:none;font-size:.875rem}
        [data-bs-theme="dark"] .list-group-item{background:transparent;border-color:var(--bd);color:var(--tx)}
        [data-bs-theme="dark"] .modal-content{background:var(--sf);border:1px solid var(--bd);border-radius:16px;backdrop-filter:blur(20px)}
        [data-bs-theme="dark"] .modal-header,[data-bs-theme="dark"] .modal-footer{border-color:var(--bd)}
        .tab-pane{animation:fadeUp .2s var(--ease)}
        @keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
        .empty-state{padding:2.5rem 1rem;text-align:center;color:var(--mu)}
        .empty-state i{font-size:2.5rem;margin-bottom:.5rem;display:block;opacity:.4}
        @media(max-width:991px){.sidebar{display:none}.main{margin-left:0;padding-bottom:75px}.mob-nav{display:flex!important}}
        @media(min-width:992px){.mob-nav{display:none!important}}
        .mob-nav{position:fixed;bottom:0;left:0;right:0;z-index:1040;padding:.3rem .25rem;justify-content:space-around;border-top:1px solid var(--bd)}
        [data-bs-theme="dark"] .mob-nav{background:rgba(12,14,22,.93);backdrop-filter:blur(18px)}
        [data-bs-theme="light"] .mob-nav{background:rgba(255,255,255,.93);backdrop-filter:blur(14px)}
        .mob-nav a{display:flex;flex-direction:column;align-items:center;gap:.1rem;font-size:.6rem;padding:.3rem .4rem;border-radius:8px;color:var(--mu);text-decoration:none;font-weight:500}
        .mob-nav a i{font-size:1.1rem}
        .mob-nav a.active,.mob-nav a:hover{color:var(--accent)}
        .login-card{max-width:380px;margin:8vh auto}
    </style>
</head>
<body>
    <!-- Topbar -->
    <div class="topbar">
        <a class="brand" href="admin.php">
            <i class="bi bi-shield-lock-fill"></i>
            <span class="glow">Admin Panel</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <?php if ($config['SHOW_THEME_TOGGLE'] === 'true'): ?>
                <button class="btn-ghost" onclick="toggleTheme()" title="Toggle Theme"><i class="bi bi-circle-half"></i></button>
            <?php endif; ?>
            <a href="index.php" class="btn-ghost"><i class="bi bi-arrow-left me-1"></i>Back</a>
            <?php if (isAuthenticated()): ?>
                <a href="?action=logout" class="btn-ghost text-danger"><i class="bi bi-box-arrow-right"></i></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!isAuthenticated()): ?>
    <!-- Login -->
    <div class="login-card">
        <div class="card">
            <div class="card-header justify-content-center"><i class="bi bi-lock me-2"></i>Admin Login</div>
            <div class="card-body p-4">
                <?php if ($message): ?>
                    <div class="alert alert-<?= $messageType ?> mb-3"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="action" value="login">
                    <input type="password" name="password" class="form-control mb-3" placeholder="Password" required autofocus>
                    <button type="submit" class="btn-accent w-100">Login</button>
                </form>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Sidebar (desktop) -->
    <aside class="sidebar">
        <nav>
            <a class="nav-link active" data-tab="files" onclick="switchTab('files',this)"><i class="bi bi-folder2-open"></i>Files</a>
            <a class="nav-link" data-tab="settings" onclick="switchTab('settings',this)"><i class="bi bi-gear"></i>Settings</a>
            <a class="nav-link" data-tab="bulk" onclick="switchTab('bulk',this)"><i class="bi bi-collection"></i>Bulk Ops</a>
            <a class="nav-link" data-tab="creator" onclick="switchTab('creator',this)"><i class="bi bi-plus-circle"></i>Create</a>
            <a class="nav-link" data-tab="comments" onclick="switchTab('comments',this)"><i class="bi bi-chat-dots"></i>Comments <span class="badge bg-warning text-dark ms-auto"><?= count($comments) ?></span></a>
            <a class="nav-link" data-tab="logs" onclick="switchTab('logs',this)"><i class="bi bi-journal-text"></i>Logs</a>
            <a class="nav-link" data-tab="server" onclick="switchTab('server',this)"><i class="bi bi-hdd-network"></i>Server</a>
        </nav>
    </aside>

    <!-- Mobile Nav -->
    <div class="mob-nav">
        <a class="active" data-tab="files" onclick="switchTab('files',this)"><i class="bi bi-folder2-open"></i>Files</a>
        <a data-tab="settings" onclick="switchTab('settings',this)"><i class="bi bi-gear"></i>Settings</a>
        <a data-tab="bulk" onclick="switchTab('bulk',this)"><i class="bi bi-collection"></i>Bulk</a>
        <a data-tab="creator" onclick="switchTab('creator',this)"><i class="bi bi-plus-circle"></i>Create</a>
        <a data-tab="comments" onclick="switchTab('comments',this)"><i class="bi bi-chat-dots"></i>Comments</a>
        <a data-tab="logs" onclick="switchTab('logs',this)"><i class="bi bi-journal-text"></i>Logs</a>
        <a data-tab="server" onclick="switchTab('server',this)"><i class="bi bi-hdd-network"></i>Server</a>
    </div>

    <div class="main">
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show mb-3">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- ═══ FILES TAB ═══ -->
        <div class="tab-pane" id="tab-files">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <i class="bi bi-folder2-open me-1"></i>File Manager
                        <nav class="d-inline-block ms-2" style="font-size:.8rem">
                            <?php foreach ($breadcrumbs as $i => $crumb): ?>
                                <?php if ($i > 0): ?> / <?php endif; ?>
                                <a href="?dir=<?= safeUrlEncode($crumb['path']) ?>" class="text-decoration-none text-accent"><?= htmlspecialchars($crumb['name']) ?></a>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                    <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="dir" value="<?= htmlspecialchars($relativeCurrentDir) ?>">
                        <input type="file" name="file" required class="form-control form-control-sm" style="max-width:170px">
                        <button type="submit" class="btn-accent btn-sm py-1 px-2"><i class="bi bi-upload"></i></button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Name</th><th>Size</th><th>Modified</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <?php if ($item['isDir']): ?>
                                        <i class="bi bi-folder-fill text-warning me-1"></i>
                                        <a href="?dir=<?= safeUrlEncode($item['path']) ?>" class="text-decoration-none"><?= htmlspecialchars($item['name']) ?></a>
                                    <?php else: ?>
                                        <i class="bi bi-file-earmark text-secondary me-1"></i><?= htmlspecialchars($item['name']) ?>
                                    <?php endif; ?>
                                </td>
                                <td style="color:var(--mu)"><?= $item['isDir'] ? '—' : formatBytes($item['size']) ?></td>
                                <td style="color:var(--mu)"><?= date('M d, H:i', $item['modified']) ?></td>
                                <td><?= $item['hidden'] ? '<span class="badge bg-danger">Hidden</span>' : '<span class="badge bg-success">Visible</span>' ?></td>
                                <td class="text-end text-nowrap">
                                    <?php if (!$item['isDir'] && $item['name'] !== '..'): ?>
                                        <a href="uploads/<?= htmlspecialchars($item['path']) ?>" download class="btn-ghost py-1 px-2"><i class="bi bi-download"></i></a>
                                    <?php endif; ?>
                                    <?php if (!$item['isDir'] && isEditable($item['name'])): ?>
                                        <button class="btn-ghost py-1 px-2" onclick="showEdit('<?= htmlspecialchars(addslashes($item['path'])) ?>','<?= htmlspecialchars(addslashes($item['name'])) ?>')"><i class="bi bi-pencil-square"></i></button>
                                    <?php endif; ?>
                                    <?php if ($item['name'] !== '..'): ?>
                                        <button class="btn-ghost py-1 px-2" onclick="showRename('<?= htmlspecialchars(addslashes($item['path'])) ?>','<?= htmlspecialchars(addslashes($item['name'])) ?>')"><i class="bi bi-input-cursor-text"></i></button>
                                        <button class="btn-ghost py-1 px-2 text-danger" onclick="showDelete('<?= htmlspecialchars(addslashes($item['path'])) ?>','<?= htmlspecialchars(addslashes($item['name'])) ?>')"><i class="bi bi-trash3"></i></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($items)): ?>
                                <tr><td colspan="5" class="empty-state"><i class="bi bi-folder-x"></i>No files found</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══ SETTINGS TAB ═══ -->
        <div class="tab-pane" id="tab-settings" style="display:none">
            <div class="card">
                <div class="card-header"><i class="bi bi-gear me-2"></i>Settings</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="save_settings">
                        <div class="row g-4">
                            <div class="col-lg-6">
                                <div class="mb-3"><label class="form-label">Site Title</label><input type="text" name="site_title" class="form-control" value="<?= htmlspecialchars($config['SITE_TITLE']) ?>"></div>
                                <div class="mb-3"><label class="form-label">Admin Password</label><input type="text" name="admin_password" class="form-control" value="<?= htmlspecialchars($config['ADMIN_PASSWORD']) ?>"></div>
                                <div class="mb-3"><label class="form-label">Hidden Files</label><input type="text" name="hidden_files" class="form-control" value="<?= htmlspecialchars($config['HIDDEN_FILES']) ?>"><small style="color:var(--mu)">Comma-separated list</small></div>
                                <div class="row g-3">
                                    <div class="col-6"><label class="form-label">Default Theme</label><select name="default_theme" class="form-select"><option value="dark" <?= $config['DEFAULT_THEME']==='dark'?'selected':'' ?>>Dark</option><option value="light" <?= $config['DEFAULT_THEME']==='light'?'selected':'' ?>>Light</option></select></div>
                                    <div class="col-6"><label class="form-label">README Position</label><select name="readme_position" class="form-select"><option value="top" <?= $config['README_POSITION']==='top'?'selected':'' ?>>Top</option><option value="bottom" <?= $config['README_POSITION']==='bottom'?'selected':'' ?>>Bottom</option></select></div>
                                </div>
                                <hr style="border-color:var(--bd)" class="my-4">
                                <h6 class="text-accent mb-3"><i class="bi bi-speedometer2 me-1"></i>Limits & Logging</h6>
                                <div class="row g-3">
                                    <div class="col-6"><label class="form-label">Max Upload (MB)</label><input type="number" name="max_upload_size" class="form-control" value="<?= htmlspecialchars($config['MAX_UPLOAD_SIZE']) ?>" min="0"><small style="color:var(--mu)">0 = unlimited</small></div>
                                    <div class="col-6"><label class="form-label">Chunk Size (MB)</label><input type="number" name="chunk_size_mb" class="form-control" value="<?= htmlspecialchars($config['CHUNK_SIZE_MB']) ?>" min="1" max="50"></div>
                                    <div class="col-6"><label class="form-label">Upload Limit/Hr</label><input type="number" name="rate_limit_uploads" class="form-control" value="<?= htmlspecialchars($config['RATE_LIMIT_UPLOADS']) ?>" min="0"></div>
                                    <div class="col-6"><label class="form-label">Comment Limit/Hr</label><input type="number" name="rate_limit_comments" class="form-control" value="<?= htmlspecialchars($config['RATE_LIMIT_COMMENTS']) ?>" min="0"></div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <label class="form-label mb-2">Visible Features</label>
                                <div class="rounded p-3" style="background:var(--bd)">
                                    <div class="toggle-row"><label>Download Button</label><input type="checkbox" name="show_download" class="form-check-input" <?= $config['SHOW_DOWNLOAD']==='true'?'checked':'' ?>></div>
                                    <div class="toggle-row"><label>Rename Button</label><input type="checkbox" name="show_rename" class="form-check-input" <?= $config['SHOW_RENAME']==='true'?'checked':'' ?>></div>
                                    <div class="toggle-row"><label>Delete Button</label><input type="checkbox" name="show_delete" class="form-check-input" <?= $config['SHOW_DELETE']==='true'?'checked':'' ?>></div>
                                    <div class="toggle-row"><label>Upload Form</label><input type="checkbox" name="show_upload" class="form-check-input" <?= $config['SHOW_UPLOAD']==='true'?'checked':'' ?>></div>
                                    <div class="toggle-row"><label>Theme Toggle</label><input type="checkbox" name="show_theme_toggle" class="form-check-input" <?= $config['SHOW_THEME_TOGGLE']==='true'?'checked':'' ?>></div>
                                    <div class="toggle-row"><label>Comments</label><input type="checkbox" name="show_comments" class="form-check-input" <?= $config['SHOW_COMMENTS']==='true'?'checked':'' ?>></div>
                                    <div class="toggle-row"><label>Download Log</label><input type="checkbox" name="enable_download_log" class="form-check-input" <?= $config['ENABLE_DOWNLOAD_LOG']==='true'?'checked':'' ?>></div>
                                </div>
                                <button type="submit" class="btn-accent w-100 mt-3"><i class="bi bi-check2 me-1"></i>Save Settings</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- ═══ BULK TAB ═══ -->
        <div class="tab-pane" id="tab-bulk" style="display:none">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-collection me-2"></i>Bulk Operations</span>
                    <div class="d-flex gap-1">
                        <button class="btn-ghost" onclick="document.querySelectorAll('.bulk-cb').forEach(c=>c.checked=true)">Select All</button>
                        <button class="btn-ghost" onclick="document.querySelectorAll('.bulk-cb').forEach(c=>c.checked=false)">Deselect</button>
                    </div>
                </div>
                <div class="card-body d-flex gap-2 flex-wrap align-items-center" style="border-bottom:1px solid var(--bd)">
                    <span style="color:var(--mu);font-size:.85rem">With selected:</span>
                    <button class="btn-ghost text-danger" onclick="bulkDelete()"><i class="bi bi-trash3 me-1"></i>Delete</button>
                    <select id="moveTarget" class="form-select form-select-sm" style="max-width:140px">
                        <?php foreach ($directories as $path => $label): ?>
                            <option value="<?= htmlspecialchars($path) ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn-ghost" onclick="bulkMove()"><i class="bi bi-box-arrow-right me-1"></i>Move</button>
                </div>
                <div style="max-height:400px;overflow-y:auto">
                    <form id="bulkForm" method="POST">
                        <input type="hidden" name="action" id="bulkAction" value="">
                        <input type="hidden" name="target_dir" id="targetDir" value="">
                        <table class="table table-hover">
                            <thead class="sticky-top" style="background:var(--sf)"><tr><th width="30"></th><th>Path</th><th>Type</th><th class="text-end">Size</th></tr></thead>
                            <tbody>
                                <?php foreach ($allFiles as $file): ?>
                                <tr>
                                    <td><input type="checkbox" name="files[]" value="<?= htmlspecialchars($file['path']) ?>" class="form-check-input bulk-cb"></td>
                                    <td class="<?= $file['isDir'] ? 'text-warning' : '' ?>"><?= htmlspecialchars($file['path']) ?></td>
                                    <td><?= $file['isDir'] ? '<i class="bi bi-folder"></i>' : '<i class="bi bi-file-earmark"></i>' ?></td>
                                    <td class="text-end" style="color:var(--mu)"><?= $file['isDir'] ? '—' : formatBytes($file['size']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>
                </div>
                <div class="card-body small" style="color:var(--mu)">Total: <?= count($allFiles) ?> items</div>
            </div>
        </div>

        <!-- ═══ CREATE TAB ═══ -->
        <div class="tab-pane" id="tab-creator" style="display:none">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-file-plus me-2"></i>Create File</div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="create_file">
                                <div class="mb-3"><label class="form-label">File Name</label><input type="text" name="file_name" class="form-control" placeholder="example.txt" required></div>
                                <div class="mb-3"><label class="form-label">Target Directory</label><select name="target_dir" class="form-select"><?php foreach ($directories as $path => $label): ?><option value="<?= htmlspecialchars($path) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
                                <div class="mb-3"><label class="form-label">Content</label><textarea name="file_content" class="form-control" rows="4" placeholder="File content..."></textarea></div>
                                <button type="submit" class="btn-accent w-100"><i class="bi bi-file-plus me-1"></i>Create File</button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-folder-plus me-2"></i>Create Folder</div>
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="create_dir">
                                <div class="mb-3"><label class="form-label">Folder Name</label><input type="text" name="dir_name" class="form-control" placeholder="new-folder" required></div>
                                <div class="mb-3"><label class="form-label">Target Directory</label><select name="target_dir" class="form-select"><?php foreach ($directories as $path => $label): ?><option value="<?= htmlspecialchars($path) ?>"><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></div>
                                <button type="submit" class="btn-accent w-100"><i class="bi bi-folder-plus me-1"></i>Create Folder</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ COMMENTS TAB ═══ -->
        <div class="tab-pane" id="tab-comments" style="display:none">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-chat-dots me-2"></i>Comments (<?= count($comments) ?>)</span>
                    <?php if (!empty($comments)): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Clear ALL comments?')">
                            <input type="hidden" name="action" value="clear_comments">
                            <button type="submit" class="btn-ghost text-danger"><i class="bi bi-trash3 me-1"></i>Clear All</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if (empty($comments)): ?>
                    <div class="empty-state"><i class="bi bi-chat-square-text"></i>No comments yet</div>
                <?php else: ?>
                    <div class="list-group list-group-flush" style="max-height:500px;overflow-y:auto">
                        <?php foreach ($comments as $i => $comment): ?>
                        <div class="list-group-item px-3 py-3">
                            <div class="d-flex justify-content-between align-items-start">
                                <div><strong><?= htmlspecialchars($comment['name'] ?? 'Anonymous') ?></strong> <small style="color:var(--mu)" class="ms-1"><?= htmlspecialchars($comment['email'] ?? '') ?></small></div>
                                <div class="d-flex align-items-center gap-2">
                                    <small style="color:var(--mu)"><?= date('M d, Y H:i', strtotime($comment['date'] ?? 'now')) ?></small>
                                    <form method="POST" class="d-inline"><input type="hidden" name="action" value="delete_comment"><input type="hidden" name="comment_index" value="<?= $i ?>"><button type="submit" class="btn-ghost py-0 px-1 text-danger"><i class="bi bi-x-lg"></i></button></form>
                                </div>
                            </div>
                            <p class="mb-0 mt-2" style="font-size:.9rem"><?= nl2br(htmlspecialchars($comment['message'] ?? '')) ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══ LOGS TAB ═══ -->
        <div class="tab-pane" id="tab-logs" style="display:none">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-journal-text me-2"></i>Download Logs</span>
                    <form method="POST" onsubmit="return confirm('Clear all logs?');">
                        <input type="hidden" name="action" value="clear_logs">
                        <button type="submit" class="btn-ghost text-danger"><i class="bi bi-trash3 me-1"></i>Clear</button>
                    </form>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr><th>Date</th><th>IP Address</th><th>File</th><th>User Agent</th></tr></thead>
                        <tbody>
                            <?php
                            $logFile = __DIR__ . '/downloads.log';
                            if (file_exists($logFile)) {
                                $logs = array_reverse(file($logFile));
                                if (empty($logs)) echo "<tr><td colspan='4' class='empty-state'>No logs found</td></tr>";
                                foreach ($logs as $log) {
                                    if (trim($log) && preg_match('/^\[(.*?)\] IP: (.*?) \| File: (.*?) \| UA: (.*)$/', $log, $m)) {
                                        echo "<tr><td class='text-nowrap'>".htmlspecialchars($m[1])."</td><td>".htmlspecialchars($m[2])."</td><td>".htmlspecialchars($m[3])."</td><td class='small' style='color:var(--mu)'>".htmlspecialchars($m[4])."</td></tr>";
                                    }
                                }
                            } else { echo "<tr><td colspan='4' class='empty-state'>No logs found</td></tr>"; }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══ SERVER TAB ═══ -->
        <div class="tab-pane" id="tab-server" style="display:none">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-hdd-network me-2"></i>Environment</div>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between"><span>PHP Version</span><span class="badge bg-secondary"><?= phpversion() ?></span></div>
                            <div class="list-group-item d-flex justify-content-between"><span>Server Software</span><small style="color:var(--mu)"><?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') ?></small></div>
                            <div class="list-group-item d-flex justify-content-between"><span>Server IP</span><span class="badge bg-secondary"><?= $_SERVER['SERVER_ADDR'] ?? 'Unknown' ?></span></div>
                            <div class="list-group-item d-flex justify-content-between"><span>Client IP</span><span class="badge bg-secondary"><?= $_SERVER['REMOTE_ADDR'] ?? 'Unknown' ?></span></div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><i class="bi bi-gear-wide-connected me-2"></i>Configuration</div>
                        <div class="list-group list-group-flush">
                            <div class="list-group-item d-flex justify-content-between"><span>Upload Max</span><span class="badge bg-warning text-dark"><?= ini_get('upload_max_filesize') ?></span></div>
                            <div class="list-group-item d-flex justify-content-between"><span>Post Max Size</span><span class="badge bg-warning text-dark"><?= ini_get('post_max_size') ?></span></div>
                            <div class="list-group-item d-flex justify-content-between"><span>Memory Limit</span><span class="badge bg-warning text-dark"><?= ini_get('memory_limit') ?></span></div>
                            <div class="list-group-item d-flex justify-content-between"><span>Max Exec Time</span><span class="badge bg-warning text-dark"><?= ini_get('max_execution_time') ?>s</span></div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card">
                        <div class="card-header"><i class="bi bi-clipboard-check me-2"></i>Requirement Checks</div>
                        <div class="table-responsive">
                            <table class="table">
                                <thead><tr><th>Check</th><th>Status</th><th>Message</th></tr></thead>
                                <tbody>
                                    <?php $phpOk = version_compare(PHP_VERSION, '7.4.0', '>='); ?>
                                    <tr><td>PHP >= 7.4</td><td><?= $phpOk ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?></td><td class="small" style="color:var(--mu)"><?= $phpOk ? 'Current: '.PHP_VERSION : 'Update PHP' ?></td></tr>
                                    <?php foreach (['json','mbstring','fileinfo','gd'] as $ext): $ok = extension_loaded($ext); ?>
                                    <tr><td><?= $ext ?></td><td><?= $ok ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?></td><td class="small" style="color:var(--mu)"><?= $ok ? 'Installed' : 'Install '.$ext ?></td></tr>
                                    <?php endforeach; ?>
                                    <?php foreach (['Uploads Dir'=>BASE_DIR,'Config File'=>__DIR__.'/.env','Comments'=>COMMENTS_FILE,'Rate Limits'=>RATE_LIMIT_FILE,'Download Log'=>DOWNLOAD_LOG_FILE] as $n=>$p): $e=file_exists($p);$w=is_writable($e?$p:dirname($p)); ?>
                                    <tr><td><?= $n ?></td><td><?= $w ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?></td><td class="small" style="color:var(--mu)"><?= $w?'Writable':'Check permissions' ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div><!-- /main -->
    <?php endif; ?>

    <!-- Rename Modal -->
    <div class="modal fade" id="renameModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-input-cursor-text me-2"></i>Rename</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="action" value="rename"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="file" id="renameFile"><label class="form-label">New name</label><input type="text" name="newname" id="renameName" class="form-control" required></div><div class="modal-footer"><button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-accent">Rename</button></div></form></div></div></div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title text-danger"><i class="bi bi-trash3 me-2"></i>Delete</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="action" value="delete"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="file" id="deleteFile"><p>Are you sure you want to delete <strong id="deleteFileName"></strong>?</p></div><div class="modal-footer"><button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-accent" style="background:linear-gradient(135deg,#dc3545,#a71d2a)">Delete</button></div></form></div></div></div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit: <span id="editFileNameDisplay" class="fw-bold text-accent"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body p-0"><form id="editForm" method="POST"><input type="hidden" name="action" value="save_file"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="file" id="editFile"><textarea name="content" id="editContent" class="form-control border-0 rounded-0" style="height:65vh;font-family:'Fira Code',monospace;font-size:13px;resize:none"></textarea></form></div><div class="modal-footer"><button type="button" class="btn-ghost" data-bs-dismiss="modal">Close</button><button type="button" class="btn-accent" onclick="document.getElementById('editForm').submit()"><i class="bi bi-check2 me-1"></i>Save</button></div></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleTheme(){const h=document.documentElement;const n=h.getAttribute('data-bs-theme')==='dark'?'light':'dark';h.setAttribute('data-bs-theme',n);localStorage.setItem('neonindex_theme',n)}
        function switchTab(id,el){document.querySelectorAll('.tab-pane').forEach(p=>p.style.display='none');const t=document.getElementById('tab-'+id);if(t)t.style.display='block';document.querySelectorAll('.sidebar .nav-link, .mob-nav a').forEach(a=>a.classList.remove('active'));document.querySelectorAll('[data-tab="'+id+'"]').forEach(a=>a.classList.add('active'))}
        function showRename(p,n){document.getElementById('renameFile').value=p;document.getElementById('renameName').value=n;new bootstrap.Modal(document.getElementById('renameModal')).show()}
        function showDelete(p,n){document.getElementById('deleteFile').value=p;document.getElementById('deleteFileName').textContent=n;new bootstrap.Modal(document.getElementById('deleteModal')).show()}
        function showEdit(p,n){const m=new bootstrap.Modal(document.getElementById('editModal'));document.getElementById('editFileNameDisplay').textContent=n;document.getElementById('editFile').value=p;document.getElementById('editContent').value='Loading...';m.show();fetch('?action=get_content&file='+encodeURIComponent(p)).then(r=>r.json()).then(d=>{if(d.status==='success')document.getElementById('editContent').value=d.content;else{alert('Error: '+d.message);m.hide()}}).catch(()=>{alert('Request failed');m.hide()})}
        function bulkDelete(){const c=document.querySelectorAll('.bulk-cb:checked').length;if(!c)return alert('Select files first');if(!confirm('Delete '+c+' item(s)?'))return;document.getElementById('bulkAction').value='bulk_delete';document.getElementById('bulkForm').submit()}
        function bulkMove(){const c=document.querySelectorAll('.bulk-cb:checked').length;if(!c)return alert('Select files first');document.getElementById('bulkAction').value='bulk_move';document.getElementById('targetDir').value=document.getElementById('moveTarget').value;document.getElementById('bulkForm').submit()}
        (function(){const s=localStorage.getItem('neonindex_theme');if(s)document.documentElement.setAttribute('data-bs-theme',s)})();
    </script>
</body>
</html>
