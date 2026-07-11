<?php
/**
 * Proof-of-concept UI for the offline skill-discovery engine.
 *
 * Open: http://localhost/wucportal/ai/test.php
 * Type a sentence about someone's experience -> see the matched taxonomy skills.
 *
 * This is a standalone test harness, not a portal page yet. Once it works,
 * the matching call (ai_match_skills) gets wired into a real portal page using
 * the standard layout/partials.
 */

require_once __DIR__ . '/match.php';   // pulls in db + ollama

$query   = trim($_POST['experience'] ?? '');
$results = null;
$error   = null;
$ollamaUp = ollama_available();
$chatModel = $ollamaUp ? ai_resolve_chat_model() : AI_CHAT_MODEL;

if ($query !== '' && $ollamaUp) {
    try {
        $results = ai_match_skills($query, 8);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ITC · Offline Skill Discovery (POC)</title>
<style>
  :root { --purple:#6f42c1; --purple-d:#59359a; }
  * { box-sizing:border-box; }
  body { font-family:"Inter",system-ui,Segoe UI,sans-serif; background:#f5f3fb; margin:0; color:#222; }
  .wrap { max-width:760px; margin:40px auto; padding:0 16px; }
  .card { background:#fff; border-radius:14px; box-shadow:0 6px 24px rgba(111,66,193,.10); padding:26px; }
  h1 { font-size:1.4rem; margin:0 0 4px; color:var(--purple-d); }
  p.sub { margin:0 0 18px; color:#666; font-size:.92rem; }
  textarea { width:100%; min-height:110px; border:1px solid #ddd; border-radius:10px; padding:12px; font-size:1rem; resize:vertical; }
  button { background:var(--purple); color:#fff; border:0; border-radius:10px; padding:11px 22px; font-size:1rem; cursor:pointer; margin-top:12px; }
  button:hover { background:var(--purple-d); }
  .status { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.78rem; font-weight:600; }
  .up { background:#e3f9e5; color:#1b7a2f; }
  .down { background:#fde8e8; color:#b42318; }
  .err { background:#fde8e8; color:#b42318; padding:12px 14px; border-radius:10px; margin-top:16px; }
  table { width:100%; border-collapse:collapse; margin-top:20px; }
  th,td { text-align:left; padding:9px 10px; border-bottom:1px solid #eee; font-size:.93rem; }
  th { color:#777; font-weight:600; font-size:.78rem; text-transform:uppercase; letter-spacing:.04em; }
  .bar { height:8px; background:#eee; border-radius:6px; overflow:hidden; min-width:90px; }
  .bar > span { display:block; height:100%; background:var(--purple); }
  .tag { font-size:.72rem; background:#efeaf9; color:var(--purple-d); padding:2px 8px; border-radius:20px; }
  .examples { font-size:.84rem; color:#888; margin-top:10px; }
  .examples code { background:#f0edf9; padding:2px 6px; border-radius:5px; cursor:pointer; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Offline Skill Discovery <span style="font-size:.7em;color:#999;">· POC</span></h1>
    <p class="sub">
      Local AI (Ollama + <?= htmlspecialchars(AI_EMBED_MODEL) ?>) maps described experience to standardised skills.
      Chat model: <?= htmlspecialchars($chatModel) ?>.
      Ollama:
      <?php if ($ollamaUp): ?>
        <span class="status up">● running</span>
      <?php else: ?>
        <span class="status down">● not reachable</span>
      <?php endif; ?>
    </p>

    <?php if (!$ollamaUp): ?>
      <div class="err">
        Ollama isn't reachable at <?= htmlspecialchars(OLLAMA_HOST) ?>.
        Start it (or run <code>ollama serve</code>) and pull the model:
        <code>ollama pull <?= htmlspecialchars(AI_EMBED_MODEL) ?></code>.
      </div>
    <?php endif; ?>

    <form method="post">
      <textarea name="experience" placeholder="e.g. I run a small shop where I sell vegetables, serve customers, handle mobile money payments and keep a notebook of daily sales."><?= htmlspecialchars($query) ?></textarea>
      <div class="examples">
        Try:
        <code onclick="fill(this)">I sell vegetables at the market and keep records of my sales</code>
        <code onclick="fill(this)">I help my aunt cook and serve food at her restaurant</code>
        <code onclick="fill(this)">I fix phones and computers for people in my neighbourhood</code>
      </div>
      <button type="submit" <?= $ollamaUp ? '' : 'disabled' ?>>Discover skills</button>
    </form>

    <?php if ($error): ?>
      <div class="err"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($results !== null && !$error): ?>
      <table>
        <thead><tr><th>Matched skill</th><th>Type</th><th>Match</th></tr></thead>
        <tbody>
        <?php foreach ($results as $r): $pct = max(0, round($r['score'] * 100)); ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['label']) ?></strong><br>
                <span style="color:#999;font-size:.8rem;"><?= htmlspecialchars($r['group']) ?> · <?= htmlspecialchars($r['code']) ?></span></td>
            <td><span class="tag"><?= htmlspecialchars($r['type']) ?></span></td>
            <td>
              <div class="bar"><span style="width:<?= $pct ?>%"></span></div>
              <span style="font-size:.8rem;color:#777;"><?= $pct ?>%</span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
<script>
  function fill(el){ document.querySelector('textarea[name=experience]').value = el.textContent; }
</script>
</body>
</html>
