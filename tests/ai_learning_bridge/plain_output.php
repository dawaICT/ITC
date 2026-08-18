<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once __DIR__ . '/../../includes/ai_markdown.php';

$input = <<<'TEXT'
**Revision Activity – Communication Skills (DCSE-108)**

*Purpose:* Quickly test your recall of key concepts.

---

### Practice Questions

1. **Define “active listening” and list two techniques.**
TEXT;

$html = wuc_ai_render_markdown($input);
$unsafeHtml = wuc_ai_render_markdown('<img src=x onerror=alert(1)> **Safe text**');
$checks = [
    'bold markers are rendered' => !str_contains($html, '**'),
    'heading markers are rendered' => !str_contains($html, '###'),
    'horizontal-rule markers are rendered' => !str_contains($html, '---'),
    'semantic emphasis is preserved' => str_contains($html, '<strong>Revision Activity'),
    'semantic heading is preserved' => str_contains($html, '<h5>Practice Questions</h5>'),
    'numbered question is preserved' => str_contains($html, '<ol>') && str_contains($html, '<li><strong>Define'),
    'generated HTML remains escaped' => !str_contains($unsafeHtml, '<img') && str_contains($unsafeHtml, '&lt;img'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        throw new RuntimeException('FAILED: ' . $label . "\n" . $html);
    }
    echo "[pass] {$label}\n";
}

echo "Learning Assistant output formatting test complete.\n";
