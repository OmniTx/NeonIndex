<?php

declare(strict_types=1);

namespace NeonIndex\Services;

use NeonIndex\Security\RateLimiter;
use ZipArchive;

/**
 * Upload Service for NeonIndex
 * Handles file uploads including chunked uploads for large files
 */
class UploadService
{
    private string $baseDir;
    private RateLimiter $rateLimiter;
    private int $maxSizeMb;
    private int $chunkSizeMb;
    private int $rateLimit;

    public function __construct(
        string $baseDir,
        RateLimiter $rateLimiter,
        int $maxSizeMb,
        int $chunkSizeMb,
        int $rateLimit
    ) {
        $this->baseDir = $baseDir;
        $this->rateLimiter = $rateLimiter;
        $this->maxSizeMb = $maxSizeMb;
        $this->chunkSizeMb = $chunkSizeMb;
        $this->rateLimit = $rateLimit;
    }

    /**
     * Check if upload is allowed (rate limit and size)
     */
    public function canUpload(): array
    {
        if ($this->rateLimit > 0 && $this->rateLimiter->isLimited('upload', $this->rateLimit)) {
            return ['allowed' => false, 'message' => 'Upload rate limit exceeded. Try again later.'];
        }

        return ['allowed' => true];
    }

    /**
     * Validate uploaded file
     */
    public function validateFile(array $file): array
    {
        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'message' => $this->getUploadErrorMessage($file['error'] ?? UPLOAD_ERR_NO_FILE)];
        }

        if ($this->maxSizeMb > 0 && $file['size'] > $this->maxSizeMb * 1024 * 1024) {
            return ['valid' => false, 'message' => "File too large! Max: {$this->maxSizeMb}MB"];
        }

        return ['valid' => true];
    }

    /**
     * Handle regular file upload
     */
    public function upload(array $file, string $targetDir): array
    {
        $validation = $this->validateFile($file);
        if (!$validation['valid']) {
            return $validation;
        }

        $filename = basename($file['name']);
        $destination = $targetDir . DIRECTORY_SEPARATOR . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            $this->rateLimiter->record('upload');
            return ['success' => true, 'message' => 'File uploaded successfully!'];
        }

        return ['success' => false, 'message' => 'Failed to upload file.'];
    }

    /**
     * Handle chunked upload
     */
    public function uploadChunk(
        string $fileName,
        int $chunkIndex,
        int $totalChunks,
        array $chunk,
        string $targetDir
    ): array {
        $tempDir = $targetDir . DIRECTORY_SEPARATOR . '.upload_temp';
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0755, true);
        }

        $tempFile = $tempDir . DIRECTORY_SEPARATOR . md5($fileName) . '_' . $chunkIndex;

        if (!isset($chunk['tmp_name']) || $chunk['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Invalid chunk'];
        }

        if (!move_uploaded_file($chunk['tmp_name'], $tempFile)) {
            return ['success' => false, 'error' => 'Failed to save chunk'];
        }

        // If this is the last chunk, merge all chunks
        if ($chunkIndex === $totalChunks - 1) {
            return $this->mergeChunks($fileName, $totalChunks, $tempDir, $targetDir);
        }

        return ['success' => true, 'complete' => false, 'chunkIndex' => $chunkIndex];
    }

    /**
     * Merge uploaded chunks into final file
     */
    private function mergeChunks(
        string $fileName,
        int $totalChunks,
        string $tempDir,
        string $targetDir
    ): array {
        // Normalize path and handle subdirectories
        $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fileName);
        $relativePath = ltrim($relativePath, DIRECTORY_SEPARATOR);

        $finalDir = $targetDir;
        if (str_contains($relativePath, DIRECTORY_SEPARATOR)) {
            $pathParts = explode(DIRECTORY_SEPARATOR, $relativePath);
            $justFileName = array_pop($pathParts);
            $subPath = implode(DIRECTORY_SEPARATOR, $pathParts);
            $finalDir = $targetDir . DIRECTORY_SEPARATOR . $subPath;
            
            if (!is_dir($finalDir)) {
                @mkdir($finalDir, 0755, true);
            }
            $relativePath = $justFileName;
        }

        $finalPath = $finalDir . DIRECTORY_SEPARATOR . basename($relativePath);
        $fp = fopen($finalPath, 'wb');

        if ($fp === false) {
            return ['success' => false, 'error' => 'Failed to create final file'];
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkPath = $tempDir . DIRECTORY_SEPARATOR . md5($fileName) . '_' . $i;
            if (file_exists($chunkPath)) {
                $chunkData = file_get_contents($chunkPath);
                if ($chunkData !== false) {
                    fwrite($fp, $chunkData);
                }
                unlink($chunkPath);
            }
        }

        fclose($fp);

        // Cleanup temp dir if empty
        @rmdir($tempDir);

        $this->rateLimiter->record('upload');

        return ['success' => true, 'complete' => true, 'message' => 'File uploaded successfully!'];
    }

    /**
     * Get human-readable error message for upload error code
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds server maximum size',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds form maximum size',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Server temporary directory missing',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload',
            default => 'Unknown upload error',
        };
    }

    /**
     * Download a folder as ZIP
     */
    public function downloadFolderAsZip(string $folderPath): ?string
    {
        if (!class_exists(ZipArchive::class)) {
            return null;
        }

        $zipName = basename($folderPath) . '.zip';
        $tempZip = tempnam(sys_get_temp_dir(), 'zip_');

        $zip = new ZipArchive();
        if ($zip->open($tempZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return null;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folderPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($folderPath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();

        return $tempZip;
    }
}
