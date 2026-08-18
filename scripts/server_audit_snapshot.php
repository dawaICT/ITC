<?php
declare(strict_types=1);
$root = dirname(__DIR__);
chdir($root);
require_once $root . '/db/connect.php';

echo "=== MySQL ===\n";
$vars = [
    'version', 'max_connections', 'innodb_buffer_pool_size', 'slow_query_log',
    'long_query_time', 'wait_timeout', 'interactive_timeout', 'table_open_cache',
    'thread_cache_size', 'tmp_table_size', 'max_heap_table_size', 'max_allowed_packet',
];
foreach ($vars as $v) {
    $r = $db->query("SHOW VARIABLES LIKE '" . $db->real_escape_string($v) . "'");
    if ($row = $r->fetch_row()) {
        echo $row[0] . '=' . $row[1] . "\n";
    }
}
echo "---STATUS---\n";
foreach ([
    'Threads_connected', 'Threads_running', 'Max_used_connections', 'Aborted_connects',
    'Slow_queries', 'Created_tmp_disk_tables', 'Created_tmp_tables', 'Open_tables',
    'Uptime', 'Innodb_buffer_pool_reads', 'Innodb_buffer_pool_read_requests',
] as $k) {
    $r = $db->query("SHOW GLOBAL STATUS LIKE '" . $db->real_escape_string($k) . "'");
    if ($row = $r->fetch_row()) {
        echo $row[0] . '=' . $row[1] . "\n";
    }
}
$reads = 0;
$reqs = 0;
$r = $db->query("SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_reads'");
if ($row = $r->fetch_row()) {
    $reads = (float)$row[1];
}
$r = $db->query("SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_read_requests'");
if ($row = $r->fetch_row()) {
    $reqs = (float)$row[1];
}
if ($reqs > 0) {
    echo 'innodb_buffer_hit_ratio=' . round(100 * (1 - ($reads / $reqs)), 3) . "%\n";
}
