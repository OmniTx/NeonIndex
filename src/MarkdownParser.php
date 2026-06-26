<?php

declare(strict_types=1);

namespace NeonIndex\Service;

/**
 * Robust Markdown Parser for NeonIndex
 * Supports GitHub-flavored features: code blocks, tables, task lists, blockquotes, etc.
 */
class MarkdownParser
{
    private array $codeBlocks = [];
    private array $inlineCodeBlocks = [];

    public function parse(string $text): string
    {
        $this->codeBlocks = [];
        $this->inlineCodeBlocks = [];

        // Normalize line endings
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Extract fenced code blocks first so they don't get processed
        $text = preg_replace_callback('/^```(\w*)\s*\n(.*?)\n```\s*$/ms', function ($matches) {
            $id = "\x00CODEBLOCK_" . count($this->codeBlocks) . "\x00";
            $lang = $matches[1] ? htmlspecialchars(trim($matches[1]), ENT_QUOTES, 'UTF-8') : '';
            $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
            $langAttr = $lang ? " class=\"language-$lang\"" : '';
            $langLabel = $lang ? "<div style='position:absolute;top:0;right:0;padding:2px 10px;font-size:0.7em;color:var(--text-secondary);text-transform:uppercase;letter-spacing:0.05em'>$lang</div>" : '';
            $this->codeBlocks[$id] = "<div class='position-relative'>{$langLabel}<pre class='p-3 rounded mb-3 overflow-auto' style='background:var(--bg-secondary); border:1px solid var(--border)'><code{$langAttr} style='font-family:\"SF Mono\",SFMono-Regular,ui-monospace,Menlo,Monaco,monospace;font-size:0.85em;white-space:pre'>{$code}</code></pre></div>";
            return $id;
        }, $text) ?? $text;

        // Also handle inline ``` style without newlines (single-line code fence)
        $text = preg_replace_callback('/```(\w*)\s+(.*?)```/s', function ($matches) {
            $id = "\x00CODEBLOCK_" . count($this->codeBlocks) . "\x00";
            $code = htmlspecialchars($matches[2], ENT_QUOTES, 'UTF-8');
            $this->codeBlocks[$id] = "<pre class='p-3 rounded mb-3 overflow-auto' style='background:var(--bg-secondary); border:1px solid var(--border)'><code style='font-family:\"SF Mono\",SFMono-Regular,ui-monospace,Menlo,Monaco,monospace;font-size:0.85em;white-space:pre'>{$code}</code></pre>";
            return $id;
        }, $text) ?? $text;

        // Extract inline code BEFORE block processing so backticks don't interfere
        $text = preg_replace_callback('/`([^`\n]+?)`/', function ($matches) {
            $id = "\x00ICODE_" . count($this->inlineCodeBlocks) . "\x00";
            $code = htmlspecialchars($matches[1], ENT_QUOTES, 'UTF-8');
            $this->inlineCodeBlocks[$id] = "<code class='px-1 py-0 rounded' style='background:var(--bg-tertiary);color:var(--accent);font-family:\"SF Mono\",SFMono-Regular,ui-monospace,monospace;font-size:0.9em'>{$code}</code>";
            return $id;
        }, $text) ?? $text;

        // Split text into blocks separated by double newlines
        $blocks = preg_split('/\n{2,}/', $text);
        $htmlBlocks = [];

        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') continue;

            // Check for code block placeholder
            if (preg_match('/^\x00CODEBLOCK_\d+\x00$/', $block)) {
                $htmlBlocks[] = $this->codeBlocks[$block] ?? '';
                continue;
            }

            // Headings
            if (preg_match('/^(#{1,6})\s+(.+)$/m', $block, $m)) {
                $level = strlen($m[1]);
                $content = $this->parseInline($m[2]);
                $sizes = [1 => '1.8em', 2 => '1.4em', 3 => '1.17em', 4 => '1em', 5 => '0.9em', 6 => '0.8em'];
                $size = $sizes[$level] ?? '1em';
                $htmlBlocks[] = "<h{$level} style='font-weight:600;font-size:{$size};margin-top:1.2em;margin-bottom:0.6em;letter-spacing:-0.02em'>{$content}</h{$level}>";
                continue;
            }

            // Horizontal Rules
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', trim($block))) {
                $htmlBlocks[] = "<hr style='border:0;border-top:1px solid var(--border);margin:1.5em 0'>";
                continue;
            }

            // Blockquotes
            if (preg_match('/^>/m', $block)) {
                $lines = explode("\n", $block);
                $quote = '';
                foreach ($lines as $line) {
                    $quote .= preg_replace('/^>\s?/', '', $line) . "\n";
                }
                $quoteHtml = $this->parseInline(trim($quote));
                $htmlBlocks[] = "<blockquote style='border-left:4px solid var(--accent);padding:0.5em 1em;margin:0.8em 0;color:var(--text-secondary);background:var(--bg-secondary);border-radius:0 var(--radius-sm) var(--radius-sm) 0'>{$quoteHtml}</blockquote>";
                continue;
            }

            // Unordered Lists
            if (preg_match('/^[\-\*\+]\s+/m', $block)) {
                $lines = explode("\n", $block);
                $listHtml = "<ul style='margin-bottom:1em;padding-left:1.5em'>\n";
                foreach ($lines as $line) {
                    if (preg_match('/^[\-\*\+]\s+(.+)$/', trim($line), $m)) {
                        $listHtml .= "<li style='margin-bottom:0.25em'>" . $this->parseInline($m[1]) . "</li>\n";
                    }
                }
                $listHtml .= "</ul>";
                $htmlBlocks[] = $listHtml;
                continue;
            }

            // Ordered Lists
            if (preg_match('/^\d+\.\s+/m', $block)) {
                $lines = explode("\n", $block);
                $listHtml = "<ol style='margin-bottom:1em;padding-left:1.5em'>\n";
                foreach ($lines as $line) {
                    if (preg_match('/^\d+\.\s+(.+)$/', trim($line), $m)) {
                        $listHtml .= "<li style='margin-bottom:0.25em'>" . $this->parseInline($m[1]) . "</li>\n";
                    }
                }
                $listHtml .= "</ol>";
                $htmlBlocks[] = $listHtml;
                continue;
            }

            // Tables
            if (strpos($block, '|') !== false && strpos($block, '---') !== false) {
                $lines = explode("\n", $block);
                if (count($lines) >= 3) {
                    $tableHtml = "<div style='overflow-x:auto;margin-bottom:1em'><table style='width:100%;border-collapse:collapse'>\n";
                    foreach ($lines as $i => $line) {
                        $line = trim($line, '| ');
                        if ($i === 1 && preg_match('/^[-:| ]+$/', $line)) {
                            continue;
                        }
                        $cells = array_map('trim', explode('|', $line));
                        $tag = ($i === 0) ? 'th' : 'td';
                        $bgStyle = ($i === 0) ? 'background:var(--bg-secondary);font-weight:600;' : '';
                        $tableHtml .= "<tr>";
                        foreach ($cells as $cell) {
                            $tableHtml .= "<{$tag} style='{$bgStyle}padding:0.5em 0.75em;border:1px solid var(--border)'>" . $this->parseInline($cell) . "</{$tag}>";
                        }
                        $tableHtml .= "</tr>\n";
                    }
                    $tableHtml .= "</table></div>";
                    $htmlBlocks[] = $tableHtml;
                    continue;
                }
            }

            // Paragraph (default)
            $blockHtml = $this->parseInline($block);
            // Smart line breaks: don't insert <br> between consecutive image-only lines
            // so that badges/shields display side-by-side
            $paraLines = explode("\n", $blockHtml);
            $result = '';
            for ($i = 0; $i < count($paraLines); $i++) {
                $result .= $paraLines[$i];
                if ($i < count($paraLines) - 1) {
                    $currentIsImg = preg_match('/^\s*(<a[^>]*>)?\s*<img\s[^>]+>\s*(<\/a>)?\s*$/', $paraLines[$i]);
                    $nextIsImg = preg_match('/^\s*(<a[^>]*>)?\s*<img\s[^>]+>\s*(<\/a>)?\s*$/', $paraLines[$i + 1]);
                    if ($currentIsImg && $nextIsImg) {
                        $result .= " "; // space instead of <br>, keeps badges inline
                    } else {
                        $result .= "<br>\n";
                    }
                }
            }
            $htmlBlocks[] = "<p style='margin-bottom:0.8em;line-height:1.7'>{$result}</p>";
        }

        $html = implode("\n", $htmlBlocks);

        // Restore code block and inline code placeholders
        $html = strtr($html, $this->codeBlocks);
        $html = strtr($html, $this->inlineCodeBlocks);

        return $html;
    }

    private function parseInline(string $text): string
    {
        // Escape HTML first
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // Restore inline code placeholders (they were already escaped)
        // They contain \x00 which survives htmlspecialchars
        // We leave them as placeholders and restore later

        // Images: ![alt](url)
        $text = preg_replace(
            '/!\[([^\]]*)\]\(([^)]+)\)/',
            '<img src="$2" alt="$1" style="max-width:100%;height:auto;border-radius:6px;margin:0.3em 0;display:inline-block;vertical-align:middle">',
            $text
        );

        // Links: [text](url)
        $text = preg_replace(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            '<a href="$2" style="color:var(--accent);text-decoration:none" target="_blank" rel="noopener noreferrer">$1</a>',
            $text
        );

        // Bold: **text** or __text__
        $text = preg_replace('/(\*\*|__)(.+?)\1/', '<strong>$2</strong>', $text);

        // Italic: *text* or _text_  (but not inside words for _)
        $text = preg_replace('/(?<!\w)\*([^\*\n]+?)\*(?!\w)/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<!\w)_([^_\n]+?)_(?!\w)/', '<em>$1</em>', $text);

        // Task lists: [ ] or [x]
        $text = str_replace(
            ['[ ]', '[x]', '[X]'],
            [
                '<input type="checkbox" disabled style="margin-right:0.3em;vertical-align:middle">',
                '<input type="checkbox" checked disabled style="margin-right:0.3em;vertical-align:middle">',
                '<input type="checkbox" checked disabled style="margin-right:0.3em;vertical-align:middle">'
            ],
            $text
        );

        return $text;
    }
}
