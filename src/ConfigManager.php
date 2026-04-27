<?php

declare(strict_types=1);

namespace NeonIndex\Service;

/**
 * Configuration Manager for NeonIndex
 * Handles loading and saving environment variables
 */
class ConfigManager
{
    private static ?self $instance = null;
    private array $config = [];
    private string $envPath;

    private function __construct()
    {
        $this->envPath = dirname(__DIR__, 2) . '/.env';
        $this->load();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function load(): void
    {
        if (!file_exists($this->envPath)) {
            $this->setDefaults();
            return;
        }

        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $this->config[trim($key)] = trim($value);
        }

        $this->applyDefaults();
    }

    private function setDefaults(): void
    {
        $this->config = [
            'ADMIN_PASSWORD' => 'admin123',
            'HIDDEN_FILES' => '.env,admin.php,.htaccess,.git,comments.json,rate_limits.json,downloads.log',
            'SITE_TITLE' => 'NeonIndex',
            'DEFAULT_THEME' => 'dark',
            'README_POSITION' => 'bottom',
            'SHOW_DOWNLOAD' => 'true',
            'SHOW_RENAME' => 'true',
            'SHOW_DELETE' => 'true',
            'SHOW_UPLOAD' => 'true',
            'SHOW_THEME_TOGGLE' => 'true',
            'SHOW_COMMENTS' => 'true',
            'MAX_UPLOAD_SIZE' => '10',
            'CHUNK_SIZE_MB' => '8',
            'RATE_LIMIT_UPLOADS' => '20',
            'RATE_LIMIT_COMMENTS' => '10',
            'ENABLE_DOWNLOAD_LOG' => 'false',
        ];
    }

    private function applyDefaults(): void
    {
        $defaults = [
            'ADMIN_PASSWORD' => 'admin123',
            'HIDDEN_FILES' => '.env,admin.php,.htaccess,.git,comments.json,rate_limits.json,downloads.log',
            'SITE_TITLE' => 'NeonIndex',
            'DEFAULT_THEME' => 'dark',
            'README_POSITION' => 'bottom',
            'SHOW_DOWNLOAD' => 'true',
            'SHOW_RENAME' => 'true',
            'SHOW_DELETE' => 'true',
            'SHOW_UPLOAD' => 'true',
            'SHOW_THEME_TOGGLE' => 'true',
            'SHOW_COMMENTS' => 'true',
            'MAX_UPLOAD_SIZE' => '10',
            'CHUNK_SIZE_MB' => '8',
            'RATE_LIMIT_UPLOADS' => '20',
            'RATE_LIMIT_COMMENTS' => '10',
            'ENABLE_DOWNLOAD_LOG' => 'false',
        ];

        foreach ($defaults as $key => $value) {
            if (!isset($this->config[$key])) {
                $this->config[$key] = $value;
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    public function getBool(string $key): bool
    {
        $value = $this->get($key, 'false');
        return in_array(strtolower((string)$value), ['true', '1', 'yes'], true);
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int)($this->config[$key] ?? $default);
    }

    public function getAll(): array
    {
        return $this->config;
    }

    public function save(array $newConfig): bool
    {
        $content = "# NeonIndex Configuration\n# Generated on " . date('Y-m-d H:i:s') . "\n\n";
        foreach ($newConfig as $key => $value) {
            $content .= "{$key}={$value}\n";
        }
        return file_put_contents($this->envPath, $content) !== false;
    }

    // Prevent cloning
    private function __clone() {}
    
    // Prevent unserialization
    public function __wakeup(): void
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}
