<?php
declare(strict_types=1);

/**
 * Safe Markdown -> HTML renderer for AI output panels.
 *
 * AI features return Markdown (headings, **bold**, tables, lists, code). Printing
 * that raw shows literal `###`, `**`, `| pipes |`. This renders a SAFE subset to
 * styled HTML.
 *
 * Security: the entire input is HTML-escaped FIRST, so the only real HTML in the
 * output is the tags this renderer generates. No user/AI text can inject markup.
 */

if (!function_exists('wuc_ai_md_inline')) {
    function wuc_ai_md_inline(string $escaped): string
    {
        // Inline code first so its content isn't touched by bold/italic.
        $escaped = preg_replace_callback('/`([^`]+)`/', static function ($m) {
            return '<code>' . $m[1] . '</code>';
        }, $escaped) ?? $escaped;

        // Bold: **text** or __text__
        $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $escaped) ?? $escaped;

        // Italic: *text* (not part of **) and _text_
        $escaped = preg_replace('/(?<!\*)\*(?!\*)([^*\n]+?)\*(?!\*)/', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<![\w])_([^_\n]+?)_(?![\w])/', '<em>$1</em>', $escaped) ?? $escaped;

        return $escaped;
    }
}

if (!function_exists('wuc_ai_md_is_table_sep')) {
    function wuc_ai_md_is_table_sep(string $line): bool
    {
        // e.g. |---|:--:|---| or --- | ---
        return (bool)preg_match('/^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)+\|?\s*$/', $line);
    }
}

if (!function_exists('wuc_ai_md_split_row')) {
    function wuc_ai_md_split_row(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line);
        $line = preg_replace('/\|$/', '', $line);
        $cells = explode('|', (string)$line);
        return array_map('trim', $cells);
    }
}

if (!function_exists('wuc_ai_render_markdown')) {
    function wuc_ai_render_markdown(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", trim($md));

        // Normalize list formatting for multiple choice questions and raw/escaped breaks
        $md = preg_replace('/<br\s*\/?>/i', "\n", $md);
        $md = preg_replace('/&lt;br\s*\/?&gt;/i', "\n", $md);
        $md = preg_replace('/(?:\t|\s{2,}|(?<=[?.])\s+)([A-Ga-g][.)])/i', "\n$1", $md);
        $md = preg_replace('/(?<!\n)\n(\d+[\s.)])/i', "\n\n$1", $md);
        $md = str_replace("\t", " ", $md);

        if ($md === '') {
            return '';
        }

        // Escape everything up front — generated tags are the only real HTML.
        $escaped = htmlspecialchars($md, ENT_QUOTES, 'UTF-8');
        $lines = explode("\n", $escaped);
        $n = count($lines);
        $html = [];

        $listType = null;        // 'ul' | 'ol' | null
        $paragraph = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph !== []) {
                $html[] = '<p>' . wuc_ai_md_inline(implode('<br>', $paragraph)) . '</p>';
                $paragraph = [];
            }
        };
        $closeList = static function () use (&$listType, &$html): void {
            if ($listType !== null) {
                $html[] = '</' . $listType . '>';
                $listType = null;
            }
        };

        for ($i = 0; $i < $n; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);

            // Fenced code block ```
            if (preg_match('/^```/', $trimmed)) {
                $flushParagraph();
                $closeList();
                $code = [];
                $i++;
                while ($i < $n && !preg_match('/^```/', trim($lines[$i]))) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $html[] = '<pre><code>' . implode("\n", $code) . '</code></pre>';
                continue;
            }

            // Blank line -> paragraph / list break
            if ($trimmed === '') {
                $flushParagraph();
                $closeList();
                continue;
            }

            // Horizontal rule
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $trimmed)) {
                $flushParagraph();
                $closeList();
                $html[] = '<hr>';
                continue;
            }

            // Table: current line has a pipe and the next line is a separator row
            if (strpos($trimmed, '|') !== false && $i + 1 < $n && wuc_ai_md_is_table_sep($lines[$i + 1])) {
                $flushParagraph();
                $closeList();
                $headerCells = wuc_ai_md_split_row($line);
                $i += 2; // skip header + separator
                $body = [];
                while ($i < $n && trim($lines[$i]) !== '' && strpos($lines[$i], '|') !== false) {
                    $body[] = wuc_ai_md_split_row($lines[$i]);
                    $i++;
                }
                $i--; // step back so the loop's i++ lands correctly

                $t = '<div class="ai-table-wrap"><table class="ai-table"><thead><tr>';
                foreach ($headerCells as $c) {
                    $t .= '<th>' . wuc_ai_md_inline($c) . '</th>';
                }
                $t .= '</tr></thead><tbody>';
                foreach ($body as $row) {
                    $t .= '<tr>';
                    foreach ($row as $c) {
                        $t .= '<td>' . wuc_ai_md_inline($c) . '</td>';
                    }
                    $t .= '</tr>';
                }
                $t .= '</tbody></table></div>';
                $html[] = $t;
                continue;
            }

            // Headings ###### .. #
            if (preg_match('/^(#{1,6})\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                $closeList();
                $level = strlen($m[1]);
                // Map to h3..h6 so AI output never outranks the page title (h1/h2).
                $tag = 'h' . min(6, max(3, $level + 2));
                $html[] = "<{$tag}>" . wuc_ai_md_inline(trim($m[2])) . "</{$tag}>";
                continue;
            }

            // Blockquote
            if (preg_match('/^&gt;\s?(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                $closeList();
                $html[] = '<blockquote>' . wuc_ai_md_inline(trim($m[1])) . '</blockquote>';
                continue;
            }

            // Ordered list
            if (preg_match('/^\d+[.)]\s+(.*)$/', $trimmed, $m)) {
                $flushParagraph();
                if ($listType !== 'ol') {
                    $closeList();
                    $html[] = '<ol>';
                    $listType = 'ol';
                }
                $html[] = '<li>' . wuc_ai_md_inline(trim($m[1])) . '</li>';
                continue;
            }

            // Unordered list (-, *, +, •)
            if (preg_match('/^([-*+]|&bull;|•)\s+(.*)$/u', $trimmed, $m)) {
                $flushParagraph();
                if ($listType !== 'ul') {
                    $closeList();
                    $html[] = '<ul>';
                    $listType = 'ul';
                }
                $html[] = '<li>' . wuc_ai_md_inline(trim($m[2])) . '</li>';
                continue;
            }

            // Plain text -> accumulate into a paragraph
            if ($listType !== null) {
                $closeList();
            }
            $paragraph[] = $trimmed;
        }

        $flushParagraph();
        $closeList();

        return implode("\n", $html);
    }
}

if (!function_exists('wuc_ai_output_styles')) {
    /**
     * Scoped styles for .ai-output. Printed once per request (static guard).
     */
    function wuc_ai_output_styles(): string
    {
        static $printed = false;
        if ($printed) {
            return '';
        }
        $printed = true;
        return <<<CSS
<style>
.ai-output{font-family:'Inter',system-ui,sans-serif;font-size:.95rem;line-height:1.7;color:#2b2f36;word-wrap:break-word;overflow-wrap:anywhere;}
.ai-output > :first-child{margin-top:0;}
.ai-output > :last-child{margin-bottom:0;}
.ai-output h3,.ai-output h4,.ai-output h5,.ai-output h6{font-weight:600;line-height:1.3;margin:1.1em 0 .5em;color:#1f2330;}
.ai-output h3{font-size:1.2rem;} .ai-output h4{font-size:1.08rem;} .ai-output h5{font-size:1rem;} .ai-output h6{font-size:.92rem;color:#4b5563;}
.ai-output p{margin:0 0 .85em;}
.ai-output ul,.ai-output ol{margin:0 0 .85em;padding-left:1.4em;}
.ai-output li{margin:.25em 0;}
.ai-output strong{font-weight:600;color:#1f2330;}
.ai-output code{background:#f1edf9;color:#5a32a3;padding:.12em .4em;border-radius:4px;font-size:.88em;font-family:'SFMono-Regular',Consolas,'Liberation Mono',monospace;}
.ai-output pre{background:#1f2330;color:#f3f0fa;padding:14px 16px;border-radius:8px;overflow-x:auto;margin:0 0 .9em;}
.ai-output pre code{background:none;color:inherit;padding:0;font-size:.85rem;}
.ai-output blockquote{border-left:3px solid #6f42c1;background:#f7f5fb;margin:0 0 .9em;padding:.5em .9em;color:#444;border-radius:0 6px 6px 0;}
.ai-output hr{border:0;border-top:1px solid #e6e1f0;margin:1.1em 0;}
.ai-output .ai-table-wrap{overflow-x:auto;margin:0 0 1em;border-radius:8px;border:1px solid #e6e1f0;}
.ai-output table.ai-table{width:100%;border-collapse:collapse;font-size:.9rem;}
.ai-output table.ai-table th{background:#6f42c1;color:#fff;text-align:left;padding:9px 12px;font-weight:600;white-space:nowrap;}
.ai-output table.ai-table td{padding:8px 12px;border-top:1px solid #ece8f5;vertical-align:top;}
.ai-output table.ai-table tbody tr:nth-child(even){background:#faf9fd;}
.ai-output a{color:#6f42c1;text-decoration:underline;}
</style>
CSS;
    }
}

if (!function_exists('wuc_ai_output_block')) {
    /**
     * Render AI Markdown into a styled, safe HTML block. Includes the scoped
     * stylesheet on first call per request.
     */
    function wuc_ai_output_block(string $md, string $extraClass = ''): string
    {
        $cls = trim('ai-output ' . $extraClass);
        $body = wuc_ai_render_markdown($md);
        if ($body === '') {
            $body = '<p class="text-muted mb-0">No content.</p>';
        }
        return wuc_ai_output_styles() . '<div class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '">' . $body . '</div>';
    }
}
