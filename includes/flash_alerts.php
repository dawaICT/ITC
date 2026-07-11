<?php
/**
 * Shared flash / alert renderer.
 *
 * Additive, dependency-free partial that surfaces one-time session messages in a
 * single consistent, dismissible Bootstrap alert — regardless of which of the
 * portal's historical flash conventions set them. It reads and CLEARS each key
 * it renders, so a page's own inline alert block simply finds nothing left to
 * show (no double render). Existing pages keep working; this only guarantees a
 * message is never silently swallowed (e.g. redirects that land on a page which
 * never rendered the key).
 *
 * Usage: include once, high in the content area:
 *   require __DIR__ . '/flash_alerts.php';   // (path-adjust per caller)
 *
 * Set $wucSuppressFlash = true before including to skip (for pages that render
 * their flash in a bespoke location).
 */

if (!function_exists('wuc_render_flash_alerts')) {
    /**
     * Collect messages from every known session convention, newest priority
     * first, and emit standardized alert markup. Each source is cleared as it is
     * read so the message shows exactly once.
     *
     * @return string HTML (empty string when there is nothing to show)
     */
    function wuc_render_flash_alerts(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Never start a session just to render alerts on a public page.
            return '';
        }

        $normalizeType = static function (string $type): string {
            $type = strtolower(trim($type));
            switch ($type) {
                case 'success':
                    return 'success';
                case 'error':
                case 'danger':
                case 'fail':
                case 'failure':
                    return 'danger';
                case 'warning':
                case 'warn':
                    return 'warning';
                case 'info':
                case 'notice':
                default:
                    return 'info';
            }
        };

        $icons = [
            'success' => 'fa-circle-check',
            'danger'  => 'fa-circle-exclamation',
            'warning' => 'fa-triangle-exclamation',
            'info'    => 'fa-circle-info',
        ];

        /** @var array<int,array{type:string,message:string}> $messages */
        $messages = [];

        $push = static function ($type, $message) use (&$messages, $normalizeType): void {
            if ($message === null) {
                return;
            }
            if (is_array($message)) {
                // Some callers store ['type'=>..,'message'=>..]
                $type = $message['type'] ?? $type;
                $message = $message['message'] ?? '';
            }
            $message = trim((string)$message);
            if ($message === '') {
                return;
            }
            $messages[] = [
                'type'    => $normalizeType((string)$type),
                'message' => $message,
            ];
        };

        // 1. Canonical helper: $_SESSION['flash'] = ['type'=>..,'message'=>..]
        if (function_exists('wuc_get_flash')) {
            $flash = wuc_get_flash();
            if ($flash !== null) {
                $push($flash['type'] ?? 'info', $flash['message'] ?? '');
            }
        } elseif (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
            $push($_SESSION['flash']['type'] ?? 'info', $_SESSION['flash']['message'] ?? '');
            unset($_SESSION['flash']);
        }

        // 2. Staff success/error keys.
        if (isset($_SESSION['successMessage'])) {
            $push('success', $_SESSION['successMessage']);
            unset($_SESSION['successMessage']);
        }
        if (isset($_SESSION['errorMessage'])) {
            $push('danger', $_SESSION['errorMessage']);
            unset($_SESSION['errorMessage']);
        }

        // 3. Student/login key.
        if (isset($_SESSION['errorMssg'])) {
            $push('danger', $_SESSION['errorMssg']);
            unset($_SESSION['errorMssg']);
        }

        // 4. Fee-structure style: message + separate type.
        if (isset($_SESSION['flash_message'])) {
            $push($_SESSION['flash_type'] ?? 'info', $_SESSION['flash_message']);
            unset($_SESSION['flash_message'], $_SESSION['flash_type']);
        }

        // 5. Admissions module: flash_{error|success|warning|info}.
        foreach (['success', 'error', 'warning', 'info'] as $ftype) {
            $key = 'flash_' . $ftype;
            if (isset($_SESSION[$key])) {
                $push($ftype, $_SESSION[$key]);
                unset($_SESSION[$key]);
            }
        }

        // 6. Generic success/error aliases seen on a few pages.
        if (isset($_SESSION['success'])) {
            $push('success', $_SESSION['success']);
            unset($_SESSION['success']);
        }
        if (isset($_SESSION['error'])) {
            $push('danger', $_SESSION['error']);
            unset($_SESSION['error']);
        }

        if (empty($messages)) {
            return '';
        }

        $html = '<div class="wuc-flash-stack" role="status" aria-live="polite">';
        foreach ($messages as $entry) {
            $type = $entry['type'];
            $icon = $icons[$type] ?? $icons['info'];
            $html .= '<div class="alert alert-' . $type . ' alert-dismissible fade show wuc-flash-alert shadow-sm" role="alert">'
                . '<i class="fas ' . $icon . ' me-2" aria-hidden="true"></i>'
                . '<span class="wuc-flash-text">' . htmlspecialchars($entry['message'], ENT_QUOTES, 'UTF-8') . '</span>'
                . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>'
                . '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}

if (empty($wucSuppressFlash)) {
    echo wuc_render_flash_alerts();
}
