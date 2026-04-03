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
    // Sanitize file name to prevent log injection (strip newlines and delimiter)
    $safeFile = str_replace(["\n", "\r", "|"], '', $file);
    $log = "[{$time}] IP: {$ip} | File: {$safeFile} | UA: {$userAgent}\n";

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

        // Escape any HTML in the line to prevent XSS before applying inline formatting
        $line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
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
    // Normalize path separators and remove traversal segments
    $fileName = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $fileName);
    $segments = array_filter(explode(DIRECTORY_SEPARATOR, $fileName), function ($seg) {
        return $seg !== '' && $seg !== '.' && $seg !== '..';
    });
    $fileName = implode(DIRECTORY_SEPARATOR, $segments);
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
    <title><?= htmlspecialchars(SITE_TITLE) ?></title>
    <meta name="description" content="<?= htmlspecialchars(SITE_TITLE) ?> - A modern file directory browser.">
    <meta name="keywords" content="file browser, directory listing, file manager">
    <meta name="author" content="OmniTx">
    <meta name="robots" content="index, follow">
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars(SITE_TITLE) ?>">
    <meta property="og:description" content="A modern file directory browser with theme switching and admin management.">
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
        body{font-family:var(--font);min-height:100vh}

        /* DARK */
        [data-bs-theme="dark"]{--bg:#0c0e16;--sf:rgba(18,21,32,.88);--tx:#e4e6ed;--mu:rgba(255,255,255,.4);--bd:rgba(255,255,255,.07);background:var(--bg);color:var(--tx)}
        [data-bs-theme="dark"] .topbar{background:rgba(12,14,22,.75);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid var(--bd)}
        [data-bs-theme="dark"] .card{background:var(--sf);border:1px solid var(--bd);box-shadow:0 4px 20px rgba(0,0,0,.2)}
        [data-bs-theme="dark"] .form-control,[data-bs-theme="dark"] .form-select{background:rgba(0,0,0,.2);border-color:var(--bd);color:#fff}
        [data-bs-theme="dark"] .form-control:focus,[data-bs-theme="dark"] .form-select:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--glow);background:rgba(0,0,0,.35)}
        [data-bs-theme="dark"] .table{--bs-table-bg:transparent;color:var(--tx)}
        [data-bs-theme="dark"] .modal-content{background:var(--sf);border:1px solid var(--bd);border-radius:16px;backdrop-filter:blur(20px)}
        [data-bs-theme="dark"] .modal-header,[data-bs-theme="dark"] .modal-footer{border-color:var(--bd)}
        [data-bs-theme="dark"] .breadcrumb-item a{color:var(--accent)}
        [data-bs-theme="dark"] .breadcrumb-item.active{color:var(--mu)}
        [data-bs-theme="dark"] .breadcrumb-item+.breadcrumb-item::before{color:var(--mu)}

        /* LIGHT */
        [data-bs-theme="light"]{--bg:#f4f5f7;--sf:#fff;--tx:#1a1d26;--mu:rgba(0,0,0,.45);--bd:rgba(0,0,0,.08);background:var(--bg);color:var(--tx)}
        [data-bs-theme="light"] .topbar{background:rgba(255,255,255,.85);backdrop-filter:blur(16px);border-bottom:1px solid var(--bd)}
        [data-bs-theme="light"] .card{background:var(--sf);border:1px solid var(--bd);box-shadow:0 1px 8px rgba(0,0,0,.05)}
        [data-bs-theme="light"] .glow{color:var(--accent)!important;text-shadow:none}
        [data-bs-theme="light"] .breadcrumb-item a{color:var(--accent)}
        [data-bs-theme="light"] .card-body h1,[data-bs-theme="light"] .card-body h2,[data-bs-theme="light"] .card-body h3,[data-bs-theme="light"] .card-body h4,[data-bs-theme="light"] .card-body h5,[data-bs-theme="light"] .card-body h6{color:var(--accent)!important}
        [data-bs-theme="light"] .card-body code{background:#e9ecef!important;color:#d63384!important}

        /* TOPBAR */
        .topbar{position:sticky;top:0;z-index:1030;padding:.7rem 0;display:flex;align-items:center;justify-content:space-between}
        .brand{display:flex;align-items:center;gap:.5rem;text-decoration:none;font-weight:700;font-size:1.1rem;color:var(--accent)}
        .glow{color:var(--accent)!important;text-shadow:0 0 16px var(--glow)}
        [data-bs-theme="light"] .glow{text-shadow:none}

        /* CARDS */
        .card{border-radius:var(--r);overflow:hidden;transition:box-shadow .2s var(--ease)}
        .card-header{font-weight:600;padding:.9rem 1.15rem;background:transparent;border-bottom:1px solid var(--bd);display:flex;align-items:center;gap:.4rem;font-size:.9rem}

        /* BUTTONS */
        .btn-accent{background:linear-gradient(135deg,#FF9D00,#e06800);border:none;color:#fff!important;font-weight:600;border-radius:var(--r);padding:.5rem 1.3rem;box-shadow:0 3px 10px var(--glow);transition:all .2s var(--ease)}
        .btn-accent:hover{transform:translateY(-1px);box-shadow:0 5px 18px var(--glow);background:linear-gradient(135deg,#ffaa20,#ff7a00)}
        .btn-ghost{background:transparent;border:1px solid var(--bd);color:var(--tx);border-radius:10px;padding:.35rem .75rem;font-size:.8rem;transition:all .15s}
        .btn-ghost:hover{border-color:var(--accent);color:var(--accent)}

        /* FORMS */
        .form-control,.form-select{border-radius:10px;padding:.55rem .8rem;font-size:.875rem}
        .form-label{font-weight:500;font-size:.8rem;margin-bottom:.3rem;color:var(--mu)}

        /* TABLE */
        .table{margin:0;vertical-align:middle;--bs-table-bg:transparent}
        .table th{text-transform:uppercase;font-size:.7rem;letter-spacing:.07em;font-weight:600;color:var(--mu);padding:.75rem 1rem;border-bottom:1px solid var(--bd)}
        .table td{padding:.65rem 1rem;border-bottom:1px solid var(--bd);font-size:.875rem}
        .table-hover tbody tr{transition:background .15s}
        [data-bs-theme="dark"] .table-hover tbody tr:hover{background:rgba(255,157,0,.04)}

        /* BADGES & MISC */
        .badge{font-weight:500;padding:.3em .6em;border-radius:6px;font-size:.72rem}
        .alert{border-radius:var(--r);border:none;font-size:.875rem}
        .text-accent{color:var(--accent)!important}
        .empty-state{padding:3rem 1rem;text-align:center;color:var(--mu)}
        .empty-state i{font-size:3rem;margin-bottom:.75rem;display:block;opacity:.4}

        /* BREADCRUMB */
        .breadcrumb{margin:0;font-size:.85rem}
        .breadcrumb-item a{text-decoration:none;font-weight:500}
        .breadcrumb-item a:hover{color:var(--accent)!important;text-decoration:underline}

        /* UPLOAD DROPZONE */
        .dropzone{border:2px dashed var(--bd);border-radius:var(--r);padding:2rem;text-align:center;cursor:pointer;transition:all .25s var(--ease)}
        .dropzone:hover,.dropzone.active{border-color:var(--accent);background:rgba(255,157,0,.04)}
        .dropzone i{font-size:2.5rem;color:var(--mu);margin-bottom:.5rem;display:block}

        /* ANIMATION */
        @keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
        .fade-up{animation:fadeUp .3s var(--ease)}

        /* FILE ACTIONS on mobile */
        @media(max-width:576px){
            .file-actions .btn-ghost{padding:.25rem .5rem;font-size:.7rem}
            .file-actions .btn-ghost span{display:none}
        }
    </style>
</head>
<body>
    <!-- Topbar -->
    <div class="topbar">
        <div class="container d-flex align-items-center justify-content-between">
            <a class="brand" href="index.php">
                <i class="bi bi-folder-fill"></i>
                <span class="glow"><?= htmlspecialchars(SITE_TITLE) ?></span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <?php if (SHOW_THEME_TOGGLE): ?>
                    <button class="btn-ghost" onclick="toggleTheme()" title="Toggle Theme"><i class="bi bi-circle-half"></i></button>
                <?php endif; ?>
                <?php if (isAuthenticated()): ?>
                    <a href="admin.php" class="btn-ghost"><i class="bi bi-gear me-1"></i><span>Admin</span></a>
                    <a href="?action=logout" class="btn-ghost text-danger"><i class="bi bi-box-arrow-right"></i></a>
                <?php else: ?>
                    <button class="btn-ghost" data-bs-toggle="modal" data-bs-target="#loginModal"><i class="bi bi-lock"></i></button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="container py-4 fade-up">
        <!-- Alerts -->
        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show mb-3">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- README (Top) -->
        <?php if (README_POSITION === 'top' && $readmeContent): ?>
            <div class="card mb-4">
                <div class="card-header"><i class="bi bi-file-text text-accent me-2"></i>README.md</div>
                <div class="card-body"><?= $readmeContent ?></div>
            </div>
        <?php endif; ?>

        <!-- File Listing -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <?php foreach ($breadcrumbs as $crumb): ?>
                            <li class="breadcrumb-item">
                                <a href="?dir=<?= safeUrlEncode($crumb['path']) ?>"><?= htmlspecialchars($crumb['name']) ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
                <?php if (isAuthenticated() && SHOW_UPLOAD): ?>
                    <button type="button" class="btn-accent btn-sm py-1 px-3" data-bs-toggle="modal" data-bs-target="#prettyUploadModal">
                        <i class="bi bi-cloud-arrow-up me-1"></i>Upload
                    </button>
                <?php endif; ?>
            </div>

            <?php if (empty($items)): ?>
                <div class="empty-state"><i class="bi bi-folder2-open"></i>This folder is empty</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead><tr>
                            <th>Name</th>
                            <th class="d-none d-sm-table-cell">Size</th>
                            <th class="d-none d-md-table-cell">Modified</th>
                            <th class="text-end">Actions</th>
                        </tr></thead>
                        <tbody>
                            <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <?php if ($item['isDir']): ?>
                                        <i class="bi bi-folder-fill text-warning me-1"></i>
                                        <a href="?dir=<?= safeUrlEncode($item['path']) ?>" class="text-decoration-none"><?= htmlspecialchars($item['name']) ?></a>
                                    <?php else: ?>
                                        <i class="bi <?= getFileIcon($item['name']) ?> me-1" style="color:var(--mu)"></i>
                                        <a href="?action=view&file=<?= safeUrlEncode($item['path']) ?>" class="text-decoration-none" target="_blank"><?= htmlspecialchars($item['name']) ?></a>
                                    <?php endif; ?>
                                </td>
                                <td class="d-none d-sm-table-cell" style="color:var(--mu)"><?= $item['isDir'] ? '—' : formatBytes($item['size']) ?></td>
                                <td class="d-none d-md-table-cell" style="color:var(--mu)"><?= date('M d, Y H:i', $item['modified']) ?></td>
                                <td class="text-end file-actions text-nowrap">
                                    <?php if ($item['isDir'] && $item['name'] !== '..' && SHOW_DOWNLOAD): ?>
                                        <a href="?action=download_folder&folder=<?= safeUrlEncode($item['path']) ?>" class="btn-ghost py-1 px-2" title="Download ZIP"><i class="bi bi-file-zip"></i></a>
                                    <?php endif; ?>
                                    <?php if (!$item['isDir'] && $item['name'] !== '..' && SHOW_DOWNLOAD): ?>
                                        <a href="uploads/<?= htmlspecialchars(safeUrlEncode($item['path'])) ?>" download class="btn-ghost py-1 px-2"><i class="bi bi-download me-1"></i><span>Download</span></a>
                                    <?php endif; ?>
                                    <?php if (isAuthenticated() && $item['name'] !== '..'): ?>
                                        <?php if (SHOW_RENAME): ?>
                                            <button class="btn-ghost py-1 px-2" onclick="showRename('<?= htmlspecialchars(addslashes($item['path'])) ?>','<?= htmlspecialchars(addslashes($item['name'])) ?>')" title="Rename"><i class="bi bi-pencil"></i></button>
                                        <?php endif; ?>
                                        <?php if (SHOW_DELETE): ?>
                                            <button class="btn-ghost py-1 px-2 text-danger" onclick="showDelete('<?= htmlspecialchars(addslashes($item['path'])) ?>','<?= htmlspecialchars(addslashes($item['name'])) ?>')" title="Delete"><i class="bi bi-trash3"></i></button>
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

        <!-- README (Bottom) -->
        <?php if (README_POSITION === 'bottom' && $readmeContent): ?>
            <div class="card mt-4">
                <div class="card-header"><i class="bi bi-file-text text-accent me-2"></i>README.md</div>
                <div class="card-body"><?= $readmeContent ?></div>
            </div>
        <?php endif; ?>

        <!-- Comment Form (Visitors only) -->
        <?php if (SHOW_COMMENTS && !isAuthenticated()): ?>
            <div class="card mt-4">
                <div class="card-header"><i class="bi bi-chat-dots text-accent me-2"></i>Leave a Comment</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="submit_comment">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Name</label><input type="text" name="comment_name" class="form-control" placeholder="Your name (optional)"></div>
                            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="comment_email" class="form-control" placeholder="Your email (optional)"></div>
                        </div>
                        <div class="mt-3"><label class="form-label">Message <span class="text-danger">*</span></label><textarea name="comment_message" class="form-control" rows="4" placeholder="Write your comment here..." required></textarea></div>
                        <button type="submit" class="btn-accent mt-3"><i class="bi bi-send me-1"></i>Submit</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Login Modal -->
    <div class="modal fade" id="loginModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-lock me-2"></i>Admin Login</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="action" value="login"><input type="password" name="password" class="form-control" placeholder="Password" required autofocus></div><div class="modal-footer"><button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-accent">Login</button></div></form></div></div></div>

    <!-- Rename Modal -->
    <div class="modal fade" id="renameModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Rename</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="action" value="rename"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="file" id="renameFile"><label class="form-label">New name</label><input type="text" name="newname" id="renameName" class="form-control" required></div><div class="modal-footer"><button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-accent">Rename</button></div></form></div></div></div>

    <!-- Delete Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title text-danger"><i class="bi bi-trash3 me-2"></i>Delete</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="action" value="delete"><input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>"><input type="hidden" name="file" id="deleteFile"><p>Are you sure you want to delete <strong id="deleteFileName"></strong>?</p></div><div class="modal-footer"><button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn-accent" style="background:linear-gradient(135deg,#dc3545,#a71d2a)">Delete</button></div></form></div></div></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleTheme(){const h=document.documentElement;const n=h.getAttribute('data-bs-theme')==='dark'?'light':'dark';h.setAttribute('data-bs-theme',n);localStorage.setItem('neonindex_theme',n)}
        function showRename(p,n){document.getElementById('renameFile').value=p;document.getElementById('renameName').value=n;new bootstrap.Modal(document.getElementById('renameModal')).show()}
        function showDelete(p,n){document.getElementById('deleteFile').value=p;document.getElementById('deleteFileName').textContent=n;new bootstrap.Modal(document.getElementById('deleteModal')).show()}
        (function(){const s=localStorage.getItem('neonindex_theme');if(s)document.documentElement.setAttribute('data-bs-theme',s)})();
    </script>

    <!-- Upload Modal (Admin only) -->
    <?php if (isAuthenticated() && SHOW_UPLOAD): ?>
    <div class="modal fade" id="prettyUploadModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-cloud-arrow-up me-2"></i>Upload Files</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div id="uploadDropZone" class="dropzone" onclick="document.getElementById('prettyFileInput').click()">
                        <i class="bi bi-cloud-upload"></i>
                        <p style="color:var(--mu);margin:0 0 .75rem">Drag & drop files/folders here or click to browse</p>
                        <div class="d-flex gap-2 justify-content-center flex-wrap">
                            <input type="file" id="prettyFileInput" class="d-none" multiple>
                            <input type="file" id="prettyFolderInput" class="d-none" webkitdirectory>
                            <button type="button" class="btn-ghost" id="btnSelectFiles" onclick="event.stopPropagation();document.getElementById('prettyFileInput').click()"><i class="bi bi-files me-1"></i>Files</button>
                            <button type="button" class="btn-ghost" id="btnSelectFolder" onclick="event.stopPropagation();document.getElementById('prettyFolderInput').click()"><i class="bi bi-folder me-1"></i>Folder</button>
                        </div>
                    </div>
                    <div id="uploadProgress" class="d-none">
                        <div id="uploadFilesList" class="mb-3" style="max-height:200px;overflow-y:auto"></div>
                        <div class="d-flex justify-content-between mb-1"><span id="uploadOverallStatus">Uploading...</span><span id="uploadPercent">0%</span></div>
                        <div class="progress" style="height:22px;border-radius:11px;overflow:hidden"><div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%;background:linear-gradient(135deg,#FF9D00,#e06800)"></div></div>
                    </div>
                    <div id="uploadResult" class="d-none"><div class="alert mb-0" id="uploadResultAlert"></div></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn-ghost" data-bs-dismiss="modal">Close</button></div>
            </div>
        </div>
    </div>
    <script>
    (function(){
        const dropZone=document.getElementById('uploadDropZone'),fileInput=document.getElementById('prettyFileInput'),folderInput=document.getElementById('prettyFolderInput'),progressDiv=document.getElementById('uploadProgress'),resultDiv=document.getElementById('uploadResult'),progressBar=document.getElementById('uploadProgressBar'),percentText=document.getElementById('uploadPercent'),filesList=document.getElementById('uploadFilesList'),overallStatus=document.getElementById('uploadOverallStatus'),resultAlert=document.getElementById('uploadResultAlert');
        const CHUNK_SIZE=<?= CHUNK_SIZE_MB ?>*1024*1024,CSRF='<?= $_SESSION['csrf_token'] ?>',DIR='<?= htmlspecialchars($relativePath) ?>';

        dropZone.addEventListener('dragover',e=>{e.preventDefault();dropZone.classList.add('active')});
        dropZone.addEventListener('dragleave',()=>dropZone.classList.remove('active'));
        dropZone.addEventListener('drop',async e=>{e.preventDefault();dropZone.classList.remove('active');const items=e.dataTransfer.items;if(items&&items.length){const files=await getAllFilesFromDrop(items);if(files.length)handleFiles(files)}});

        async function getAllFilesFromDrop(items){
            const files=[];
            async function traverse(entry,path=''){
                if(entry.isFile){return new Promise(r=>entry.file(f=>{Object.defineProperty(f,'webkitRelativePath',{value:path+f.name,writable:false});files.push(f);r()}))}
                else if(entry.isDirectory){const reader=entry.createReader();return new Promise(r=>reader.readEntries(async entries=>{for(const e of entries)await traverse(e,path+entry.name+'/');r()}))}
            }
            for(let i=0;i<items.length;i++){const entry=items[i].webkitGetAsEntry();if(entry)await traverse(entry)}
            return files;
        }

        fileInput.addEventListener('change',()=>{if(fileInput.files.length)handleFiles(Array.from(fileInput.files))});
        folderInput.addEventListener('change',()=>{if(folderInput.files.length)handleFiles(Array.from(folderInput.files))});

        function handleFiles(files){
            if(!files.length)return;
            dropZone.classList.add('d-none');progressDiv.classList.remove('d-none');resultDiv.classList.add('d-none');
            filesList.innerHTML=files.map((f,i)=>`<div class="d-flex justify-content-between small py-1" style="border-bottom:1px solid var(--bd)" id="file-${i}"><span class="text-truncate" style="max-width:70%">${f.webkitRelativePath||f.name}</span><span style="color:var(--mu)" id="file-status-${i}">Pending</span></div>`).join('');
            uploadAll(files);
        }

        async function uploadAll(files){
            let done=0,fail=0;
            for(let i=0;i<files.length;i++){
                const f=files[i],st=document.getElementById('file-status-'+i);
                st.textContent='Uploading...';st.style.color='var(--accent)';
                const ok=await uploadChunked(f,p=>{st.textContent=p+'%';const o=Math.round(((done+p/100)/files.length)*100);progressBar.style.width=o+'%';percentText.textContent=o+'%';overallStatus.textContent='Uploading '+(i+1)+' of '+files.length+': '+f.name});
                if(ok){done++;st.textContent='✓ Done';st.style.color='#22c55e'}else{fail++;st.textContent='✗ Failed';st.style.color='#ef4444'}
            }
            if(fail===0)showResult(true,'All '+done+' file(s) uploaded!');else showResult(false,done+' uploaded, '+fail+' failed');
        }

        async function uploadChunked(file,onProgress){
            const total=Math.ceil(file.size/CHUNK_SIZE),rel=file.webkitRelativePath||file.name;
            for(let i=0;i<total;i++){
                const fd=new FormData();fd.append('action','chunked_upload');fd.append('csrf_token',CSRF);fd.append('fileName',rel);fd.append('chunkIndex',i);fd.append('totalChunks',total);fd.append('dir',DIR);fd.append('chunk',file.slice(i*CHUNK_SIZE,Math.min((i+1)*CHUNK_SIZE,file.size)));
                try{const r=await(await fetch(window.location.pathname,{method:'POST',body:fd})).json();if(!r.success)return false;onProgress(Math.round(((i+1)/total)*100))}catch(e){return false}
            }
            return true;
        }

        function showResult(ok,msg){
            progressDiv.classList.add('d-none');resultDiv.classList.remove('d-none');
            resultAlert.className='alert mb-0 alert-'+(ok?'success':'danger');
            resultAlert.innerHTML=(ok?'<i class="bi bi-check-circle me-2"></i>':'<i class="bi bi-x-circle me-2"></i>')+msg;
            if(ok)setTimeout(()=>location.reload(),1500);else setTimeout(()=>{dropZone.classList.remove('d-none');resultDiv.classList.add('d-none');progressBar.style.width='0%';percentText.textContent='0%'},3000);
        }

        document.getElementById('prettyUploadModal').addEventListener('hidden.bs.modal',()=>{dropZone.classList.remove('d-none');progressDiv.classList.add('d-none');resultDiv.classList.add('d-none');progressBar.style.width='0%';percentText.textContent='0%';fileInput.value='';folderInput.value='';filesList.innerHTML=''});
    })();
    </script>
    <?php endif; ?>
</body>
</html>
