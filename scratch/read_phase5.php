<?php
$content = file_get_contents('scratch/goal_text.txt');
$start = strpos($content, 'Phase 5');
$end = strpos($content, 'Phase 6');
if ($start !== false && $end !== false) {
    echo "=== PHASE 5 REQUIREMENTS ===\n";
    echo substr($content, $start, $end - $start) . "\n";
} else {
    echo "Could not extract Phase 5 section.\n";
}
?>
