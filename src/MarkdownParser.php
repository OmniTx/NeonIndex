<?php

declare(strict_types=1);

namespace NeonIndex\Service;

/**
 * Robust Markdown Parser for NeonIndex
 * Supports GitHub-flavored features: code blocks, tables, task lists, blockquotes, etc.
 */
class MarkdownParser
{
    public function parse(string $text): string
    {
        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Extract fenced code blocks first so they don't get processed by inline/block logic
        $codeBlocks = [];
        $text = preg_replace_callback('/```(\w*)\n(.*?)\n```/s', function ($matches) use (&$codeBlocks) {
            $id = '<!--CODEBLOCK_' . count($codeBlocks) . '-->';
            $lang = $matches[1] ? htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8') : '';
            $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
            $class = $lang ? " class=\"language-$lang\"" : '';
            $codeBlocks[$id] = "<pre class='p-3 rounded mb-3 overflow-auto' style='background:var(--bg-secondary); border:1px solid var(--border)'><code{$class} style='font-family:\"SF Mono\",SFMono-Regular,ui-monospace,Menlo,Monaco,monospace;font-size:0.85em'>{$code}</code></pre>";
            return "\n\n" . $id . "\n\n";
        }, $text);

        // Split text into blocks separated by double newlines
        $blocks = preg_split('/\n{2,}/', $text);
        $htmlBlocks = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') continue;

            // Restore Code Blocks
            if (preg_match('/^<!--CODEBLOCK_\d+-->$/', $block)) {
                $htmlBlocks[] = $codeBlocks[$block];
                continue;
            }

            // Headings
            if (preg_match('/^(#{1,6})\s+(.+)$/s', $block, $m)) {
                $level = strlen($m[1]);
                $content = $this->parseInline($m[2]);
                $htmlBlocks[] = "<h{$level} class='fw-bold mt-4 mb-3'>{$content}</h{$level}>";
                continue;
            }

            // Horizontal Rules
            if (preg_match('/^(---|\*\*\*|___)$/', $block)) {
                $htmlBlocks[] = "<hr class='my-4' style='border-color:var(--border)'>";
                continue;
            }

            // Blockquotes
            if (strpos($block, '>') === 0) {
                $lines = explode("\n", $block);
                $quote = '';
                foreach ($lines as $line) {
                    $quote .= preg_replace('/^>\s?/', '', $line) . "\n";
                }
                $quoteHtml = $this->parseInline(trim($quote));
                $htmlBlocks[] = "<blockquote class='border-start border-4 ps-3 py-1 my-3' style='border-color:var(--border)!important; color:var(--text-secondary)'>{$quoteHtml}</blockquote>";
                continue;
            }

            // Unordered Lists
            if (preg_match('/^[-*+]\s+/m', $block)) {
                $lines = explode("\n", $block);
                $listHtml = "<ul class='mb-3 ps-4'>\n";
                foreach ($lines as $line) {
                    if (preg_match('/^[-*+]\s+(.+)$/', trim($line), $m)) {
                        $listHtml .= "<li class='mb-1'>" . $this->parseInline($m[1]) . "</li>\n";
                    } elseif (trim($line) !== '') {
                        // Continuation of previous li
                        $listHtml .= "<br>" . $this->parseInline(trim($line));
                    }
                }
                $listHtml .= "</ul>";
                $htmlBlocks[] = $listHtml;
                continue;
            }

            // Ordered Lists
            if (preg_match('/^\d+\.\s+/m', $block)) {
                $lines = explode("\n", $block);
                $listHtml = "<ol class='mb-3 ps-4'>\n";
                foreach ($lines as $line) {
                    if (preg_match('/^\d+\.\s+(.+)$/', trim($line), $m)) {
                        $listHtml .= "<li class='mb-1'>" . $this->parseInline($m[1]) . "</li>\n";
                    } elseif (trim($line) !== '') {
                        $listHtml .= "<br>" . $this->parseInline(trim($line));
                    }
                }
                $listHtml .= "</ol>";
                $htmlBlocks[] = $listHtml;
                continue;
            }

            // Tables (simple support)
            if (strpos($block, '|') !== false && strpos($block, '---') !== false) {
                $lines = explode("\n", $block);
                if (count($lines) >= 3) {
                    $tableHtml = "<div class='table-responsive mb-3'><table class='table table-bordered table-sm'>\n";
                    foreach ($lines as $i => $line) {
                        $line = trim($line, '| ');
                        if ($i === 1 && preg_match('/^[-:| ]+$/', $line)) {
                            continue; // Skip separator line
                        }
                        $cells = explode('|', $line);
                        $tag = ($i === 0) ? 'th' : 'td';
                        $tableHtml .= "<tr>";
                        foreach ($cells as $cell) {
                            $tableHtml .= "<{$tag}>" . $this->parseInline(trim($cell)) . "</{$tag}>";
                        }
                        $tableHtml .= "</tr>\n";
                    }
                    $tableHtml .= "</table></div>";
                    $htmlBlocks[] = $tableHtml;
                    continue;
                }
            }

            // Paragraph
            $blockHtml = $this->parseInline($block);
            $blockHtml = nl2br($blockHtml);
            $htmlBlocks[] = "<p class='mb-3'>{$blockHtml}</p>";
        }

        return implode("\n", $htmlBlocks);
    }

    private function parseInline(string $text): string
    {
        // Escape HTML
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Images: ![alt](url)
        $text = preg_replace(
            '/!\[([^\]]*)\]\(([^)]+)\)/',
            '<img src="$2" alt="$1" class="img-fluid rounded my-2" style="max-height: 500px; display: block;">',
            $text
        );

        // Links: [text](url)
        $text = preg_replace(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            '<a href="$2" class="text-accent text-decoration-none" target="_blank" rel="noopener noreferrer">$1</a>',
            $text
        );

        // Bold: **text** or __text__
        $text = preg_replace('/(\*\*|__)(.+?)\1/', '<strong>$2</strong>', $text);

        // Italic: *text* or _text_
        $text = preg_replace('/(\*|_)(?=\S)([^\*\_]+?)(?<=\S)\1/', '<em>$2</em>', $text);

        // Inline code: `code`
        $text = preg_replace('/`(.+?)`/', '<code class="px-1 py-0 rounded" style="background:var(--bg-tertiary);color:var(--accent);font-family:\'SF Mono\',SFMono-Regular,ui-monospace,monospace;font-size:0.9em">$1</code>', $text);

        // Task lists: [ ] or [x]
        $text = str_replace(
            ['[ ]', '[x]', '[X]'], 
            ['<input type="checkbox" class="form-check-input mt-0" disabled>', '<input type="checkbox" class="form-check-input mt-0" checked disabled>', '<input type="checkbox" class="form-check-input mt-0" checked disabled>'], 
            $text
        );

        return $text;
    }
}
