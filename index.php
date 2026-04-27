<?php

declare(strict_types=1);

/**
 * NeonIndex - Modern PHP Directory Lister
 * 
 * A sleek, modern directory listing application with admin panel,
 * theme switching, README rendering, and visitor comments support.
 * 
 * @author OmniTx
 * @version 2.0.0
 * @license MIT
 */

// Bootstrap the application
require_once __DIR__ . '/bootstrap.php';

use NeonIndex\Service\ConfigManager;
use NeonIndex\Service\RateLimiter;
use NeonIndex\Service\MarkdownParser;
use NeonIndex\Service\CommentService;
use NeonIndex\Service\UploadService;
use NeonIndex\Service\FileSystem;

// Initialize configuration
$config = ConfigManager::getInstance();

// Define constants from config
define('ADMIN_PASSWORD', $config->get('ADMIN_PASSWORD', 'admin123'));
define('HIDDEN_FILES', array_map('trim', explode(',', $config->get('HIDDEN_FILES', '.env,admin.php'))));
define('SITE_TITLE', $config->get('SITE_TITLE', 'NeonIndex'));
define('DEFAULT_THEME', $config->get('DEFAULT_THEME', 'dark'));
define('README_POSITION', $config->get('README_POSITION', 'bottom'));
define('SHOW_DOWNLOAD', $config->getBool('SHOW_DOWNLOAD'));
define('SHOW_RENAME', $config->getBool('SHOW_RENAME'));
define('SHOW_DELETE', $config->getBool('SHOW_DELETE'));
define('SHOW_UPLOAD', $config->getBool('SHOW_UPLOAD'));
define('SHOW_THEME_TOGGLE', $config->getBool('SHOW_THEME_TOGGLE'));
define('SHOW_COMMENTS', $config->getBool('SHOW_COMMENTS'));
define('BASE_DIR', realpath(__DIR__ . '/uploads') ?: __DIR__ . '/uploads');
define('COMMENTS_FILE', __DIR__ . '/comments.json');
define('RATE_LIMIT_FILE', __DIR__ . '/rate_limits.json');
define('DOWNLOAD_LOG_FILE', __DIR__ . '/downloads.log');
define('MAX_UPLOAD_SIZE', $config->getInt('MAX_UPLOAD_SIZE', 10));
define('CHUNK_SIZE_MB', $config->getInt('CHUNK_SIZE_MB', 8));
define('RATE_LIMIT_UPLOADS', $config->getInt('RATE_LIMIT_UPLOADS', 20));
define('RATE_LIMIT_COMMENTS', $config->getInt('RATE_LIMIT_COMMENTS', 10));
define('ENABLE_DOWNLOAD_LOG', $config->getBool('ENABLE_DOWNLOAD_LOG'));

// Initialize services
$rateLimiter = new RateLimiter(RATE_LIMIT_FILE);
$commentService = new CommentService(COMMENTS_FILE, $rateLimiter, RATE_LIMIT_COMMENTS);
$uploadService = new UploadService(BASE_DIR, $rateLimiter, MAX_UPLOAD_SIZE, CHUNK_SIZE_MB, RATE_LIMIT_UPLOADS);
$markdownParser = new MarkdownParser();

// Ensure uploads directory exists
if (!is_dir(BASE_DIR)) {
    @mkdir(BASE_DIR, 0755, true);
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
    if ($realPath === false || !str_starts_with($realPath, BASE_DIR)) {
        return null;
    }
    return $realPath;
}

/**
 * Check if file should be hidden from listing
 */
function isHiddenFile(string $filename): bool
{
    return in_array($filename, HIDDEN_FILES, true) || 
           in_array(strtolower($filename), HIDDEN_FILES, true);
}

/**
 * Verify CSRF token
 */
function verifyCSRF(): bool
{
    return isset($_POST['csrf_token']) && 
           hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

/**
 * Log a download
 */
function logDownload(string $file): void
{
    if (!ENABLE_DOWNLOAD_LOG) {
        return;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $time = date('Y-m-d H:i:s');
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    $safeFile = str_replace(["\n", "\r", "|"], '', $file);
    $log = "[{$time}] IP: {$ip} | File: {$safeFile} | UA: {$userAgent}\n";

    file_put_contents(DOWNLOAD_LOG_FILE, $log, FILE_APPEND | LOCK_EX);
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
    if (hash_equals(ADMIN_PASSWORD, $password)) {
        session_regenerate_id(true);
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
        $mimeType = FileSystem::getMimeType($filePath);
        $fileName = basename($filePath);

        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: inline; filename="' . $fileName . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }
}

// Handle Download
if (($_GET['action'] ?? '') === 'download' && isset($_GET['file'])) {
    $filePath = sanitizePath($_GET['file']);
    if ($filePath && is_file($filePath) && !isHiddenFile(basename($filePath))) {
        logDownload($_GET['file']);

        @ini_set('memory_limit', '-1');
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: no-cache, must-revalidate');

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
    if ($folderPath && is_dir($folderPath)) {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $tempZip = $uploadService->downloadFolderAsZip($folderPath);
        
        if ($tempZip !== null) {
            $zipName = basename($folderPath) . '.zip';
            logDownload($_GET['folder'] . ' (folder)');
            
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $zipName . '"');
            header('Content-Length: ' . filesize($tempZip));
            header('Content-Transfer-Encoding: binary');
            header('Cache-Control: no-cache, must-revalidate');

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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    ($_POST['action'] ?? '') === 'delete' && 
    isAuthenticated()) {
    
    if (verifyCSRF() && isset($_POST['file'])) {
        $filePath = sanitizePath($_POST['file']);
        if ($filePath && $filePath !== BASE_DIR) {
            if (FileSystem::deleteRecursive($filePath)) {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    ($_POST['action'] ?? '') === 'rename' && 
    isAuthenticated()) {
    
    if (verifyCSRF() && isset($_POST['file'], $_POST['newname'])) {
        $filePath = sanitizePath($_POST['file']);
        $newName = basename($_POST['newname']);
        
        if ($filePath && $filePath !== BASE_DIR && $newName !== '') {
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
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    ($_POST['action'] ?? '') === 'upload' && 
    isAuthenticated()) {
    
    if (verifyCSRF() && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $check = $uploadService->canUpload();
        if (!$check['allowed']) {
            $_SESSION['flash_message'] = $check['message'];
            $_SESSION['flash_type'] = 'warning';
        } else {
            $validation = $uploadService->validateFile($_FILES['file']);
            if (!$validation['valid']) {
                $_SESSION['flash_message'] = $validation['message'];
                $_SESSION['flash_type'] = 'danger';
            } else {
                $currentDir = isset($_POST['dir']) ? sanitizePath($_POST['dir']) : BASE_DIR;
                $currentDir ??= BASE_DIR;
                
                $result = $uploadService->upload($_FILES['file'], $currentDir);
                $_SESSION['flash_message'] = $result['message'];
                $_SESSION['flash_type'] = $result['success'] ? 'success' : 'danger';
            }
        }
        
        $redirectUrl = $_SERVER['PHP_SELF'];
        if (isset($_POST['dir']) && $_POST['dir'] !== '') {
            $redirectUrl .= '?dir=' . urlencode($_POST['dir']);
        }
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Handle Chunked Upload (AJAX - Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    ($_POST['action'] ?? '') === 'chunked_upload' && 
    isAuthenticated()) {
    
    header('Content-Type: application/json');

    if (!verifyCSRF()) {
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        exit;
    }

    $fileName = $_POST['fileName'] ?? '';
    $chunkIndex = (int)($_POST['chunkIndex'] ?? 0);
    $totalChunks = (int)($_POST['totalChunks'] ?? 1);
    $uploadDir = isset($_POST['dir']) ? sanitizePath($_POST['dir']) : BASE_DIR;
    $uploadDir ??= BASE_DIR;

    $result = $uploadService->uploadChunk(
        $fileName,
        $chunkIndex,
        $totalChunks,
        $_FILES['chunk'] ?? [],
        $uploadDir
    );

    echo json_encode($result);
    exit;
}

// Handle Comment Submission (Visitors only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    ($_POST['action'] ?? '') === 'submit_comment' && 
    !isAuthenticated()) {
    
    if (verifyCSRF()) {
        $name = trim($_POST['comment_name'] ?? 'Anonymous');
        $email = trim($_POST['comment_email'] ?? '');
        $commentMessage = trim($_POST['comment_message'] ?? '');

        $result = $commentService->submit($name, $email, $commentMessage);
        $message = $result['message'];
        $messageType = $result['success'] ? 'success' : 'warning';
    }
}

// =============================================================================
// DIRECTORY LISTING
// =============================================================================

// Get current directory
$requestedPath = $_GET['dir'] ?? '';
$currentDir = sanitizePath($requestedPath) ?: BASE_DIR;

// Get directory contents
$items = [];
if (is_dir($currentDir)) {
    foreach (scandir($currentDir) as $file) {
        if ($file === '.' || ($file === '..' && $currentDir === BASE_DIR)) {
            continue;
        }
        if (isHiddenFile($file) && !isAuthenticated()) {
            continue;
        }

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
    usort($items, fn($a, $b) => 
        $b['isDir'] <=> $a['isDir'] ?: strcasecmp($a['name'], $b['name'])
    );
}

// Build breadcrumb navigation
$relativePath = trim(str_replace(BASE_DIR, '', $currentDir), DIRECTORY_SEPARATOR);
$breadcrumbs = [['name' => 'Home', 'path' => '']];
if ($relativePath !== '') {
    $parts = explode(DIRECTORY_SEPARATOR, $relativePath);
    $accPath = '';
    foreach ($parts as $part) {
        $accPath .= ($accPath !== '' ? '/' : '') . $part;
        $breadcrumbs[] = ['name' => $part, 'path' => $accPath];
    }
}

// Check for README.md
$readmeFile = $currentDir . DIRECTORY_SEPARATOR . 'README.md';
$readmeContent = '';
if (file_exists($readmeFile)) {
    $readmeContent = $markdownParser->parse(file_get_contents($readmeFile));
}

?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= htmlspecialchars(DEFAULT_THEME) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(SITE_TITLE) ?></title>
    <script src="public/js/theme.js"></script>
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
    <link href="public/css/styles.css" rel="stylesheet">
</head>
<body>
    <!-- Topbar -->
    <div class="topbar">
        <div class="container d-flex align-items-center justify-content-between">
            <a class="brand" href="index.php">
                <?= getIconSvg('folder') ?>
                <span class="glow"><?= htmlspecialchars(SITE_TITLE) ?></span>
            </a>
            <div class="d-flex align-items-center gap-2">
                <?php if (SHOW_THEME_TOGGLE): ?>
                    <button class="btn-ghost" onclick="toggleTheme()" title="Toggle Theme">
                        <?= getIconSvg('theme') ?>
                    </button>
                <?php endif; ?>
                <?php if (isAuthenticated()): ?>
                    <a href="admin.php" class="btn-ghost">
                        <?= getIconSvg('settings') ?><span>Admin</span>
                    </a>
                    <a href="?action=logout" class="btn-ghost text-danger">
                        <?= getIconSvg('logout') ?>
                    </a>
                <?php else: ?>
                    <button class="btn-ghost" data-bs-toggle="modal" data-bs-target="#loginModal" title="Admin Login">
                        <?= getIconSvg('lock') ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="container py-4 fade-up">
        <!-- Alerts -->
        <?php if ($message !== ''): ?>
            <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show mb-3">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- README (Top) -->
        <?php if (README_POSITION === 'top' && $readmeContent !== ''): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <span class="text-accent"><?= getIconSvg('file') ?></span> README.md
                </div>
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
                                <a href="?dir=<?= FileSystem::safeUrlEncode($crumb['path']) ?>">
                                    <?= htmlspecialchars($crumb['name']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>
                <?php if (isAuthenticated() && SHOW_UPLOAD): ?>
                    <button type="button" class="btn-accent btn-sm py-1 px-3" data-bs-toggle="modal" data-bs-target="#prettyUploadModal">
                        <?= getIconSvg('upload') ?>Upload
                    </button>
                <?php endif; ?>
            </div>

            <?php if (empty($items)): ?>
                <div class="empty-state">
                    <i class="bi bi-folder2-open"></i>
                    This folder is empty
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover">
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
                                        <i class="bi bi-folder-fill text-warning me-1"></i>
                                        <a href="?dir=<?= FileSystem::safeUrlEncode($item['path']) ?>" class="text-decoration-none">
                                            <?= htmlspecialchars($item['name']) ?>
                                        </a>
                                    <?php else: ?>
                                        <i class="bi <?= FileSystem::getFileIcon($item['name']) ?> me-1" style="color:var(--muted)"></i>
                                        <a href="?action=view&file=<?= FileSystem::safeUrlEncode($item['path']) ?>" 
                                           class="text-decoration-none" target="_blank">
                                            <?= htmlspecialchars($item['name']) ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td class="d-none d-sm-table-cell" style="color:var(--muted)">
                                    <?= $item['isDir'] ? '—' : FileSystem::formatBytes($item['size']) ?>
                                </td>
                                <td class="d-none d-md-table-cell" style="color:var(--muted)">
                                    <?= date('M d, Y H:i', $item['modified']) ?>
                                </td>
                                <td class="text-end file-actions text-nowrap">
                                    <?php if ($item['isDir'] && $item['name'] !== '..' && SHOW_DOWNLOAD): ?>
                                        <a href="?action=download_folder&folder=<?= FileSystem::safeUrlEncode($item['path']) ?>" 
                                           class="btn-ghost py-1 px-2" title="Download ZIP">
                                            <?= getIconSvg('bulk') ?>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (!$item['isDir'] && $item['name'] !== '..' && SHOW_DOWNLOAD): ?>
                                        <a href="uploads/<?= htmlspecialchars(FileSystem::safeUrlEncode($item['path'])) ?>" 
                                           download class="btn-ghost py-1 px-2">
                                            <?= getIconSvg('download') ?><span>Download</span>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (isAuthenticated() && $item['name'] !== '..'): ?>
                                        <?php if (SHOW_RENAME): ?>
                                            <button class="btn-ghost py-1 px-2" 
                                                    onclick="showRename('<?= htmlspecialchars(addslashes($item['path'])) ?>','<?= htmlspecialchars(addslashes($item['name'])) ?>')" 
                                                    title="Rename">
                                                <?= getIconSvg('edit') ?>
                                            </button>
                                        <?php endif; ?>
                                        <?php if (SHOW_DELETE): ?>
                                            <button class="btn-ghost py-1 px-2 text-danger" 
                                                    onclick="showDelete('<?= htmlspecialchars(addslashes($item['path'])) ?>','<?= htmlspecialchars(addslashes($item['name'])) ?>')" 
                                                    title="Delete">
                                                <?= getIconSvg('trash') ?>
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

        <!-- README (Bottom) -->
        <?php if (README_POSITION === 'bottom' && $readmeContent !== ''): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <span class="text-accent"><?= getIconSvg('file') ?></span> README.md
                </div>
                <div class="card-body"><?= $readmeContent ?></div>
            </div>
        <?php endif; ?>

        <!-- Comment Form (Visitors only) -->
        <?php if (SHOW_COMMENTS && !isAuthenticated()): ?>
            <div class="card mt-4">
                <div class="card-header">
                    <span class="text-accent"><?= getIconSvg('message') ?></span> Leave a Comment
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="submit_comment">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Name</label>
                                <input type="text" name="comment_name" class="form-control" placeholder="Your name (optional)">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Email</label>
                                <input type="email" name="comment_email" class="form-control" placeholder="Your email (optional)">
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label">Message <span class="text-danger">*</span></label>
                            <textarea name="comment_message" class="form-control" rows="4" placeholder="Write your comment here..." required></textarea>
                        </div>
                        <button type="submit" class="btn-accent mt-3">
                            <?= getIconSvg('message') ?>Submit
                        </button>
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
                        <input type="password" name="password" class="form-control" placeholder="Password" required autofocus>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-accent">Login</button>
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
                        <label class="form-label">New name</label>
                        <input type="text" name="newname" id="renameName" class="form-control" required>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-accent">Rename</button>
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
                    <h5 class="modal-title text-danger"><i class="bi bi-trash3 me-2"></i>Delete</h5>
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
                        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-accent" style="background:linear-gradient(135deg,#dc3545,#a71d2a)">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Upload Modal (Admin only) -->
    <?php if (isAuthenticated() && SHOW_UPLOAD): ?>
    <div class="modal fade" id="prettyUploadModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><?= getIconSvg('upload') ?> Upload Files</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <!-- Drop Zone -->
                    <div id="uploadDropZone" class="dropzone" onclick="document.getElementById('prettyFileInput').click()">
                        <?= getIconSvg('upload', 32) ?>
                        <p style="color:var(--muted);margin:0.75rem 0">Drag & drop files or folders here, or click to browse</p>
                        <div class="d-flex gap-2 justify-content-center flex-wrap">
                            <input type="file" id="prettyFileInput" class="d-none" multiple>
                            <input type="file" id="prettyFolderInput" class="d-none" webkitdirectory>
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <button type="button" class="btn-ghost" onclick="event.stopPropagation();document.getElementById('prettyFileInput').click()">
                                <?= getIconSvg('file') ?> Files
                            </button>
                            <button type="button" class="btn-ghost" onclick="event.stopPropagation();document.getElementById('prettyFolderInput').click()">
                                <?= getIconSvg('folder') ?> Folder
                            </button>
                        </div>
                    </div>

                    <!-- Progress Panel -->
                    <div id="uploadProgress" class="d-none">
                        <!-- File List with individual progress -->
                        <div id="uploadFilesList" class="mb-3" style="max-height:200px;overflow-y:auto"></div>

                        <!-- Stats Row: Speed | Transferred | ETA -->
                        <div class="d-flex justify-content-between align-items-center mb-2" style="font-size:.82rem;color:var(--muted)">
                            <span><?= getIconSvg('upload', 14) ?> <span id="uploadSpeed">—</span></span>
                            <span id="uploadTransferred">0 B / 0 B</span>
                            <span>ETA: <span id="uploadEta">—</span></span>
                        </div>

                        <!-- Overall bar -->
                        <div class="d-flex justify-content-between mb-1">
                            <span id="uploadOverallStatus">Uploading...</span>
                            <span id="uploadPercent" class="fw-bold">0%</span>
                        </div>
                        <div class="progress" style="height:22px;border-radius:11px;overflow:hidden">
                            <div id="uploadProgressBar" class="progress-bar progress-bar-striped progress-bar-animated" 
                                 style="width:0%;background:linear-gradient(135deg,var(--accent),var(--accent-hover));transition:width 0.15s"></div>
                        </div>
                    </div>

                    <!-- Result -->
                    <div id="uploadResult" class="d-none">
                        <div class="alert mb-0" id="uploadResultAlert"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-ghost" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="public/js/app.js"></script>
</body>
</html>
