<?php
declare(strict_types=1);
/**
 * Shared helpers for the TEVETA curriculum / assessment / licensing pages.
 * Assumes includes/transport.php has already run (auth + $db).
 */

if (!function_exists('tev_h')) {
    function tev_h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('tev_csrf_token')) {
    function tev_csrf_token(): string
    {
        if (empty($_SESSION['transport_csrf'])) {
            try {
                $_SESSION['transport_csrf'] = bin2hex(random_bytes(32));
            } catch (Throwable $e) {
                $_SESSION['transport_csrf'] = bin2hex(openssl_random_pseudo_bytes(32));
            }
        }
        return (string)$_SESSION['transport_csrf'];
    }
}

if (!function_exists('tev_verify_csrf')) {
    function tev_verify_csrf(): bool
    {
        return isset($_POST['csrf_token'])
            && hash_equals((string)($_SESSION['transport_csrf'] ?? ''), (string)$_POST['csrf_token']);
    }
}

if (!function_exists('tev_flash_set')) {
    function tev_flash_set(string $type, string $message): void
    {
        $_SESSION['tev_flash'] = ['type' => $type, 'message' => $message];
    }
}

if (!function_exists('tev_flash_get')) {
    function tev_flash_get(): ?array
    {
        if (!empty($_SESSION['tev_flash'])) {
            $f = $_SESSION['tev_flash'];
            unset($_SESSION['tev_flash']);
            return $f;
        }
        return null;
    }
}

if (!function_exists('tev_flash_render')) {
    function tev_flash_render(): string
    {
        $f = tev_flash_get();
        if (!$f) {
            return '';
        }
        $type = in_array($f['type'] ?? '', ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
        return '<div class="alert alert-' . $type . '">' . tev_h($f['message']) . '</div>';
    }
}

if (!function_exists('tev_table_exists')) {
    function tev_table_exists(mysqli $db, string $table): bool
    {
        $safe = $db->real_escape_string($table);
        $r = @$db->query("SHOW TABLES LIKE '{$safe}'");
        return $r && $r->num_rows > 0;
    }
}

if (!function_exists('tev_redirect_self')) {
    function tev_redirect_self(): void
    {
        $t = (string)($_SERVER['REQUEST_URI'] ?? 'transport_management.php');
        if (!headers_sent()) {
            header('Location: ' . $t);
            exit;
        }
        echo '<script>location.href=' . json_encode($t) . ';</script>';
        exit;
    }
}

if (!function_exists('tev_redirect_to')) {
    function tev_redirect_to(string $url): void
    {
        if (!headers_sent()) {
            header('Location: ' . $url);
            exit;
        }
        echo '<script>location.href=' . json_encode($url) . ';</script>';
        exit;
    }
}
