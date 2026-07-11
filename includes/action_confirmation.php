<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_helpers.php';

function wuc_render_action_confirmation(string $title, string $message, string $action, array $fields): void
{
    wuc_security_headers();
    $token = wuc_csrf_token();
    http_response_code(200);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
    echo '<style>body{font-family:Inter,Arial,sans-serif;background:#f5f3ff;margin:0;padding:3rem 1rem}.card{max-width:34rem;margin:auto;background:#fff;padding:2rem;border-radius:12px;box-shadow:0 8px 30px #0002}.actions{display:flex;gap:.75rem;margin-top:1.5rem}button,a{padding:.7rem 1rem;border-radius:7px;text-decoration:none;border:0;font-weight:600}button{background:#b91c1c;color:#fff;cursor:pointer}a{background:#e5e7eb;color:#111827}</style></head><body><main class="card">';
    echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">';
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    foreach ($fields as $name => $value) {
        echo '<input type="hidden" name="' . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
    }
    echo '<div class="actions"><button type="submit">Confirm</button><a href="javascript:history.back()">Cancel</a></div></form></main></body></html>';
    exit;
}
