<?php
/**
 * Loads the skill taxonomy into MySQL and computes an embedding for each skill
 * via the local Ollama server.
 *
 * Usage (CLI):
 *   php ai\load_skills.php              # load the built-in seed set
 *   php ai\load_skills.php --csv=path   # load from an ESCO/Tabiya CSV instead
 *   php ai\load_skills.php --reembed    # recompute embeddings for existing rows
 *
 * CSV format expected (header row required):
 *   code,preferred_label,skill_type,skill_group,alt_labels
 *   (alt_labels: synonyms separated by | )
 *
 * The text we embed = preferred label + alt labels + description, so that
 * informal, real-world phrasings still land near the canonical skill.
 *
 * This is intentionally CLI-first: embedding the whole set can take a while on
 * a CPU-only machine, and CLI has no request timeout.
 */

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/ollama.php';
require_once __DIR__ . '/skills_data.php';

// ---- parse args ----
$opts     = $isCli ? getopt('', ['csv:', 'reembed']) : [];
$csvPath  = $opts['csv']    ?? null;
$reembed  = isset($opts['reembed']);

echo "AI skill loader\n----------------\n";

// ---- gather rows to load ----
$rows = $csvPath ? load_from_csv($csvPath) : seed_rows();
echo "Source: " . ($csvPath ? "CSV ($csvPath)" : "built-in seed") . "  —  " . count($rows) . " skills\n\n";

// ---- upsert text first (no embedding), so the table is usable even if embedding fails ----
$insert = $db->prepare("
    INSERT INTO ai_skill_taxonomy (skill_code, preferred_label, alt_labels, description, skill_group, skill_type)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        preferred_label = VALUES(preferred_label),
        alt_labels      = VALUES(alt_labels),
        description     = VALUES(description),
        skill_group     = VALUES(skill_group),
        skill_type      = VALUES(skill_type)
");
foreach ($rows as $r) {
    $insert->bind_param('ssssss',
        $r['code'], $r['label'], $r['alt'], $r['desc'], $r['group'], $r['type']);
    $insert->execute();
}
echo "Upserted " . count($rows) . " skill rows.\n";

// ---- preflight before embeddings ----
if (!ollama_available()) {
    ai_loader_error(
        "Ollama is not reachable at " . OLLAMA_HOST . ".\n" .
        "Text rows were loaded, but embeddings were not created.\n" .
        "Start Ollama (it usually runs as a service after install) or run 'ollama serve',\n" .
        "then pull the model:  ollama pull " . AI_EMBED_MODEL . "\n" .
        "After that, re-run:  php ai\\load_skills.php\n"
    );
    exit($isCli ? 1 : 0);
}

// ---- compute embeddings ----
$where = $reembed ? '1' : 'embedding IS NULL';
$todo  = $db->query("SELECT id, preferred_label, alt_labels, description FROM ai_skill_taxonomy WHERE $where");
$total = $todo->num_rows;
echo "Embedding $total skill(s) with " . AI_EMBED_MODEL . " ...\n";

$update = $db->prepare("UPDATE ai_skill_taxonomy SET embedding = ?, embed_model = ? WHERE id = ?");
$done = 0; $failed = 0; $model = AI_EMBED_MODEL;

while ($row = $todo->fetch_assoc()) {
    $text = embed_text($row['preferred_label'], $row['alt_labels'], $row['description']);
    try {
        $vec  = ollama_embed($text);
        $json = json_encode($vec);
        $update->bind_param('ssi', $json, $model, $row['id']);
        $update->execute();
        $done++;
    } catch (Throwable $e) {
        $failed++;
        ai_loader_error("  ! failed on '{$row['preferred_label']}': {$e->getMessage()}\n");
    }
    if (($done + $failed) % 10 === 0) {
        echo "  ... " . ($done + $failed) . "/$total\n";
    }
}

echo "\nDone. Embedded: $done   Failed: $failed\n";
if ($done > 0) {
    echo "Try it:  php ai\\match.php \"I sell vegetables at the market and keep records of sales\"\n";
}

// ===================== helpers =====================

/** Build the text we actually embed for a skill. */
function embed_text(string $label, ?string $alt, ?string $desc): string
{
    $parts = [$label];
    if ($alt)  { $parts[] = str_replace("\n", ', ', $alt); }
    if ($desc) { $parts[] = $desc; }
    return implode('. ', $parts);
}

/** Normalise the built-in seed into loader rows. */
function seed_rows(): array
{
    $out = [];
    foreach (ai_seed_skills() as $s) {
        // [code, label, type, group, alt[]]
        $out[] = [
            'code'  => $s[0],
            'label' => $s[1],
            'type'  => $s[2],
            'group' => $s[3],
            'alt'   => implode("\n", $s[4] ?? []),
            'desc'  => '',
        ];
    }
    return $out;
}

/** Parse an ESCO/Tabiya-style CSV into loader rows. */
function load_from_csv(string $path): array
{
    if (!is_readable($path)) {
        ai_loader_error("Cannot read CSV: $path\n");
        exit(1);
    }
    $fh     = fopen($path, 'r');
    $header = fgetcsv($fh);
    if (!$header) { ai_loader_error("Empty CSV.\n"); exit(1); }
    $idx = array_flip(array_map('trim', $header));

    $need = ['code', 'preferred_label'];
    foreach ($need as $col) {
        if (!isset($idx[$col])) {
            ai_loader_error("CSV missing required column '$col'. Found: " . implode(', ', $header) . "\n");
            exit(1);
        }
    }

    $rows = [];
    while (($line = fgetcsv($fh)) !== false) {
        $get = fn($k) => isset($idx[$k]) && isset($line[$idx[$k]]) ? trim($line[$idx[$k]]) : '';
        if ($get('preferred_label') === '') { continue; }
        $rows[] = [
            'code'  => $get('code') !== '' ? $get('code') : substr(md5($get('preferred_label')), 0, 12),
            'label' => $get('preferred_label'),
            'type'  => $get('skill_type'),
            'group' => $get('skill_group'),
            'alt'   => str_replace('|', "\n", $get('alt_labels')),
            'desc'  => $get('description'),
        ];
    }
    fclose($fh);
    return $rows;
}

/** Write errors safely from both CLI and browser execution. */
function ai_loader_error(string $message): void
{
    if (defined('STDERR')) {
        fwrite(STDERR, $message);
        return;
    }
    echo $message;
}
