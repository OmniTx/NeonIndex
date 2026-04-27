<?php

declare(strict_types=1);

namespace NeonIndex\Service;

use Exception;

class UpdateService
{
    private string $githubRepo = 'OmniTx/NeonIndex'; // Replace with actual repo if different
    private string $versionFile = __DIR__ . '/../VERSION';
    private string $backupDir = __DIR__ . '/../backups';
    private string $tempDir = __DIR__ . '/../temp';

    public function getCurrentVersion(): string
    {
        if (!file_exists($this->versionFile)) {
            return '1.0.0'; // Default if no version file exists
        }
        return trim(file_get_contents($this->versionFile)) ?: '1.0.0';
    }

    public function checkForUpdates(): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: NeonIndex-Updater\r\n"
            ]
        ]);

        $url = "https://api.github.com/repos/{$this->githubRepo}/releases/latest";
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return ['success' => false, 'error' => 'Could not connect to GitHub API'];
        }

        $data = json_decode($response, true);
        if (!isset($data['tag_name'])) {
            return ['success' => false, 'error' => 'Invalid response from GitHub'];
        }

        $latestVersion = ltrim($data['tag_name'], 'v');
        $currentVersion = $this->getCurrentVersion();
        $hasUpdate = version_compare($latestVersion, $currentVersion, '>');

        return [
            'success' => true,
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'has_update' => $hasUpdate,
            'release_notes' => $data['body'] ?? '',
            'download_url' => $data['zipball_url'] ?? ''
        ];
    }

    public function performUpdate(string $downloadUrl): array
    {
        try {
            // Ensure directories exist
            if (!is_dir($this->backupDir)) mkdir($this->backupDir, 0755, true);
            if (!is_dir($this->tempDir)) mkdir($this->tempDir, 0755, true);

            $backupName = 'backup_' . date('Ymd_His');
            $backupPath = $this->backupDir . '/' . $backupName;
            $tempZip = $this->tempDir . '/update.zip';
            $extractPath = $this->tempDir . '/extracted';

            // 1. Create Backup (Failsafe)
            $this->createBackup($backupPath);

            // 2. Download Update
            if (!$this->downloadFile($downloadUrl, $tempZip)) {
                throw new Exception('Failed to download update package');
            }

            // 3. Extract
            if (!class_exists('ZipArchive')) {
                throw new Exception('ZipArchive extension is required for updates');
            }
            
            $zip = new \ZipArchive();
            if ($zip->open($tempZip) !== true) {
                throw new Exception('Failed to open update package');
            }
            
            if (!is_dir($extractPath)) mkdir($extractPath, 0755, true);
            $zip->extractTo($extractPath);
            $zip->close();

            // Find the root folder inside the zip (usually repo-name-hash)
            $files = scandir($extractPath);
            $sourceDir = '';
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..' && is_dir($extractPath . '/' . $file)) {
                    $sourceDir = $extractPath . '/' . $file;
                    break;
                }
            }

            if (empty($sourceDir)) {
                throw new Exception('Invalid update package structure');
            }

            // 4. Copy Files (Overwrite)
            $this->copyFiles($sourceDir, __DIR__ . '/..');

            // 5. Cleanup
            $this->deleteDir($tempZip);
            $this->deleteDir($extractPath);

            return ['success' => true, 'message' => 'Update completed successfully!'];

        } catch (Exception $e) {
            // Rollback on failure
            $this->rollback($backupPath);
            return ['success' => false, 'error' => 'Update failed: ' . $e->getMessage() . '. System rolled back.'];
        }
    }

    private function createBackup(string $destination): void
    {
        $root = __DIR__ . '/..';
        $zip = new \ZipArchive();
        $zip->open($destination . '.zip', \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($root) + 1);
                
                // Skip backup folder itself to avoid infinite loop
                if (strpos($relativePath, 'backups/') === 0) continue;
                // Skip temp folder
                if (strpos($relativePath, 'temp/') === 0) continue;

                $zip->addFile($filePath, $relativePath);
            }
        }
        $zip->close();
    }

    private function downloadFile(string $url, string $destination): bool
    {
        $fp = fopen($destination, 'w+');
        if ($fp === false) return false;

        $ch = curl_init(str_replace(" ", "%20", $url));
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, 'NeonIndex-Updater');
        
        $success = curl_exec($ch);
        curl_close($ch);
        fclose($fp);

        return $success !== false;
    }

    private function copyFiles(string $source, string $dest): void
    {
        if (!is_dir($source)) return;
        
        $files = scandir($source);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;

            $srcPath = $source . '/' . $file;
            $dstPath = $dest . '/' . $file;

            // Preserve config and user data
            if ($file === '.env' || $file === 'uploads' || $file === 'comments.json' || $file === 'backups' || $file === 'temp') {
                continue;
            }

            if (is_dir($srcPath)) {
                if (!is_dir($dstPath)) mkdir($dstPath, 0755, true);
                $this->copyFiles($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    private function rollback(string $backupZip): void
    {
        if (!file_exists($backupZip . '.zip')) return;

        $zip = new \ZipArchive();
        if ($zip->open($backupZip . '.zip') === true) {
            $zip->extractTo(__DIR__ . '/..');
            $zip->close();
        }
    }

    private function deleteDir(string $path): void
    {
        if (!file_exists($path)) return;
        if (is_dir($path)) {
            $files = scandir($path);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $this->deleteDir($path . '/' . $file);
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}
