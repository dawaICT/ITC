<?php
/**
 * Course Fees & Requirements pane (§8 fee schedule + §7 entry requirements).
 * Included by transport_management.php when $activeTab === 'fees'.
 * Relies on the hub having provided $db, $csrfToken, and the tm_h() helper, and
 * on transport_fees.php / role_helpers being loaded.
 */
if (!isset($db) || !($db instanceof mysqli)) {
    return;
}

$canEditFees = (function_exists('canAccessFinance') && canAccessFinance())
    || (function_exists('isSystemsAdmin') && isSystemsAdmin());
$canEditReq = (function_exists('canAccessTransport') && canAccessTransport())
    || (function_exists('canAccessAdmissions') && canAccessAdmissions())
    || (function_exists('isSystemsAdmin') && isSystemsAdmin());

$feesPrograms = $db->query(
    "SELECT id, program_code, program_name, default_fee, rtsa_regulated,
            minimum_age, required_licence_class, requires_nrc, requires_grade_12,
            requires_driver_licence, requires_medical_certificate
     FROM transport_programs ORDER BY program_name"
)->fetch_all(MYSQLI_ASSOC);

if (!$feesPrograms) {
    echo '<div class="text-center py-5 text-muted"><i class="fas fa-coins fa-3x mb-3 opacity-50"></i>'
        . '<p class="mb-0">No transport courses found. Add courses to the transport catalogue first.</p></div>';
    return;
}

// Selected program: GET wins, else the program last edited (survives the PRG redirect), else first.
$selProgId = (int)($_GET['program_id'] ?? ($_SESSION['transport_fees_program'] ?? 0));
$selProg = null;
foreach ($feesPrograms as $p) {
    if ((int)$p['id'] === $selProgId) { $selProg = $p; break; }
}
if (!$selProg) { $selProg = $feesPrograms[0]; $selProgId = (int)$selProg['id']; }

// Fee schedule + additional fees for the selected program.
$courseFees = [];
$cfStmt = $db->prepare("SELECT id, fee_year, training_mode, amount, currency, is_available FROM transport_course_fees WHERE program_id = ? ORDER BY fee_year DESC, training_mode ASC");
$cfStmt->bind_param('i', $selProgId);
$cfStmt->execute();
$courseFees = $cfStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$cfStmt->close();

$addFees = [];
$afStmt = $db->prepare("SELECT id, fee_name, amount, currency, is_mandatory, fee_category, is_active FROM transport_additional_fees WHERE program_id = ? ORDER BY fee_category ASC, id ASC");
$afStmt->bind_param('i', $selProgId);
$afStmt->execute();
$addFees = $afStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$afStmt->close();

$thisYear = (int)date('Y');
$catBadge = static function (string $c): string {
    switch ($c) {
        case 'rtsa':      return 'bg-info text-dark';
        case 'equipment': return 'bg-secondary';
        case 'option':    return 'bg-warning text-dark';
        default:          return 'bg-light text-dark border';
    }
};
?>
<div class="transport-fees-pane">

    <div class="transport-setup-note mb-3">
        <i class="fas fa-coins me-1"></i>
        Fees are calculated from this <strong>approved schedule</strong> at enrolment (BR008) and
        versioned by year (BR016/BR017). A study mode marked <strong>N/A</strong> cannot be selected (BR002).
    </div>

    <!-- Course picker -->
    <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-md-6">
            <label class="form-label">Course</label>
            <select name="program_id" class="form-select" onchange="this.form.submit()">
                <?php foreach ($feesPrograms as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>" <?php echo (int)$p['id'] === $selProgId ? 'selected' : ''; ?>>
                        <?php echo tm_h($p['program_code'] . ' — ' . $p['program_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6 small text-muted">
            Default fee: <strong>K <?php echo number_format((float)$selProg['default_fee'], 2); ?></strong>
            &middot; RTSA regulated: <strong><?php echo (int)$selProg['rtsa_regulated'] ? 'Yes' : 'No'; ?></strong>
        </div>
    </form>

    <div class="row g-4">
        <!-- Entry requirements (§7) -->
        <div class="col-lg-4">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white"><strong><i class="fas fa-clipboard-check me-2 text-primary"></i>Entry Requirements (§7)</strong></div>
                <div class="card-body">
                    <form method="post" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                        <input type="hidden" name="action" value="update_program_requirements">
                        <input type="hidden" name="program_id" value="<?php echo $selProgId; ?>">
                        <div class="col-6">
                            <label class="form-label small">Minimum age</label>
                            <input type="number" min="0" max="120" name="minimum_age" class="form-control form-control-sm"
                                   value="<?php echo $selProg['minimum_age'] !== null ? (int)$selProg['minimum_age'] : ''; ?>"
                                   <?php echo $canEditReq ? '' : 'disabled'; ?>>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Req. licence class</label>
                            <input name="required_licence_class" class="form-control form-control-sm" maxlength="120"
                                   placeholder="e.g. B, C" value="<?php echo tm_h($selProg['required_licence_class']); ?>"
                                   <?php echo $canEditReq ? '' : 'disabled'; ?>>
                        </div>
                        <?php
                        $flags = [
                            'requires_nrc' => 'Requires NRC',
                            'requires_grade_12' => 'Requires Grade 12',
                            'requires_driver_licence' => 'Requires driver licence',
                            'requires_medical_certificate' => 'Requires medical clearance',
                        ];
                        foreach ($flags as $field => $label): ?>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?php echo $field; ?>" value="1"
                                           id="req_<?php echo $field; ?>" <?php echo (int)$selProg[$field] ? 'checked' : ''; ?>
                                           <?php echo $canEditReq ? '' : 'disabled'; ?>>
                                    <label class="form-check-label small" for="req_<?php echo $field; ?>"><?php echo $label; ?></label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($canEditReq): ?>
                            <div class="col-12 d-grid mt-2">
                                <button class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Save requirements</button>
                            </div>
                        <?php else: ?>
                            <div class="col-12"><div class="form-text">You do not have permission to edit requirements.</div></div>
                        <?php endif; ?>
                    </form>
                    <div class="form-text mt-2">Grade 12 / O-Level credits are screened as a <em>document warning</em> at enrolment (no structured source), not a hard block.</div>
                </div>
            </div>
        </div>

        <!-- Course fees by mode/year (§8) -->
        <div class="col-lg-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-white"><strong><i class="fas fa-money-bill-wave me-2 text-success"></i>Course Fees by Study Mode</strong></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-3">
                            <thead><tr><th>Year</th><th>Study Mode</th><th>Amount</th><th>Available?</th><?php if ($canEditFees): ?><th class="text-end">Action</th><?php endif; ?></tr></thead>
                            <tbody>
                                <?php if (!$courseFees): ?>
                                    <tr><td colspan="<?php echo $canEditFees ? 5 : 4; ?>" class="text-center text-muted py-3">No fee rows yet. Add one below.</td></tr>
                                <?php else: foreach ($courseFees as $cf):
                                    $cfId = (int)$cf['id']; $avail = (int)$cf['is_available']; ?>
                                    <tr>
                                        <td><?php echo (int)$cf['fee_year']; ?></td>
                                        <td><?php echo tm_h($cf['training_mode']); ?></td>
                                        <td class="fw-semibold">K <?php echo number_format((float)$cf['amount'], 2); ?></td>
                                        <td>
                                            <?php if ($avail): ?><span class="badge bg-success">Available</span>
                                            <?php else: ?><span class="badge bg-secondary">N/A</span><?php endif; ?>
                                        </td>
                                        <?php if ($canEditFees): ?>
                                        <td class="text-end">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                                <input type="hidden" name="action" value="toggle_course_mode">
                                                <input type="hidden" name="program_id" value="<?php echo $selProgId; ?>">
                                                <input type="hidden" name="fee_id" value="<?php echo $cfId; ?>">
                                                <input type="hidden" name="is_available" value="<?php echo $avail ? 0 : 1; ?>">
                                                <button class="btn btn-outline-secondary btn-sm">
                                                    <?php echo $avail ? 'Mark N/A' : 'Make available'; ?>
                                                </button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($canEditFees): ?>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                        <input type="hidden" name="action" value="add_course_fee">
                        <input type="hidden" name="program_id" value="<?php echo $selProgId; ?>">
                        <div class="col-6 col-md-2">
                            <label class="form-label small">Year</label>
                            <input type="number" name="fee_year" class="form-control form-control-sm" value="<?php echo $thisYear; ?>" min="2000" max="2100">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small">Study mode</label>
                            <select name="training_mode" class="form-select form-select-sm">
                                <option value="full-time">full-time</option>
                                <option value="in-house">in-house</option>
                                <option value="evening">evening</option>
                                <option value="distance">distance</option>
                                <option value="at-campus">at-campus</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small">Amount (K)</label>
                            <input type="number" step="0.01" min="0" name="amount" class="form-control form-control-sm" value="0.00">
                        </div>
                        <div class="col-6 col-md-2 form-check ms-2 mt-4">
                            <input class="form-check-input" type="checkbox" name="is_available" value="1" id="cf_avail" checked>
                            <label class="form-check-label small" for="cf_avail">Available</label>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i>Save fee</button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Additional / RTSA / option fees (§8) -->
            <div class="card shadow-sm">
                <div class="card-header bg-white"><strong><i class="fas fa-receipt me-2 text-info"></i>Additional Charges (mandatory, RTSA &amp; options)</strong></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-3">
                            <thead><tr><th>Fee</th><th>Category</th><th>Amount</th><th>Applies</th><th>Status</th><?php if ($canEditFees): ?><th class="text-end">Action</th><?php endif; ?></tr></thead>
                            <tbody>
                                <?php if (!$addFees): ?>
                                    <tr><td colspan="<?php echo $canEditFees ? 6 : 5; ?>" class="text-center text-muted py-3">No additional fees.</td></tr>
                                <?php else: foreach ($addFees as $af):
                                    $afId = (int)$af['id']; $active = (int)$af['is_active']; ?>
                                    <tr class="<?php echo $active ? '' : 'opacity-50'; ?>">
                                        <td><?php echo tm_h($af['fee_name']); ?></td>
                                        <td><span class="badge <?php echo $catBadge((string)$af['fee_category']); ?>"><?php echo tm_h($af['fee_category']); ?></span></td>
                                        <td class="fw-semibold">K <?php echo number_format((float)$af['amount'], 2); ?></td>
                                        <td><?php echo (int)$af['is_mandatory'] ? 'Mandatory' : 'Optional'; ?></td>
                                        <td><?php echo $active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Retired</span>'; ?></td>
                                        <?php if ($canEditFees): ?>
                                        <td class="text-end">
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                                                <input type="hidden" name="action" value="toggle_additional_fee">
                                                <input type="hidden" name="program_id" value="<?php echo $selProgId; ?>">
                                                <input type="hidden" name="fee_id" value="<?php echo $afId; ?>">
                                                <input type="hidden" name="is_active" value="<?php echo $active ? 0 : 1; ?>">
                                                <button class="btn btn-outline-secondary btn-sm"><?php echo $active ? 'Retire' : 'Re-activate'; ?></button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($canEditFees): ?>
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?php echo tm_h($csrfToken); ?>">
                        <input type="hidden" name="action" value="add_additional_fee">
                        <input type="hidden" name="program_id" value="<?php echo $selProgId; ?>">
                        <div class="col-12 col-md-4">
                            <label class="form-label small">Fee name</label>
                            <input name="fee_name" class="form-control form-control-sm" maxlength="120" placeholder="e.g. Registration fee" required>
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label small">Amount (K)</label>
                            <input type="number" step="0.01" min="0" name="amount" class="form-control form-control-sm" value="0.00">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small">Category</label>
                            <select name="fee_category" class="form-select form-select-sm">
                                <option value="standard">standard</option>
                                <option value="rtsa">rtsa</option>
                                <option value="equipment">equipment</option>
                                <option value="option">option (applicant-selectable)</option>
                            </select>
                        </div>
                        <div class="col-6 col-md-2 form-check ms-2 mt-4">
                            <input class="form-check-input" type="checkbox" name="is_mandatory" value="1" id="af_mand" checked>
                            <label class="form-check-label small" for="af_mand">Mandatory</label>
                        </div>
                        <div class="col-md-1 d-grid">
                            <button class="btn btn-info btn-sm text-white"><i class="fas fa-plus"></i></button>
                        </div>
                        <div class="col-12"><div class="form-text">Mark a charge <strong>option</strong> + un-tick Mandatory to let applicants choose it at enrolment (e.g. own vs ITC motorbike).</div></div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
