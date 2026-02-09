<?php
/**
 * NeonIndex - PHP Directory Lister & File Manager
 * 
 * A modern, Bootstrap-based directory listing application with admin panel,
 * theme switching, README rendering, and visitor comments support.
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

$config = loadEnv(__DIR__ . '/.env');

// Application Settings
define('ADMIN_PASSWORD', $config['ADMIN_PASSWORD'] ?? 'admin123');
define('HIDDEN_FILES', array_map('trim', explode(',', $config['HIDDEN_FILES'] ?? '.env,admin.php')));
define('SITE_TITLE', $config['SITE_TITLE'] ?? 'NeonIndex');
define('DEFAULT_THEME', $config['DEFAULT_THEME'] ?? 'dark');
define('README_POSITION', $config['README_POSITION'] ?? 'bottom');
define('SHOW_DOWNLOAD', ($config['SHOW_DOWNLOAD'] ?? 'true') === 'true');
define('SHOW_RENAME', ($config['SHOW_RENAME'] ?? 'true') === 'true');
define('SHOW_DELETE', ($config['SHOW_DELETE'] ?? 'true') === 'true');
define('SHOW_UPLOAD', ($config['SHOW_UPLOAD'] ?? 'true') === 'true');
define('SHOW_THEME_TOGGLE', ($config['SHOW_THEME_TOGGLE'] ?? 'true') === 'true');
define('SHOW_COMMENTS', ($config['SHOW_COMMENTS'] ?? 'true') === 'true');
define('BASE_DIR', realpath(__DIR__ . '/uploads') ?: __DIR__ . '/uploads');
define('COMMENTS_FILE', __DIR__ . '/comments.json');

// Upload & Rate Limiting Settings
define('MAX_UPLOAD_SIZE', (int) ($config['MAX_UPLOAD_SIZE'] ?? 10));  // MB
define('CHUNK_SIZE_MB', (int) ($config['CHUNK_SIZE_MB'] ?? 8));  // MB for chunked uploads
define('RATE_LIMIT_UPLOADS', (int) ($config['RATE_LIMIT_UPLOADS'] ?? 20));  // per hour
define('RATE_LIMIT_COMMENTS', (int) ($config['RATE_LIMIT_COMMENTS'] ?? 10));  // per hour
define('ENABLE_DOWNLOAD_LOG', ($config['ENABLE_DOWNLOAD_LOG'] ?? 'false') === 'true');
define('RATE_LIMIT_FILE', __DIR__ . '/rate_limits.json');
define('DOWNLOAD_LOG_FILE', __DIR__ . '/downloads.log');

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// =============================================================================
// RATE LIMITING
// =============================================================================

/**
 * Get rate limit data for current IP
 */
function getRateLimits(): array
{
    if (!file_exists(RATE_LIMIT_FILE))
        return [];
    $data = json_decode(file_get_contents(RATE_LIMIT_FILE), true);
    return is_array($data) ? $data : [];
}

/**
 * Save rate limit data
 */
function saveRateLimits(array $data): void
{
    file_put_contents(RATE_LIMIT_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

/**
 * Check if action is rate limited for current IP
 */
function isRateLimited(string $action, int $limit): bool
{
    if ($limit <= 0)
        return false;  // 0 = disabled

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $limits = getRateLimits();
    $hourAgo = time() - 3600;

    // Clean old entries
    if (isset($limits[$ip][$action])) {
        $limits[$ip][$action] = array_filter($limits[$ip][$action], fn($t) => $t > $hourAgo);
    }

    $count = count($limits[$ip][$action] ?? []);
    return $count >= $limit;
}

/**
 * Record an action for rate limiting
 */
function recordAction(string $action): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $limits = getRateLimits();

    if (!isset($limits[$ip]))
        $limits[$ip] = [];
    if (!isset($limits[$ip][$action]))
        $limits[$ip][$action] = [];

    $limits[$ip][$action][] = time();
    saveRateLimits($limits);
}

/**
 * Log a download
 */
function logDownload(string $file): void
{
    if (!ENABLE_DOWNLOAD_LOG)
        return;

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $time = date('Y-m-d H:i:s');
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $log = "[{$time}] IP: {$ip} | File: {$file} | UA: {$userAgent}\n";

    file_put_contents(DOWNLOAD_LOG_FILE, $log, FILE_APPEND | LOCK_EX);
}

// =============================================================================
// MARKDOWN PARSER
// =============================================================================

/**
 * Minimal Markdown parser with support for headings, bold, italic, 
 * code, links, images, and lists. Groups consecutive images for
 * side-by-side display.
 */
class Parsedown
{
    public function text($text)
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $html = '';
        $imageBuffer = [];

        foreach ($lines as $line) {
            $parsedLine = $this->parseLine($line, true);

            // Group consecutive image-only lines
            if (preg_match('/^<img[^>]+>$/', trim($parsedLine))) {
                $imageBuffer[] = $parsedLine;
            } else {
                if (!empty($imageBuffer)) {
                    $html .= '<div class="d-flex flex-wrap gap-2 my-2">' . implode('', $imageBuffer) . '</div>';
                    $imageBuffer = [];
                }
                $html .= $this->parseLine($line, false) . "\n";
            }
        }

        // Flush remaining buffered images
        if (!empty($imageBuffer)) {
            $html .= '<div class="d-flex flex-wrap gap-2 my-2">' . implode('', $imageBuffer) . '</div>';
        }

        return $html;
    }

    protected function parseLine($line, $checkOnly = false)
    {
        // Headings
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
            $level = strlen($m[1]);
            return "<h{$level} class='text-info fw-bold mt-3 mb-2'>" . htmlspecialchars($m[2]) . "</h{$level}>";
        }

        // Inline formatting
        $line = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line);
        $line = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $line);
        $line = preg_replace('/`(.+?)`/', '<code class="bg-secondary px-1 rounded">$1</code>', $line);
        $line = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '<img src="$2" alt="$1" class="rounded" style="height: 28px;">', $line);
        $line = preg_replace('/\[(.+?)\]\((.+?)\)/', '<a href="$2" class="text-info" target="_blank">$1</a>', $line);

        if ($checkOnly)
            return $line;

        // Lists
        if (preg_match('/^[-*]\s+(.+)$/', $line, $m)) {
            return '<li class="ms-3">' . $m[1] . '</li>';
        }

        // Empty lines
        if (trim($line) === '')
            return '<br>';

        return '<p class="mb-1">' . $line . '</p>';
    }
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
 * Format bytes to human-readable size
 */
function formatBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    return round($bytes / pow(1024, min($pow, 4)), 2) . ' ' . $units[min($pow, 4)];
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
 * Check if file should be hidden from listing
 */
function isHiddenFile(string $filename): bool
{
    return in_array($filename, HIDDEN_FILES) || in_array(strtolower($filename), HIDDEN_FILES);
}

/**
 * Recursively delete a directory and all its contents
 */
function deleteRecursive(string $path): bool
{
    if (is_file($path)) {
        return @unlink($path);
    }
    if (is_dir($path)) {
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..')
                continue;
            if (!deleteRecursive($path . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        return @rmdir($path);
    }
    return false;
}

/**
 * Verify CSRF token
 */
function verifyCSRF(): bool
{
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Get file icon based on extension
 */
function getFileIcon(string $filename): string
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $icons = [
        'php' => 'bi-filetype-php',
        'js' => 'bi-filetype-js',
        'css' => 'bi-filetype-css',
        'html' => 'bi-filetype-html',
        'json' => 'bi-filetype-json',
        'md' => 'bi-filetype-md',
        'txt' => 'bi-file-text',
        'pdf' => 'bi-filetype-pdf',
        'jpg' => 'bi-file-image',
        'jpeg' => 'bi-file-image',
        'png' => 'bi-file-image',
        'gif' => 'bi-file-image',
        'svg' => 'bi-file-image',
        'zip' => 'bi-file-zip',
        'rar' => 'bi-file-zip',
        'mp3' => 'bi-file-music',
        'mp4' => 'bi-file-play',
        'exe' => 'bi-file-binary',
    ];
    return $icons[$ext] ?? 'bi-file-earmark';
}

// =============================================================================
// REQUEST HANDLERS
// =============================================================================

$message = '';
$messageType = '';

// Check for flash message from redirect
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = $_SESSION['flash_type'] ?? 'info';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
}

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $password = $_POST['password'] ?? '';
    // Use constant-time comparison to prevent timing attacks
    if (hash_equals(ADMIN_PASSWORD, $password)) {
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['authenticated'] = true;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $message = 'Invalid password!';
        $messageType = 'danger';
    }
}

// Handle Logout
if (($_GET['action'] ?? '') === 'logout') {
    $_SESSION = [];
    session_destroy();
    session_start();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle View (display file in browser)
if (($_GET['action'] ?? '') === 'view' && isset($_GET['file'])) {
    $filePath = sanitizePath($_GET['file']);
    if ($filePath && is_file($filePath) && !isHiddenFile(basename($filePath))) {
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $fileName = basename($filePath);

        // For text files, force text/plain to display properly
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $textExtensions = ['txt', 'md', 'json', 'xml', 'csv', 'log', 'ini', 'yml', 'yaml', 'css', 'js', 'php', 'html', 'htm', 'sql'];
        if (in_array($ext, $textExtensions)) {
            $mimeType = 'text/plain; charset=utf-8';
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// Handle Download (with logging)
if (($_GET['action'] ?? '') === 'download' && isset($_GET['file'])) {
    $filePath = sanitizePath($_GET['file']);
    if ($filePath && is_file($filePath) && !isHiddenFile(basename($filePath))) {
        logDownload($_GET['file']);

        // Disable output buffering and increase limits for large files
        @ini_set('memory_limit', '-1');
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        if (ob_get_level())
            ob_end_clean();

        $fileSize = filesize($filePath);

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . $fileSize);
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: no-cache, must-revalidate');

        // Stream file in chunks (8KB at a time)
        $handle = fopen($filePath, 'rb');
        if ($handle) {
            while (!feof($handle) && !connection_aborted()) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
        }
        exit;
    }
}

// Handle Folder Download (ZIP)
if (($_GET['action'] ?? '') === 'download_folder' && isset($_GET['folder'])) {
    $folderPath = sanitizePath($_GET['folder']);
    if ($folderPath && is_dir($folderPath) && class_exists('ZipArchive')) {
        // Increase limits for large folders
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $zipName = basename($folderPath) . '.zip';
        $tempZip = tempnam(sys_get_temp_dir(), 'zip_');

        $zip = new ZipArchive();
        if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folderPath, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($folderPath) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();

            logDownload($_GET['folder'] . ' (folder)');
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipName . '"');
            header('Content-Length: ' . filesize($tempZip));
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: no-cache, must-revalidate');

            // Stream ZIP file in chunks to avoid memory issues
            $handle = fopen($tempZip, 'rb');
            if ($handle) {
                while (!feof($handle) && !connection_aborted()) {
                    echo fread($handle, 8192);
                    flush();
                }
                fclose($handle);
            }
            unlink($tempZip);
            exit;
        }
    }
}

// Handle Delete (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isAuthenticated()) {
    if (verifyCSRF() && isset($_POST['file'])) {
        $filePath = sanitizePath($_POST['file']);
        if ($filePath && $filePath !== BASE_DIR) {
            // Use recursive delete for directories (supports non-empty folders)
            if (deleteRecursive($filePath)) {
                $message = 'Deleted successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to delete!';
                $messageType = 'danger';
            }
        }
    }
}

// Handle Rename (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rename' && isAuthenticated()) {
    if (verifyCSRF() && isset($_POST['file'], $_POST['newname'])) {
        $filePath = sanitizePath($_POST['file']);
        $newName = basename($_POST['newname']);
        if ($filePath && $filePath !== BASE_DIR && $newName) {
            $newPath = dirname($filePath) . DIRECTORY_SEPARATOR . $newName;
            if (!file_exists($newPath) && @rename($filePath, $newPath)) {
                $message = 'Renamed successfully!';
                $messageType = 'success';
            } else {
                $message = 'Failed to rename!';
                $messageType = 'danger';
            }
        }
    }
}

// Handle Upload (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload' && isAuthenticated()) {
    if (verifyCSRF() && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        // Check rate limit
        if (isRateLimited('upload', RATE_LIMIT_UPLOADS)) {
            $_SESSION['flash_message'] = 'Upload rate limit exceeded. Try again later.';
            $_SESSION['flash_type'] = 'warning';
        }
        // Check file size (0 = unlimited)
        elseif (MAX_UPLOAD_SIZE > 0 && $_FILES['file']['size'] > MAX_UPLOAD_SIZE * 1024 * 1024) {
            $_SESSION['flash_message'] = 'File too large! Max: ' . MAX_UPLOAD_SIZE . 'MB';
            $_SESSION['flash_type'] = 'danger';
        } else {
            $currentDir = isset($_POST['dir']) ? sanitizePath($_POST['dir']) : BASE_DIR;
            if (!$currentDir)
                $currentDir = BASE_DIR;
            $filename = basename($_FILES['file']['name']);
            if (move_uploaded_file($_FILES['file']['tmp_name'], $currentDir . DIRECTORY_SEPARATOR . $filename)) {
                recordAction('upload');
                $_SESSION['flash_message'] = 'File uploaded!';
                $_SESSION['flash_type'] = 'success';
            } else {
                $_SESSION['flash_message'] = 'Upload failed!';
                $_SESSION['flash_type'] = 'danger';
            }
        }
        // Redirect to prevent form resubmission
        $redirectUrl = $_SERVER['PHP_SELF'];
        if (isset($_POST['dir']) && $_POST['dir'] !== '') {
            $redirectUrl .= '?dir=' . urlencode($_POST['dir']);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Handle Chunked Upload (AJAX - for large files)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'chunked_upload' && isAuthenticated()) {
    header('Content-Type: application/json');

    if (!verifyCSRF()) {
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        exit;
    }

    $fileName = $_POST['fileName'] ?? '';
    $chunkIndex = (int) ($_POST['chunkIndex'] ?? 0);
    $totalChunks = (int) ($_POST['totalChunks'] ?? 1);
    $uploadDir = isset($_POST['dir']) ? sanitizePath($_POST['dir']) : BASE_DIR;

    if (!$uploadDir)
        $uploadDir = BASE_DIR;

    $tempDir = $uploadDir . DIRECTORY_SEPARATOR . '.upload_temp';
    if (!is_dir($tempDir))
        @mkdir($tempDir, 0755, true);

    $tempFile = $tempDir . DIRECTORY_SEPARATOR . md5($fileName) . '_' . $chunkIndex;

    if (isset($_FILES['chunk']) && $_FILES['chunk']['error'] === UPLOAD_ERR_OK) {
        if (move_uploaded_file($_FILES['chunk']['tmp_name'], $tempFile)) {
            // If this is the last chunk, merge all chunks
            if ($chunkIndex === $totalChunks - 1) {
                // Handle folder structure (fileName might be "folder/subfolder/file.txt")
                $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fileName);
                $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);

                // Create subdirectories if needed
                $finalDir = $uploadDir;
                if (strpos($relativePath, DIRECTORY_SEPARATOR) !== false) {
                    $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
                    $justFileName = array_pop($pathParts);
                    $subPath = implode(DIRECTORY_SEPARATOR, $pathParts);
                    $finalDir = $uploadDir . DIRECTORY_SEPARATOR . $subPath;
                    if (!is_dir($finalDir)) {
                        @mkdir($finalDir, 0755, true);
                    }
                    $relativePath = $justFileName;
                }

                $finalPath = $finalDir . DIRECTORY_SEPARATOR . basename($relativePath);
                $fp = fopen($finalPath, 'wb');

                for ($i = 0; $i < $totalChunks; $i++) {
                    $chunkPath = $tempDir . DIRECTORY_SEPARATOR . md5($fileName) . '_' . $i;
                    if (file_exists($chunkPath)) {
                        fwrite($fp, file_get_contents($chunkPath));
                        unlink($chunkPath);
                    }
                }
                fclose($fp);

                // Cleanup temp dir if empty
                @rmdir($tempDir);

                recordAction('upload');
                echo json_encode(['success' => true, 'complete' => true, 'message' => 'File uploaded successfully!']);
            } else {
                echo json_encode(['success' => true, 'complete' => false, 'chunkIndex' => $chunkIndex]);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save chunk']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'No chunk received']);
    }
    exit;
}

// Handle Comment Submission (Visitors only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_comment' && !isAuthenticated()) {
    if (verifyCSRF()) {
        // Check rate limit
        if (isRateLimited('comment', RATE_LIMIT_COMMENTS)) {
            $message = 'Comment rate limit exceeded. Try again later.';
            $messageType = 'warning';
        } else {
            $name = trim($_POST['comment_name'] ?? 'Anonymous');
            $email = trim($_POST['comment_email'] ?? '');
            $commentMessage = trim($_POST['comment_message'] ?? '');

            if ($commentMessage) {
                $comments = file_exists(COMMENTS_FILE) ? json_decode(file_get_contents(COMMENTS_FILE), true) : [];
                if (!is_array($comments))
                    $comments = [];

                $comments[] = [
                    'name' => $name ?: 'Anonymous',
                    'email' => $email,
                    'message' => $commentMessage,
                    'date' => date('Y-m-d H:i:s'),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
                ];

                if (file_put_contents(COMMENTS_FILE, json_encode($comments, JSON_PRETTY_PRINT))) {
                    recordAction('comment');
                    $message = 'Comment submitted! Thank you.';
                    $messageType = 'success';
                } else {
                    $message = 'Failed to submit comment.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

// =============================================================================
// DIRECTORY LISTING
// =============================================================================

// Ensure uploads directory exists
if (!is_dir(BASE_DIR)) {
    @mkdir(BASE_DIR, 0755, true);
}

// Get current directory
$requestedPath = $_GET['dir'] ?? '';
$currentDir = sanitizePath($requestedPath) ?: BASE_DIR;

// Get directory contents
$items = [];
if (is_dir($currentDir)) {
    foreach (scandir($currentDir) as $file) {
        if ($file === '.' || ($file === '..' && $currentDir === BASE_DIR))
            continue;
        if (isHiddenFile($file) && !isAuthenticated())
            continue;

        $fullPath = $currentDir . DIRECTORY_SEPARATOR . $file;
        $items[] = [
            'name' => $file,
            'path' => str_replace(BASE_DIR . DIRECTORY_SEPARATOR, '', $fullPath),
            'isDir' => is_dir($fullPath),
            'size' => is_file($fullPath) ? filesize($fullPath) : 0,
            'modified' => filemtime($fullPath)
        ];
    }
    // Sort: directories first, then alphabetical
    usort($items, fn($a, $b) => $b['isDir'] <=> $a['isDir'] ?: strcasecmp($a['name'], $b['name']));
}

// Build breadcrumb navigation
$relativePath = trim(str_replace(BASE_DIR, '', $currentDir), DIRECTORY_SEPARATOR);
$breadcrumbs = [['name' => 'Home', 'path' => '']];
if ($relativePath) {
    $parts = explode(DIRECTORY_SEPARATOR, $relativePath);
    $accPath = '';
    foreach ($parts as $part) {
        $accPath .= ($accPath ? '/' : '') . $part;
        $breadcrumbs[] = ['name' => $part, 'path' => $accPath];
    }
}

// Check for README.md
$readmeFile = $currentDir . DIRECTORY_SEPARATOR . 'README.md';
$readmeContent = '';
if (file_exists($readmeFile)) {
    $parsedown = new Parsedown();
    $readmeContent = $parsedown->text(file_get_contents($readmeFile));
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= DEFAULT_THEME ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= htmlspecialchars(SITE_TITLE) ?></title>

    <!-- SEO Meta Tags -->
    <meta name="description"
        content="<?= htmlspecialchars(SITE_TITLE) ?> - A modern file directory browser with theme switching and admin management.">
    <meta name="keywords" content="file browser, directory listing, file manager, php directory lister">
    <meta name="author" content="OmniTx">
    <meta name="robots" content="index, follow">

    <!-- Open Graph / Social Media -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars(SITE_TITLE) ?>">
    <meta property="og:description"
        content="A modern file directory browser with theme switching and admin management.">
    <meta property="og:site_name" content="<?= htmlspecialchars(SITE_TITLE) ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= htmlspecialchars(SITE_TITLE) ?>">
    <meta name="twitter:description"
        content="A modern file directory browser with theme switching and admin management.">

    <!-- Theme Color -->
    <meta name="theme-color" content="#00FFC8">
    <meta name="msapplication-TileColor" content="#0B1215">

    <!-- Favicon (inline SVG) -->
    <link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2300ffc8' class='bi bi-hdd-network' viewBox='0 0 16 16'%3E%3Cpath d='M4.5 5a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1zM3 4.5a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0z'/%3E%3Cpath d='M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1a2 2 0 0 1-2 2H8.5v3a1.5 1.5 0 0 1 1.5 1.5h5.5a.5.5 0 0 1 0 1H10A1.5 1.5 0 0 1 8.5 14h-1A1.5 1.5 0 0 1 6 12.5H.5a.5.5 0 0 1 0-1H6A1.5 1.5 0 0 1 7.5 10V7H2a2 2 0 0 1-2-2V4zm1 0v1a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H2a1 1 0 0 0-1 1zm6 7.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5z'/%3E%3C/svg%3E">

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

        [data-bs-theme="light"] .card-body {
            color: #212529;
        }

        [data-bs-theme="light"] .card-body h1,
        [data-bs-theme="light"] .card-body h2,
        [data-bs-theme="light"] .card-body h3,
        [data-bs-theme="light"] .card-body h4,
        [data-bs-theme="light"] .card-body h5,
        [data-bs-theme="light"] .card-body h6 {
            color: #0d6efd !important;
        }

        [data-bs-theme="light"] .card-body p,
        [data-bs-theme="light"] .card-body li {
            color: #212529 !important;
        }

        [data-bs-theme="light"] .card-body code {
            background: #e9ecef !important;
            color: #d63384 !important;
        }

        [data-bs-theme="light"] .card-body a {
            color: #0d6efd !important;
        }

        .file-icon {
            width: 20px;
        }

        /* Modal backdrop blur effect */
        .modal-backdrop {
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            background-color: rgba(0, 0, 0, 0.4);
        }
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg sticky-top shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
                <i class="bi bi-folder-fill text-info fs-4"></i>
                <span class="fw-bold glow"><?= htmlspecialchars(SITE_TITLE) ?></span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <?php if (SHOW_THEME_TOGGLE): ?>
                    <button class="btn btn-sm btn-outline-secondary" onclick="toggleTheme()" title="Toggle Theme">
                        <i class="bi bi-circle-half"></i>
                    </button>
                <?php endif; ?>
                <?php if (isAuthenticated()): ?>
                    <a href="admin.php" class="btn btn-sm btn-outline-info"><i class="bi bi-gear"></i> Admin</a>
                    <a href="?action=logout" class="btn btn-sm btn-outline-danger"><i class="bi bi-box-arrow-right"></i></a>
                <?php else: ?>
                    <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#loginModal">
                        <i class="bi bi-lock"></i>
                    </button>
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

        <!-- README (Top Position) -->
        <?php if (README_POSITION === 'top' && $readmeContent): ?>
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-file-text text-info"></i>
                    <span class="fw-semibold">README.md</span>
                </div>
                <div class="card-body"><?= $readmeContent ?></div>
            </div>
        <?php endif; ?>

        <!-- File Listing -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <!-- Breadcrumb Navigation -->
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb mb-0">
                        <?php foreach ($breadcrumbs as $crumb): ?>
                            <li class="breadcrumb-item">
                                <a href="?dir=<?= safeUrlEncode($crumb['path']) ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($crumb['name']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>

                <!-- Upload Form (Admin only) -->
                <?php if (isAuthenticated() && SHOW_UPLOAD): ?>
                    <!-- Original Quick Upload -->
                    <form method="POST" enctype="multipart/form-data" class="d-flex gap-2">
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="dir" value="<?= htmlspecialchars($relativePath) ?>">
                        <input type="file" name="file" required class="form-control form-control-sm"
                            style="max-width: 200px;">
                        <button type="submit" class="btn btn-sm btn-info"><i class="bi bi-upload"></i></button>
                    </form>

                    <!-- Pretty Upload Button -->
                    <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal"
                        data-bs-target="#prettyUploadModal">
                        <i class="bi bi-cloud-arrow-up"></i> Upload Large File
                    </button>
                <?php endif; ?>
            </div>

            <!-- File Table -->
            <?php if (empty($items)): ?>
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-folder2-open display-1"></i>
                    <p class="mt-3">This folder is empty</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th class="d-none d-sm-table-cell">Size</th>
                                <th class="d-none d-md-table-cell">Modified</th>
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
                                            <i class="bi <?= getFileIcon($item['name']) ?> text-secondary me-2"></i>
                                            <a href="?action=view&file=<?= safeUrlEncode($item['path']) ?>"
                                                class="text-decoration-none" target="_blank">
                                                <?= htmlspecialchars($item['name']) ?>
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted d-none d-sm-table-cell">
                                        <?= $item['isDir'] ? '—' : formatBytes($item['size']) ?>
                                    </td>
                                    <td class="text-muted d-none d-md-table-cell">
                                        <?= date('M d, Y H:i', $item['modified']) ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($item['isDir'] && $item['name'] !== '..' && SHOW_DOWNLOAD): ?>
                                            <a href="?action=download_folder&folder=<?= safeUrlEncode($item['path']) ?>"
                                                class="btn btn-sm btn-outline-info" title="Download as ZIP">
                                                <i class="bi bi-file-zip"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (!$item['isDir'] && $item['name'] !== '..' && SHOW_DOWNLOAD): ?>
                                            <a href="?action=download&file=<?= safeUrlEncode($item['path']) ?>"
                                                class="btn btn-sm btn-outline-secondary">
                                                Download
                                            </a>
                                        <?php endif; ?>
                                        <?php if (isAuthenticated() && $item['name'] !== '..'): ?>
                                            <?php if (SHOW_RENAME): ?>
                                                <button class="btn btn-sm btn-outline-secondary"
                                                    onclick="showRename('<?= htmlspecialchars(addslashes($item['path'])) ?>', '<?= htmlspecialchars(addslashes($item['name'])) ?>')"
                                                    title="Rename">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                            <?php if (SHOW_DELETE): ?>
                                                <button class="btn btn-sm btn-outline-danger"
                                                    onclick="showDelete('<?= htmlspecialchars(addslashes($item['path'])) ?>', '<?= htmlspecialchars(addslashes($item['name'])) ?>')"
                                                    title="Delete">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- README (Bottom Position) -->
        <?php if (README_POSITION === 'bottom' && $readmeContent): ?>
            <div class="card mt-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-file-text text-info"></i>
                    <span class="fw-semibold">README.md</span>
                </div>
                <div class="card-body"><?= $readmeContent ?></div>
            </div>
        <?php endif; ?>

        <!-- Comment Form (Visitors only) -->
        <?php if (SHOW_COMMENTS && !isAuthenticated()): ?>
            <div class="card mt-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-chat-dots text-info"></i>
                    <span class="fw-semibold">Leave a Comment</span>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="submit_comment">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Name</label>
                                <input type="text" name="comment_name" class="form-control"
                                    placeholder="Your name (optional)">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="comment_email" class="form-control"
                                    placeholder="Your email (optional)">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Message <span class="text-danger">*</span></label>
                            <textarea name="comment_message" class="form-control" rows="4"
                                placeholder="Write your comment here..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-info"><i class="bi bi-send me-1"></i>Submit</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Login Modal -->
    <div class="modal fade" id="loginModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-lock me-2"></i>Admin Login</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="login">
                        <input type="password" name="password" class="form-control" placeholder="Password" required
                            autofocus>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-info">Login</button>
                    </div>
                </form>
            </div>
        </div>
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

        // Load saved theme on page load
        (function () {
            const saved = localStorage.getItem('neonindex_theme');
            if (saved) document.documentElement.setAttribute('data-bs-theme', saved);
        })();
    </script>

    <!-- Pretty Upload Modal -->
    <?php if (isAuthenticated() && SHOW_UPLOAD): ?>
        <div class="modal fade" id="prettyUploadModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-cloud-arrow-up me-2"></i>Upload Files</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="uploadDropZone" class="border border-2 border-dashed rounded-3 p-4 text-center mb-3"
                            style="cursor: pointer; transition: all 0.3s;">
                            <i class="bi bi-cloud-upload display-4 text-muted"></i>
                            <p class="mt-2 mb-2 text-muted">Drag & drop files/folders here or click to browse</p>
                            <div class="d-flex gap-2 justify-content-center flex-wrap">
                                <input type="file" id="prettyFileInput" class="d-none" multiple>
                                <input type="file" id="prettyFolderInput" class="d-none" webkitdirectory>
                                <button type="button" class="btn btn-outline-primary btn-sm" id="btnSelectFiles">
                                    <i class="bi bi-files me-1"></i>Select Files
                                </button>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="btnSelectFolder">
                                    <i class="bi bi-folder me-1"></i>Select Folder
                                </button>
                            </div>
                        </div>

                        <div id="uploadProgress" class="d-none">
                            <div id="uploadFilesList" class="mb-3" style="max-height: 200px; overflow-y: auto;"></div>
                            <div class="d-flex justify-content-between mb-1">
                                <span id="uploadOverallStatus">Uploading...</span>
                                <span id="uploadPercent">0%</span>
                            </div>
                            <div class="progress" style="height: 25px;">
                                <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                                    role="progressbar" style="width: 0%"></div>
                            </div>
                        </div>

                        <div id="uploadResult" class="d-none">
                            <div class="alert mb-0" id="uploadResultAlert"></div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <script>
            (function () {
                const dropZone = document.getElementById('uploadDropZone');
                const fileInput = document.getElementById('prettyFileInput');
                const folderInput = document.getElementById('prettyFolderInput');
                const progressDiv = document.getElementById('uploadProgress');
                const resultDiv = document.getElementById('uploadResult');
                const progressBar = document.getElementById('uploadProgressBar');
                const percentText = document.getElementById('uploadPercent');
                const filesList = document.getElementById('uploadFilesList');
                const overallStatus = document.getElementById('uploadOverallStatus');
                const resultAlert = document.getElementById('uploadResultAlert');

                const CHUNK_SIZE = <?= CHUNK_SIZE_MB ?> * 1024 * 1024; // Configurable chunk size
                const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
                const CURRENT_DIR = '<?= htmlspecialchars($relativePath) ?>';

                // Button events
                document.getElementById('btnSelectFiles').addEventListener('click', (e) => {
                    e.stopPropagation();
                    fileInput.click();
                });
                document.getElementById('btnSelectFolder').addEventListener('click', (e) => {
                    e.stopPropagation();
                    folderInput.click();
                });

                // Drop zone drag events
                dropZone.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    dropZone.classList.add('border-primary', 'bg-primary', 'bg-opacity-10');
                });
                dropZone.addEventListener('dragleave', () => {
                    dropZone.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');
                });
                dropZone.addEventListener('drop', async (e) => {
                    e.preventDefault();
                    dropZone.classList.remove('border-primary', 'bg-primary', 'bg-opacity-10');

                    // Use DataTransferItemList for folder support
                    const items = e.dataTransfer.items;
                    if (items && items.length) {
                        const files = await getAllFilesFromDrop(items);
                        if (files.length) handleFiles(files);
                    }
                });

                // Recursively get all files from dropped items (supports folders)
                async function getAllFilesFromDrop(items) {
                    const files = [];

                    async function traverseEntry(entry, path = '') {
                        if (entry.isFile) {
                            return new Promise(resolve => {
                                entry.file(file => {
                                    // Create a new file object with the relative path
                                    Object.defineProperty(file, 'webkitRelativePath', {
                                        value: path + file.name,
                                        writable: false
                                    });
                                    files.push(file);
                                    resolve();
                                });
                            });
                        } else if (entry.isDirectory) {
                            const reader = entry.createReader();
                            return new Promise(resolve => {
                                reader.readEntries(async entries => {
                                    for (const subEntry of entries) {
                                        await traverseEntry(subEntry, path + entry.name + '/');
                                    }
                                    resolve();
                                });
                            });
                        }
                    }

                    for (let i = 0; i < items.length; i++) {
                        const entry = items[i].webkitGetAsEntry();
                        if (entry) {
                            await traverseEntry(entry);
                        }
                    }

                    return files;
                }

                // File/folder input events
                fileInput.addEventListener('change', () => {
                    if (fileInput.files.length) handleFiles(Array.from(fileInput.files));
                });
                folderInput.addEventListener('change', () => {
                    if (folderInput.files.length) handleFiles(Array.from(folderInput.files));
                });

                function handleFiles(files) {
                    if (!files.length) return;

                    dropZone.classList.add('d-none');
                    progressDiv.classList.remove('d-none');
                    resultDiv.classList.add('d-none');

                    // Show file list
                    filesList.innerHTML = files.map((f, i) =>
                        `<div class="d-flex justify-content-between small py-1 border-bottom" id="file-${i}">
                            <span class="text-truncate" style="max-width: 70%;">${f.webkitRelativePath || f.name}</span>
                            <span class="text-muted" id="file-status-${i}">Pending</span>
                        </div>`
                    ).join('');

                    uploadAllFiles(files);
                }

                async function uploadAllFiles(files) {
                    let completed = 0;
                    let failed = 0;

                    for (let i = 0; i < files.length; i++) {
                        const file = files[i];
                        const statusEl = document.getElementById(`file-status-${i}`);
                        const rowEl = document.getElementById(`file-${i}`);

                        statusEl.textContent = 'Uploading...';
                        statusEl.className = 'text-primary';

                        const success = await uploadChunked(file, (percent) => {
                            statusEl.textContent = percent + '%';
                            // Update overall progress
                            const overall = Math.round(((completed + percent / 100) / files.length) * 100);
                            progressBar.style.width = overall + '%';
                            percentText.textContent = overall + '%';
                            overallStatus.textContent = `Uploading ${i + 1} of ${files.length}: ${file.name}`;
                        });

                        if (success) {
                            completed++;
                            statusEl.textContent = '✓ Done';
                            statusEl.className = 'text-success';
                        } else {
                            failed++;
                            statusEl.textContent = '✗ Failed';
                            statusEl.className = 'text-danger';
                        }
                    }

                    // Final result
                    if (failed === 0) {
                        showResult(true, `All ${completed} file(s) uploaded successfully!`);
                    } else {
                        showResult(false, `${completed} uploaded, ${failed} failed`);
                    }
                }

                async function uploadChunked(file, onProgress) {
                    const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
                    const relativePath = file.webkitRelativePath || file.name;

                    for (let i = 0; i < totalChunks; i++) {
                        const start = i * CHUNK_SIZE;
                        const end = Math.min(start + CHUNK_SIZE, file.size);
                        const chunk = file.slice(start, end);

                        const formData = new FormData();
                        formData.append('action', 'chunked_upload');
                        formData.append('csrf_token', CSRF_TOKEN);
                        formData.append('fileName', relativePath);
                        formData.append('chunkIndex', i);
                        formData.append('totalChunks', totalChunks);
                        formData.append('dir', CURRENT_DIR);
                        formData.append('chunk', chunk);

                        try {
                            const response = await fetch(window.location.pathname, {
                                method: 'POST',
                                body: formData
                            });

                            const result = await response.json();

                            if (!result.success) {
                                console.error('Chunk failed:', result.error);
                                return false;
                            }

                            onProgress(Math.round(((i + 1) / totalChunks) * 100));
                        } catch (error) {
                            console.error('Network error:', error);
                            return false;
                        }
                    }
                    return true;
                }

                function showResult(success, message) {
                    progressDiv.classList.add('d-none');
                    resultDiv.classList.remove('d-none');
                    resultAlert.className = 'alert mb-0 alert-' + (success ? 'success' : 'danger');
                    resultAlert.innerHTML = (success ? '<i class="bi bi-check-circle me-2"></i>' : '<i class="bi bi-x-circle me-2"></i>') + message;

                    if (success) {
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        setTimeout(() => {
                            dropZone.classList.remove('d-none');
                            resultDiv.classList.add('d-none');
                            progressBar.style.width = '0%';
                            percentText.textContent = '0%';
                        }, 3000);
                    }
                }

                // Reset modal on close
                document.getElementById('prettyUploadModal').addEventListener('hidden.bs.modal', () => {
                    dropZone.classList.remove('d-none');
                    progressDiv.classList.add('d-none');
                    resultDiv.classList.add('d-none');
                    progressBar.style.width = '0%';
                    percentText.textContent = '0%';
                    fileInput.value = '';
                    folderInput.value = '';
                    filesList.innerHTML = '';
                });
            })();
        </script>
    <?php endif; ?>
</body>

</html>