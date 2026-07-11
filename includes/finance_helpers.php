<?php
// Common helpers for Finance & Accounting module

require_once __DIR__ . '/session_guard.php';
require_once __DIR__ . '/staff_role_helpers.php';
require_once __DIR__ . '/json_response.php';
wuc_guard_start_session('finance');

require_once __DIR__ . '/../db/connect.php';
require_once __DIR__ . '/audit.php';

function json_success($data = [], $httpCode = 200) {
    wuc_json_success($data, (int) $httpCode);
}

function json_error($message, $httpCode = 400, $extra = []) {
    wuc_json_error((string) $message, (int) $httpCode, (array) $extra);
}

function get_staff_role(mysqli $db, $staffId) {
    if (!$staffId) return null;
    $resolved = wuc_resolve_staff_roles($db, (string) $staffId);
    return $resolved['role'] ?? null;
}

function canonicalize_role(?string $role): ?string {
    if ($role === null) return null;
    return wuc_normalize_staff_role($role);
}

function ensure_logged_in() {
    if (!isset($_SESSION['staff_id']) && !isset($_SESSION['user_id'])) {
        json_error('Unauthorized', 401);
    }
}

function ensure_roles(mysqli $db, array $allowedRoles) {
    ensure_logged_in();
    $staffId = (string) ($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? '');
    $resolved = wuc_resolve_staff_roles($db, $staffId);
    $assignedRoles = (array) ($resolved['all_roles'] ?? []);
    $allowedCanonical = array_map(
        static fn($role) => wuc_normalize_staff_role((string) $role, false),
        $allowedRoles
    );

    if (in_array('systems_admin', $assignedRoles, true)) return;
    if (!array_intersect($assignedRoles, $allowedCanonical)) {
        json_error('Forbidden: insufficient permissions', 403, ['roles' => $assignedRoles]);
    }
}

function log_audit(mysqli $db, $userId, $action, $details = null) {
    audit_log($db, (string)$userId, (string)$action, $details);
}

function get_exchange_rate(mysqli $db, $baseCurrency, $quoteCurrency) {
    if (!$baseCurrency || !$quoteCurrency || strtoupper($baseCurrency) === strtoupper($quoteCurrency)) {
        return 1.0;
    }
    $stmt = $db->prepare("SELECT rate FROM finance_exchange_rates WHERE base_currency = ? AND quote_currency = ? LIMIT 1");
    if (!$stmt) return 1.0;
    $stmt->bind_param('ss', $baseCurrency, $quoteCurrency);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        return (float)$row['rate'];
    }
    return 1.0;
}

function convert_amount(mysqli $db, $amount, $fromCurrency, $toCurrency) {
    $rate = get_exchange_rate($db, $fromCurrency, $toCurrency);
    return round((float)$amount * (float)$rate, 2);
}
