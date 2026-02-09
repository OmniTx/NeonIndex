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

session_start();

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

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
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

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (($_POST['password'] ?? '') === ADMIN_PASSWORD) {
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
    $_SESSION['authenticated'] = false;
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Handle Delete (Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isAuthenticated()) {
    if (verifyCSRF() && isset($_POST['file'])) {
        $filePath = sanitizePath($_POST['file']);
        if ($filePath && $filePath !== BASE_DIR) {
            if (is_dir($filePath) ? @rmdir($filePath) : @unlink($filePath)) {
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
        $currentDir = isset($_POST['dir']) ? sanitizePath($_POST['dir']) : BASE_DIR;
        if (!$currentDir)
            $currentDir = BASE_DIR;
        $filename = basename($_FILES['file']['name']);
        if (move_uploaded_file($_FILES['file']['tmp_name'], $currentDir . DIRECTORY_SEPARATOR . $filename)) {
            $message = 'File uploaded!';
            $messageType = 'success';
        }
    }
}

// Handle Comment Submission (Visitors only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_comment' && !isAuthenticated()) {
    if (verifyCSRF()) {
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
                $message = 'Comment submitted! Thank you.';
                $messageType = 'success';
            } else {
                $message = 'Failed to submit comment.';
                $messageType = 'danger';
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
    <meta name="description" content="<?= htmlspecialchars(SITE_TITLE) ?> - A modern file directory browser with theme switching and admin management.">
    <meta name="keywords" content="file browser, directory listing, file manager, php directory lister">
    <meta name="author" content="OmniTx">
    <meta name="robots" content="index, follow">
    
    <!-- Open Graph / Social Media -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="<?= htmlspecialchars(SITE_TITLE) ?>">
    <meta property="og:description" content="A modern file directory browser with theme switching and admin management.">
    <meta property="og:site_name" content="<?= htmlspecialchars(SITE_TITLE) ?>">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="<?= htmlspecialchars(SITE_TITLE) ?>">
    <meta name="twitter:description" content="A modern file directory browser with theme switching and admin management.">
    
    <!-- Theme Color -->
    <meta name="theme-color" content="#00FFC8">
    <meta name="msapplication-TileColor" content="#0B1215">
    
    <!-- Favicon (inline SVG) -->
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect fill='%230B1215' width='100' height='100' rx='20'/><text y='70' x='50' text-anchor='middle' font-size='60'>📁</text></svg>">
    
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
    </style>
</head>

<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg sticky-top shadow-sm">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center gap-2" href="?">
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
                                <a href="?dir=<?= urlencode($crumb['path']) ?>" class="text-decoration-none">
                                    <?= htmlspecialchars($crumb['name']) ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </nav>

                <!-- Upload Form (Admin only) -->
                <?php if (isAuthenticated() && SHOW_UPLOAD): ?>
                    <form method="POST" enctype="multipart/form-data" class="d-flex gap-2">
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="dir" value="<?= htmlspecialchars($relativePath) ?>">
                        <input type="file" name="file" required class="form-control form-control-sm"
                            style="max-width: 200px;">
                        <button type="submit" class="btn btn-sm btn-info"><i class="bi bi-upload"></i></button>
                    </form>
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
                                            <a href="?dir=<?= urlencode($item['path']) ?>" class="text-decoration-none">
                                                <?= htmlspecialchars($item['name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <i class="bi <?= getFileIcon($item['name']) ?> text-secondary me-2"></i>
                                            <span><?= htmlspecialchars($item['name']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted d-none d-sm-table-cell">
                                        <?= $item['isDir'] ? '—' : formatBytes($item['size']) ?>
                                    </td>
                                    <td class="text-muted d-none d-md-table-cell">
                                        <?= date('M d, Y H:i', $item['modified']) ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (!$item['isDir'] && $item['name'] !== '..' && SHOW_DOWNLOAD): ?>
                                            <a href="uploads/<?= htmlspecialchars($item['path']) ?>" download
                                                class="btn btn-sm btn-outline-secondary" title="Download">
                                                <i class="bi bi-download"></i>
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
</body>

</html>