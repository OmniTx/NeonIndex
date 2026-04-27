<?php

declare(strict_types=1);

namespace NeonIndex\Service;

/**
 * File System Utilities for NeonIndex
 */
class FileSystem
{
    /**
     * Recursively delete a directory and all its contents
     */
    public static function deleteRecursive(string $path): bool
    {
        if (is_file($path)) {
            return @unlink($path);
        }
        
        if (is_dir($path)) {
            $items = scandir($path);
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                if (!self::deleteRecursive($path . DIRECTORY_SEPARATOR . $item)) {
                    return false;
                }
            }
            return @rmdir($path);
        }
        
        return false;
    }

    /**
     * Format bytes to human-readable size
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes) / log(1024));
        $pow = min($pow, 4);
        
        return round($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    /**
     * URL encode path segments but keep slashes
     */
    public static function safeUrlEncode(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Get file icon class based on extension
     */
    public static function getFileIcon(string $filename): string
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
            'webp' => 'bi-file-image',
            'zip' => 'bi-file-zip',
            'rar' => 'bi-file-zip',
            '7z' => 'bi-file-zip',
            'tar' => 'bi-file-zip',
            'gz' => 'bi-file-zip',
            'mp3' => 'bi-file-music',
            'wav' => 'bi-file-music',
            'flac' => 'bi-file-music',
            'mp4' => 'bi-file-play',
            'avi' => 'bi-file-play',
            'mkv' => 'bi-file-play',
            'webm' => 'bi-file-play',
            'exe' => 'bi-file-binary',
            'msi' => 'bi-file-binary',
            'doc' => 'bi-file-word',
            'docx' => 'bi-file-word',
            'xls' => 'bi-file-excel',
            'xlsx' => 'bi-file-excel',
            'ppt' => 'bi-file-ppt',
            'pptx' => 'bi-file-ppt',
        ];
        
        return $icons[$ext] ?? 'bi-file-earmark';
    }

    /**
     * Get MIME type for a file
     */
    public static function getMimeType(string $filePath): string
    {
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        // Force text/plain for common text files to display in browser
        $textExtensions = [
            'txt', 'md', 'json', 'xml', 'csv', 'log', 'ini', 
            'yml', 'yaml', 'css', 'js', 'php', 'html', 'htm', 'sql'
        ];
        
        if (in_array($ext, $textExtensions, true)) {
            return 'text/plain; charset=utf-8';
        }
        
        return $mimeType;
    }
}
