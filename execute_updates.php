<?php
/**
 * Legacy destructive database reset script — permanently disabled.
 * Use reviewed migrations or schema tools instead.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=utf-8');
echo "This destructive legacy database reset script has been disabled for security reasons.\n";
echo "Use reviewed migrations or schema tools instead.\n";
exit;
