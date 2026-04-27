<?php

declare(strict_types=1);

namespace NeonIndex\Service;

/**
 * Minimal Markdown Parser for NeonIndex
 * Supports headings, bold, italic, code, links, images, and lists
 */
class MarkdownParser
{
    /**
     * Parse markdown text to HTML
     */
    public function parse(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $html = '';
        $imageBuffer = [];

        foreach ($lines as $line) {
            $parsedLine = $this->parseInline($line, true);

            // Group consecutive image-only lines
            if (preg_match('/^<img[^>]+>$/', trim($parsedLine))) {
                $imageBuffer[] = $parsedLine;
            } else {
                if (!empty($imageBuffer)) {
                    $html .= '<div class="d-flex flex-wrap gap-2 my-2">' . implode('', $imageBuffer) . '</div>';
                    $imageBuffer = [];
                }
                $html .= $this->parseInline($line, false) . "\n";
            }
        }

        // Flush remaining buffered images
        if (!empty($imageBuffer)) {
            $html .= '<div class="d-flex flex-wrap gap-2 my-2">' . implode('', $imageBuffer) . '</div>';
        }

        return $html;
    }

    /**
     * Parse a single line with inline formatting
     */
    private function parseInline(string $line, bool $checkOnly = false): string
    {
        // Headings
        if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches)) {
            $level = strlen($matches[1]);
            $content = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
            return "<h{$level} class='text-info fw-bold mt-3 mb-2'>{$content}</h{$level}>";
        }

        // Escape HTML to prevent XSS
        $line = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

        // Inline formatting
        $line = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $line);
        $line = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $line);
        $line = preg_replace('/`(.+?)`/', '<code class="bg-secondary px-1 rounded">$1</code>', $line);
        $line = preg_replace(
            '/!\[([^\]]*)\]\(([^)]+)\)/',
            '<img src="$2" alt="$1" class="rounded" style="height: 28px;">',
            $line
        );
        $line = preg_replace(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            '<a href="$2" class="text-info" target="_blank" rel="noopener noreferrer">$1</a>',
            $line
        );

        if ($checkOnly) {
            return $line;
        }

        // Lists
        if (preg_match('/^[-*]\s+(.+)$/', $line, $matches)) {
            return '<li class="ms-3">' . $matches[1] . '</li>';
        }

        // Empty lines
        if (trim($line) === '') {
            return '<br>';
        }

        return '<p class="mb-1">' . $line . '</p>';
    }
}
