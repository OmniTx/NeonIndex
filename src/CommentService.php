<?php

declare(strict_types=1);

namespace NeonIndex\Service;

/**
 * Comment Service for NeonIndex
 * Handles visitor comment submission and management
 */
class CommentService
{
    private string $commentsFile;
    private RateLimiter $rateLimiter;
    private int $rateLimit;

    public function __construct(string $commentsFile, RateLimiter $rateLimiter, int $rateLimit)
    {
        $this->commentsFile = $commentsFile;
        $this->rateLimiter = $rateLimiter;
        $this->rateLimit = $rateLimit;
    }

    /**
     * Get all comments
     */
    public function getAll(): array
    {
        if (!file_exists($this->commentsFile)) {
            return [];
        }
        
        $content = file_get_contents($this->commentsFile);
        if ($content === false) {
            return [];
        }
        
        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save all comments
     */
    public function saveAll(array $comments): bool
    {
        return file_put_contents($this->commentsFile, json_encode($comments, JSON_PRETTY_PRINT), LOCK_EX) !== false;
    }

    /**
     * Submit a new comment
     */
    public function submit(string $name, string $email, string $message): array
    {
        // Check rate limit
        if ($this->rateLimit > 0 && $this->rateLimiter->isLimited('comment', $this->rateLimit)) {
            return ['success' => false, 'message' => 'Comment rate limit exceeded. Try again later.'];
        }

        $message = trim($message);
        if ($message === '') {
            return ['success' => false, 'message' => 'Comment message is required.'];
        }

        $comments = $this->getAll();
        $comments[] = [
            'name' => trim($name) ?: 'Anonymous',
            'email' => trim($email),
            'message' => $message,
            'date' => date('Y-m-d H:i:s'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ];

        if ($this->saveAll($comments)) {
            $this->rateLimiter->record('comment');
            return ['success' => true, 'message' => 'Comment submitted! Thank you.'];
        }

        return ['success' => false, 'message' => 'Failed to submit comment.'];
    }

    /**
     * Delete a comment by index (admin only)
     */
    public function delete(int $index): bool
    {
        $comments = $this->getAll();
        
        if (!isset($comments[$index])) {
            return false;
        }

        array_splice($comments, $index, 1);
        return $this->saveAll($comments);
    }

    /**
     * Clear all comments
     */
    public function clearAll(): bool
    {
        return $this->saveAll([]);
    }
}
