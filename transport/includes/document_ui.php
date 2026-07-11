<?php
declare(strict_types=1);
/**
 * Small styled message page for the transport document endpoints
 * (certificate.php / invoice.php / receipt.php). Replaces bare plain-text
 * exit() bodies with a page consistent with the module's look, so trainees
 * and staff always see a professional, actionable message.
 */

function tdoc_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Render a standalone message page and exit.
 *
 * @param int    $httpCode  HTTP status code to send.
 * @param string $title     <title> and page heading.
 * @param string $message   Plain-text explanation (escaped here).
 * @param string $tone      'info' | 'warning' | 'danger' | 'success'.
 * @param string $extraHtml Optional pre-escaped HTML block (e.g. a POST form)
 *                          rendered below the message. Caller escapes values.
 */
function tdoc_message_page(int $httpCode, string $title, string $message, string $tone = 'info', string $extraHtml = ''): void
{
    $palette = [
        'info'    => ['#1B2A4A', 'fa-circle-info'],
        'warning' => ['#b54708', 'fa-triangle-exclamation'],
        'danger'  => ['#b42318', 'fa-circle-xmark'],
        'success' => ['#1a7f37', 'fa-circle-check'],
    ];
    [$accent, $icon] = $palette[$tone] ?? $palette['info'];

    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . tdoc_h($title) . '</title>'
        . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">'
        . '<style>'
        . 'body{font-family:Helvetica,Arial,sans-serif;background:#f4f5f7;color:#1f2a44;margin:0;}'
        . '.card{max-width:560px;margin:64px auto;background:#fff;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);'
        . 'border-top:6px solid ' . $accent . ';padding:36px 40px;}'
        . '.brand{font-size:13px;letter-spacing:2px;text-transform:uppercase;color:#6b7280;margin-bottom:14px;}'
        . 'h1{font-size:21px;margin:0 0 10px;color:#1B2A4A;} h1 i{color:' . $accent . ';margin-right:8px;}'
        . 'p{font-size:14px;line-height:1.65;color:#374151;margin:0 0 6px;}'
        . '.actions{margin-top:22px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;}'
        . '.btn{display:inline-block;background:#1B2A4A;color:#fff;text-decoration:none;padding:9px 18px;border-radius:6px;'
        . 'font-size:13px;border:none;cursor:pointer;}'
        . '.btn.secondary{background:#fff;color:#1B2A4A;border:1px solid #c9cedb;}'
        . '.btn.primary-action{background:' . $accent . ';}'
        . '</style></head><body><div class="card">'
        . '<div class="brand">ITC Industrial Training Centre &mdash; Transport</div>'
        . '<h1><i class="fas ' . $icon . '"></i>' . tdoc_h($title) . '</h1>'
        . '<p>' . tdoc_h($message) . '</p>'
        . $extraHtml
        . '<div class="actions"><a class="btn secondary" href="/wucportal/transport/transport_management.php">'
        . '<i class="fas fa-arrow-left" style="margin-right:6px;"></i>Back to Transport Management</a></div>'
        . '</div></body></html>';
    exit;
}
