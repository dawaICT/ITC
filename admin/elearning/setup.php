<?php
require_once __DIR__ . '/../includes/admin.php';
require_once __DIR__ . '/../../includes/elearning_guard.php';
elearning_require_role(['systems_admin']);

// Run schema
$sqlFile = dirname(__DIR__, 2) . '/db/elearning_schema.sql';
$ok = false; $err = null;
if (file_exists($sqlFile)) {
    $sql = file_get_contents($sqlFile);
    // Split on semicolons carefully is complex; rely on multi_query
    if ($db->multi_query($sql)) {
        do { /* flush results */ } while ($db->more_results() && $db->next_result());
        $ok = true;
    } else { $err = $db->error; }
} else {
    $err = 'Schema file not found: ' . htmlspecialchars($sqlFile);
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
  <h2>eLearning Setup</h2>
  <?php if ($ok): ?>
    <div class="alert alert-success">Schema installed/verified.</div>
  <?php else: ?>
    <div class="alert alert-danger">Setup failed: <?php echo htmlspecialchars($err); ?></div>
  <?php endif; ?>
  <a class="btn btn-primary" href="index.php">Back to eLearning</a>
  <pre class="mt-3 small">Executed: db/elearning_schema.sql</pre>
  <p class="text-muted">Note: Re-run is safe (idempotent CREATE TABLE IF NOT EXISTS).</p>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>


