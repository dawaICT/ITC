<?php
/**
 * Shared browser tab title and favicon helpers for the ITC portal.
 *
 * Title conventions:
 *   - Authenticated pages:  "{Page} - ITC"
 *   - Public auth pages:    "{Page} | ITC Portal"
 *   - Fallback:             "ITC Portal"
 */

declare(strict_types=1);

if (!function_exists('wuc_portal_title')) {
    /**
     * Build a consistent document title.
     *
     * @param string $pageTitle Visible page name (empty = site default only).
     * @param string $style     'dash' = "Page - ITC", 'pipe' = "Page | ITC Portal"
     */
    function wuc_portal_title(string $pageTitle = '', string $style = 'dash'): string
    {
        $pageTitle = trim($pageTitle);
        if ($pageTitle === '') {
            return 'ITC Portal';
        }

        if ($style === 'pipe') {
            return $pageTitle . ' | ITC Portal';
        }

        return $pageTitle . ' - ITC';
    }
}

if (!function_exists('wuc_portal_favicon_links')) {
    /**
     * Echo standard favicon link tags. Safe to call multiple times per request.
     *
     * @param string $basePath Web path prefix without trailing slash (default /wucportal).
     */
    function wuc_portal_favicon_links(string $basePath = '/wucportal'): void
    {
        $base = rtrim($basePath, '/');
        $h = static fn(string $url): string => htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        echo '<link rel="icon" href="' . $h($base . '/images/favicon.ico') . '" sizes="any">' . "\n";
        echo '    <link rel="icon" type="image/png" sizes="32x32" href="' . $h($base . '/images/favicon-32.png') . '">' . "\n";
        echo '    <link rel="icon" type="image/png" sizes="16x16" href="' . $h($base . '/images/favicon-16.png') . '">' . "\n";
        echo '    <link rel="apple-touch-icon" href="' . $h($base . '/images/apple-touch-icon.png') . '">' . "\n";
    }
}

if (!function_exists('wuc_portal_favicon_links_html')) {
    /**
     * Return standard favicon link tags as a string (for output-buffer injection).
     */
    function wuc_portal_favicon_links_html(string $basePath = '/wucportal'): string
    {
        ob_start();
        wuc_portal_favicon_links($basePath);
        return (string) ob_get_clean();
    }
}

if (!function_exists('wuc_portal_inject_head_meta')) {
    /**
     * Ensure legacy HTML documents have a title and favicon links in <head>.
     *
     * @param string      $html       Full page HTML from an output buffer.
     * @param string|null $pageTitle  Page name; empty uses "ITC Portal".
     * @param string      $titleStyle 'dash' or 'pipe' (see wuc_portal_title).
     * @param string      $basePath   Web path prefix for favicon assets.
     */
    function wuc_portal_inject_head_meta(
        string $html,
        ?string $pageTitle = null,
        string $titleStyle = 'dash',
        string $basePath = '/wucportal'
    ): string {
        $docTitle = wuc_portal_title(trim((string) ($pageTitle ?? '')), $titleStyle);
        $titleTag = '<title>' . htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') . '</title>';
        $faviconTags = wuc_portal_favicon_links_html($basePath);

        if (preg_match('/<title>\s*<\/title>/i', $html)) {
            $html = preg_replace('/<title>\s*<\/title>/i', $titleTag, $html, 1) ?? $html;
        } elseif (!preg_match('/<title\b[^>]*>/i', $html) && stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', '    ' . $titleTag . "\n</head>", $html, 1) ?? $html;
        }

        if (!preg_match('/rel=["\']icon["\']/i', $html) && stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', '    ' . $faviconTags . '</head>', $html, 1) ?? $html;
        }

        // Repair student_nav shells that opened <body> inside <head>.
        if (preg_match('/<head\b[^>]*>/i', $html)
            && preg_match('/<body\b/i', $html)
            && !preg_match('/<\/head>/i', $html)) {
            $html = preg_replace('/<body\b/i', "</head>\n<body", $html, 1) ?? $html;
        }

        return $html;
    }
}
