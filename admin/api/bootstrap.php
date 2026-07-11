<?php
/**
 * Shared bootstrap for admin JSON list endpoints.
 *
 * Provides: session + systems_admin enforcement (checkAdminAuth), the $db
 * connection, the pagination helper, and the JSON content-type header. Keeps
 * every list endpoint down to a column list + a FROM clause.
 *
 * These endpoints are additive, read-only infrastructure: they let list pages
 * and dashboards fetch just the slice of data they need instead of rendering
 * the whole table on load. Access is gated to systems_admin (no data leakage);
 * per-role variants can be added later for non-admin portals.
 */

require_once dirname(__DIR__, 2) . '/config/auth_check.php';
require_once dirname(__DIR__, 2) . '/db/connect.php';
require_once dirname(__DIR__, 2) . '/includes/pagination_helper.php';

checkAdminAuth(); // session + systems_admin guard (redirects if unauthenticated)

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    // Read-only data: let the browser reuse it briefly, but never share it.
    header('Cache-Control: private, max-age=15');
}
