<?php

declare(strict_types=1);

namespace NeonIndex\Service;

/**
 * Rate Limiter for NeonIndex
 * Prevents abuse by limiting actions per IP address
 */
class RateLimiter
{
    private string $rateLimitFile;
    private const WINDOW_SECONDS = 3600; // 1 hour

    public function __construct(string $rateLimitFile)
    {
        $this->rateLimitFile = $rateLimitFile;
    }

    /**
     * Get rate limit data for current IP
     */
    public function getRateLimits(): array
    {
        if (!file_exists($this->rateLimitFile)) {
            return [];
        }
        
        $content = file_get_contents($this->rateLimitFile);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save rate limit data
     */
    public function saveRateLimits(array $data): void
    {
        file_put_contents($this->rateLimitFile, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Check if action is rate limited for current IP
     */
    public function isLimited(string $action, int $limit): bool
    {
        if ($limit <= 0) {
            return false; // 0 = disabled
        }

        $ip = $this->getClientIp();
        $limits = $this->getRateLimits();
        $windowStart = time() - self::WINDOW_SECONDS;

        // Clean old entries
        if (isset($limits[$ip][$action])) {
            $limits[$ip][$action] = array_filter(
                $limits[$ip][$action],
                fn(int $timestamp): bool => $timestamp > $windowStart
            );
        }

        $count = count($limits[$ip][$action] ?? []);
        return $count >= $limit;
    }

    /**
     * Record an action for rate limiting
     */
    public function record(string $action): void
    {
        $ip = $this->getClientIp();
        $limits = $this->getRateLimits();

        if (!isset($limits[$ip])) {
            $limits[$ip] = [];
        }
        if (!isset($limits[$ip][$action])) {
            $limits[$ip][$action] = [];
        }

        $limits[$ip][$action][] = time();
        $this->saveRateLimits($limits);
    }

    /**
     * Get client IP address
     */
    private function getClientIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
