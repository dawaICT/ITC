<?php
/**
 * Creates the AI skill-taxonomy table. Idempotent — safe to re-run.
 *
 * Run once from CLI:   php ai\setup_db.php
 *
 * Stores each taxonomy skill together with its precomputed embedding vector
 * (as a JSON array of floats). Cosine similarity is done in PHP at query time,
 * so no special vector DB is required — plain MySQL is enough at this scale.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../includes/db_connect.php'; // gives $db (mysqli)

$cli = (php_sapi_name() === 'cli');
$nl  = $cli ? "\n" : "<br>\n";

$sql = "
CREATE TABLE IF NOT EXISTS ai_skill_taxonomy (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    skill_code      VARCHAR(120)  NOT NULL,            -- ESCO uri/code or local code
    preferred_label VARCHAR(255)  NOT NULL,            -- canonical skill name
    alt_labels      TEXT          NULL,                -- synonyms, newline separated
    description     TEXT          NULL,                -- optional definition
    skill_group     VARCHAR(120)  NULL,                -- broad category
    skill_type      VARCHAR(40)   NULL,                -- skill | knowledge | attitude
    embedding       LONGTEXT      NULL,                -- JSON float[] (AI_EMBED_DIM)
    embed_model     VARCHAR(80)   NULL,                -- model that produced embedding
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_skill_code (skill_code),
    KEY idx_label (preferred_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
";

try {
    $db->query($sql);
    echo "OK: table 'ai_skill_taxonomy' is ready.$nl";

    $count = (int) $db->query("SELECT COUNT(*) c FROM ai_skill_taxonomy")->fetch_assoc()['c'];
    $withEmb = (int) $db->query(
        "SELECT COUNT(*) c FROM ai_skill_taxonomy WHERE embedding IS NOT NULL"
    )->fetch_assoc()['c'];

    echo "Rows: $count   (with embeddings: $withEmb)$nl";
    if ($count === 0) {
        echo "Next: run  php ai\\load_skills.php  to load + embed the seed skills.$nl";
    }
} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage() . $nl;
}
