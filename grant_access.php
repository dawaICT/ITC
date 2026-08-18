<?php
/**
 * Legacy access-granting utility — permanently disabled.
 * Use authenticated admin user/role management pages instead.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo "This legacy access-granting utility has been disabled for security reasons.\n";
echo "Use the authenticated admin user/role management pages instead.\n";
exit;
