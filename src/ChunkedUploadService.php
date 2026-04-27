<?php

declare(strict_types=1);

namespace NeonIndex\Service;

use Exception;

class ChunkedUploadService
{
    private string $uploadDir;
    private int $chunkSize;

    public function __construct(string $uploadDir = '../uploads', int $chunkSize = 2 * 1024 * 1024)
    {
        $this->uploadDir = rtrim($uploadDir, '/');
        $this->chunkSize = $chunkSize; // Default 2MB chunks for low-end servers
        
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    /**
     * Handle a chunk upload
     */
    public function handleChunk(array $file, string $chunkIndex, int $totalChunks, string $fileName): array
    {
        try {
            $tempDir = $this->uploadDir . '/.chunks';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
            $chunkFile = $tempDir . '/' . md5($safeName) . '_' . $chunkIndex;

            if (!move_uploaded_file($file['tmp_name'], $chunkFile)) {
                throw new Exception('Failed to save chunk');
            }

            // Check if all chunks are received
            $receivedChunks = 0;
            for ($i = 0; $i < $totalChunks; $i++) {
                if (file_exists($tempDir . '/' . md5($safeName) . '_' . $i)) {
                    $receivedChunks++;
                }
            }

            if ($receivedChunks === $totalChunks) {
                // Assemble file
                return $this->assembleFile($safeName, $totalChunks, $tempDir);
            }

            return [
                'success' => true,
                'assembled' => false,
                'progress' => ($receivedChunks / $totalChunks) * 100
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Assemble chunks into final file
     */
    private function assembleFile(string $fileName, int $totalChunks, string $tempDir): array
    {
        $finalPath = $this->uploadDir . '/' . $fileName;
        $hash = md5($fileName);
        
        $out = fopen($finalPath, 'wb');
        if ($out === false) {
            return ['success' => false, 'error' => 'Cannot create final file'];
        }

        for ($i = 0; $i < $totalChunks; $i++) {
            $chunkFile = $tempDir . '/' . $hash . '_' . $i;
            if (!file_exists($chunkFile)) {
                fclose($out);
                return ['success' => false, 'error' => 'Missing chunk ' . $i];
            }
            
            $in = fopen($chunkFile, 'rb');
            if ($in === false) {
                fclose($out);
                return ['success' => false, 'error' => 'Cannot read chunk ' . $i];
            }
            
            while ($buff = fread($in, $this->chunkSize)) {
                fwrite($out, $buff);
            }
            fclose($in);
            unlink($chunkFile);
        }

        fclose($out);
        rmdir($tempDir); // Clean up temp dir if empty

        return [
            'success' => true,
            'assembled' => true,
            'path' => $finalPath,
            'size' => filesize($finalPath)
        ];
    }

    /**
     * Handle simple single-file upload (fallback)
     */
    public function handleSimpleUpload(array $file, string $destinationName): array
    {
        try {
            $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $destinationName);
            $targetPath = $this->uploadDir . '/' . $safeName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                throw new Exception('Failed to move uploaded file');
            }

            return [
                'success' => true,
                'path' => $targetPath,
                'size' => filesize($targetPath)
            ];

        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Stream file download with range support (resumable downloads)
     */
    public function streamDownload(string $filePath): void
    {
        if (!file_exists($filePath)) {
            http_response_code(404);
            echo 'File not found';
            return;
        }

        $fileSize = filesize($filePath);
        $start = 0;
        $end = $fileSize - 1;

        // Handle Range header for resumable downloads
        if (isset($_SERVER['HTTP_RANGE'])) {
            http_response_code(206);
            preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches);
            $start = (int)$matches[1];
            $end = isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : $fileSize - 1;
        }

        $length = $end - $start + 1;

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header("Content-Range: bytes $start-$end/$fileSize");
        header('Connection: keep-alive');

        $handle = fopen($filePath, 'rb');
        fseek($handle, $start);
        
        $buffer = 8192; // 8KB buffer for low memory servers
        $sent = 0;
        
        while (!feof($handle) && $sent < $length) {
            $read = min($buffer, $length - $sent);
            echo fread($handle, $read);
            flush();
            $sent += $read;
            
            // Check if client disconnected
            if (connection_aborted()) {
                break;
            }
        }
        
        fclose($handle);
    }
}
