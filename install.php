<?php
/**
 * NeonIndex - Web Installer
 * 
 * Downloads and installs the latest version of NeonIndex from GitHub.
 * @author OmniTx
 */

session_start();

// CSRF Protection
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Configuration
define('DEFAULT_REPO', 'OmniTx/NeonIndex');
define('DEFAULT_BRANCH', 'main');
define('INSTALL_DIR', __DIR__);

// Helper Functions
function logMessage($msg, $type = 'info')
{
    $_SESSION['logs'][] = ['msg' => $msg, 'type' => $type];
}

function clearLogs()
{
    $_SESSION['logs'] = [];
}

function downloadFile($url, $destination)
{
    $fp = fopen($destination, 'w+');
    if (!$fp)
        return false;

    $ch = curl_init(str_replace(' ', '%20', $url));
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'NeonIndex-Installer');

    $success = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    return $success ? true : $error;
}

function recursiveCopy($src, $dst)
{
    $dir = opendir($src);
    @mkdir($dst);
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                recursiveCopy($src . '/' . $file, $dst . '/' . $file);
            } else {
                copy($src . '/' . $file, $dst . '/' . $file);
            }
        }
    }
    closedir($dir);
}

function recursiveDelete($dir)
{
    if (!is_dir($dir))
        return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        (is_dir("$dir/$file")) ? recursiveDelete("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}

// Handle Installation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $_SESSION['logs'][] = ['msg' => 'Invalid security token. Please refresh and try again.', 'type' => 'danger'];
    } else {
        clearLogs();
        $repo = preg_replace('/[^a-zA-Z0-9\-_\/]/', '', $_POST['repo'] ?? DEFAULT_REPO);
        $branch = preg_replace('/[^a-zA-Z0-9\-_]/', '', $_POST['branch'] ?? DEFAULT_BRANCH);

        // 1. Check Requirements
        if (!extension_loaded('zip')) {
            logMessage('ZIP extension is missing!', 'danger');
        } elseif (!extension_loaded('curl')) {
            logMessage('CURL extension is missing!', 'danger');
        } elseif (!is_writable(INSTALL_DIR)) {
            logMessage('Current directory is not writable!', 'danger');
        } else {
            // 2. Download Zip
            $zipUrl = "https://github.com/{$repo}/archive/refs/heads/{$branch}.zip";
            $zipFile = INSTALL_DIR . '/neonindex.zip';

            logMessage("Downloading from {$zipUrl}...", 'info');
            $download = downloadFile($zipUrl, $zipFile);

            if ($download === true) {
                logMessage('Download successful!', 'success');

                // 3. Extract Zip
                $zip = new ZipArchive;
                if ($zip->open($zipFile) === TRUE) {
                    // Get the name of the root folder in the zip
                    $rootFolder = $zip->getNameIndex(0);
                    $zip->extractTo(INSTALL_DIR);
                    $zip->close();

                    logMessage('Extracted successfully!', 'success');

                    // 4. Move Files
                    $source = INSTALL_DIR . '/' . trim($rootFolder, '/');
                    if (is_dir($source)) {
                        logMessage('Moving files..', 'info');

                        // Move files from extracted folder to root
                        $iterator = new RecursiveIteratorIterator(
                            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
                            RecursiveIteratorIterator::SELF_FIRST
                        );

                        foreach ($iterator as $item) {
                            $destPath = INSTALL_DIR . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
                            if ($item->isDir()) {
                                if (!is_dir($destPath))
                                    mkdir($destPath);
                            } else {
                                // Don't overwrite existing config if possible, or maybe we should?
                                // For installer, usually we overwrite. 
                                // Exception: don't overwrite .env if it exists
                                if ($item->getFilename() === '.env' && file_exists($destPath)) {
                                    continue;
                                }
                                copy($item, $destPath);
                            }
                        }

                        // 5. Cleanup
                        recursiveDelete($source);
                        unlink($zipFile);

                        // 6. Create .env if missing
                        if (!file_exists(INSTALL_DIR . '/.env') && file_exists(INSTALL_DIR . '/.env.example')) {
                            copy(INSTALL_DIR . '/.env.example', INSTALL_DIR . '/.env');
                            logMessage('Created .env file configuration.', 'success');
                        }

                        logMessage('Installation complete!', 'success');
                        $_SESSION['install_complete'] = true;
                    } else {
                        logMessage('Could not find extracted folder.', 'danger');
                    }
                } else {
                    logMessage('Failed to extract ZIP file.', 'danger');
                }
            } else {
                logMessage("Failed to download: " . (is_string($download) ? $download : 'Unknown error'), 'danger');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>NeonIndex Installer</title>
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml"
        href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2300ffc8' class='bi bi-hdd-network' viewBox='0 0 16 16'%3E%3Cpath d='M4.5 5a.5.5 0 1 0 0-1 .5.5 0 0 0 0 1zM3 4.5a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0z'/%3E%3Cpath d='M0 4a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1a2 2 0 0 1-2 2H8.5v3a1.5 1.5 0 0 1 1.5 1.5h5.5a.5.5 0 0 1 0 1H10A1.5 1.5 0 0 1 8.5 14h-1A1.5 1.5 0 0 1 6 12.5H.5a.5.5 0 0 1 0-1H6A1.5 1.5 0 0 1 7.5 10V7H2a2 2 0 0 1-2-2V4zm1 0v1a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V4a1 1 0 0 0-1-1H2a1 1 0 0 0-1 1zm6 7.5v1a.5.5 0 0 0 .5.5h1a.5.5 0 0 0 .5-.5v-1a.5.5 0 0 0-.5-.5h-1a.5.5 0 0 0-.5.5z'/%3E%3C/svg%3E">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background-color: #0B1215;
            color: #fff;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        .installer-container {
            max-width: 600px;
            margin: 50px auto;
        }

        .card {
            border: 1px solid #333;
            background: #151b1e;
        }

        .card-header {
            background: #1a2125;
            border-bottom: 1px solid #333;
        }

        .neon-text {
            color: #00FFC8;
            text-shadow: 0 0 10px rgba(0, 255, 200, 0.3);
        }

        .btn-neon {
            background: #00FFC8;
            color: #000;
            border: none;
            font-weight: 600;
        }

        .btn-neon:hover {
            background: #00e6b4;
            color: #000;
            box-shadow: 0 0 15px rgba(0, 255, 200, 0.4);
        }
    </style>
</head>

<body>
    <div class="container installer-container">
        <div class="text-center mb-4">
            <h1 class="neon-text display-4 mb-3"><i class="bi bi-hdd-network"></i> NeonIndex</h1>
            <p class="text-muted">Web Installer</p>
        </div>

        <div class="card shadow-lg">
            <div class="card-header py-3">
                <i class="bi bi-download me-2"></i>Install from GitHub
            </div>
            <div class="card-body p-4">

                <?php if (isset($_SESSION['install_complete']) && $_SESSION['install_complete']): ?>
                    <div class="alert alert-success border-0 bg-success bg-opacity-25 text-success mb-4">
                        <i class="bi bi-check-circle-fill me-2"></i>
                        <h5 class="alert-heading d-inline">Installation Successful!</h5>
                        <p class="mt-2 mb-0">NeonIndex has been installed correctly.</p>
                    </div>
                    <div class="d-grid gap-2">
                        <a href="index.php" class="btn btn-neon btn-lg">Go to Home Page</a>
                        <a href="admin.php" class="btn btn-outline-info">Go to Admin Panel</a>
                    </div>
                <?php else: ?>

                    <?php if (!empty($_SESSION['logs'])): ?>
                        <div class="mb-4">
                            <?php foreach ($_SESSION['logs'] as $log): ?>
                                <div class="alert alert-<?= $log['type'] ?> py-2 mb-2">
                                    <i
                                        class="bi bi-<?= $log['type'] === 'success' ? 'check-circle' : ($log['type'] === 'danger' ? 'exclamation-triangle' : 'info-circle') ?> me-2"></i>
                                    <?= htmlspecialchars($log['msg']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="action" value="install">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                        <div class="mb-3">
                            <label class="form-label">GitHub Repository</label>
                            <div class="input-group">
                                <span class="input-group-text bg-dark border-secondary text-light">github.com/</span>
                                <input type="text" name="repo" class="form-control bg-dark border-secondary text-light"
                                    value="<?= DEFAULT_REPO ?>" required>
                            </div>
                            <div class="form-text text-muted">Format: username/repository</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label">Branch</label>
                            <input type="text" name="branch" class="form-control bg-dark border-secondary text-light"
                                value="<?= DEFAULT_BRANCH ?>" required>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-neon btn-lg">
                                <i class="bi bi-cloud-download me-2"></i>Install Now
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
            <div class="card-footer text-center text-muted small py-3">
                Current Directory:
                <?= htmlspecialchars(INSTALL_DIR) ?>
            </div>
        </div>
    </div>
</body>

</html>
<?php
// Clear logs after display
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Keep logs? No, clear them
    // $_SESSION['logs'] = [];
}
?>