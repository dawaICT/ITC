<?php
$logPath = 'C:\\Users\\THIS PC\\.gemini\\antigravity\\brain\\dbd01264-480a-48bb-b327-0a2a0ea0974c\\.system_generated\\logs\\transcript_full.jsonl';
if (!file_exists($logPath)) {
    echo "Log file not found at: $logPath\n";
    exit(1);
}

$handle = fopen($logPath, 'r');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        $data = json_decode($line, true);
        if (isset($data['type']) && $data['type'] === 'USER_INPUT') {
            $content = $data['content'];
            // Save to goal_text.txt
            file_put_contents('scratch/goal_text.txt', $content);
            echo "Successfully extracted goal content to scratch/goal_text.txt\n";
            
            // Print table of contents or headers in the goal content
            preg_match_all('/^#+\s+(.*)$/m', $content, $matches);
            echo "\n=== HEADINGS FOUND ===\n";
            foreach ($matches[1] as $h) {
                echo " - " . trim($h) . "\n";
            }
            break;
        }
    }
    fclose($handle);
}
?>
