<?php
declare(strict_types=1);

$page_title = 'Recorded Outcomes';
$activeNav = 'outcomes';
require_once dirname(__DIR__) . '/includes/guard.php';
require_once dirname(__DIR__) . '/includes/participant_helpers.php';

$profileId = ep_member_profile_id($epProfile);
ep_require_member_profile($profileId);
$rows = ep_list_outcomes_for_profile($db, $profileId);
require_once dirname(__DIR__) . '/includes/layout.php';
?>
<div class="ep-card">
    <p class="small ep-muted">Outcomes are recorded after confirmation. Leads and enquiries are not counted as outcomes.</p>
    <?php if ($rows === []): ?>
        <p class="ep-muted mb-0">No outcomes recorded yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Date</th><th>Type</th><th>Stage</th><th>Value</th><th>Jobs</th><th>Verification</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= ep_h((string)$r['recorded_at']) ?></td>
                        <td><?= ep_h(ep_status_label((string)$r['outcome_type'])) ?></td>
                        <td><?= ep_h((string)$r['outcome_stage']) ?></td>
                        <td><?= $r['estimated_value'] !== null ? ep_h(ep_money($r['estimated_value'], (string)($r['currency'] ?? 'ZMW'))) : '—' ?></td>
                        <td><?= ep_h((string)($r['jobs_created'] ?? '—')) ?></td>
                        <td><?= ep_h((string)$r['verification_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
