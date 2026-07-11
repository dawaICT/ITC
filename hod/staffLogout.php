<?php
// Unified redirect to secure logout handler
// This script is kept for backward compatibility with existing GET links
$target = '../logout.php?to=staff';

if (!headers_sent()) {
    header('Location: ' . $target);
    exit;
}
echo '<script>window.location.href=' . json_encode($target) . ';</script>';
echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8') . '"></noscript>';
exit;
?>