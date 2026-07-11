<?php
/**
 * Output-buffer chrome for legacy module student_nav.php includes.
 * Child pages own the document shell; this injects title + favicons on flush.
 */
declare(strict_types=1);

require_once __DIR__ . '/page_meta.php';

$pageTitleForHead = isset($page_title) ? (string) $page_title : 'Student Portal';

ob_start(static function (string $html) use ($pageTitleForHead): string {
    return wuc_portal_inject_head_meta($html, $pageTitleForHead);
});
