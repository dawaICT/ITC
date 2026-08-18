<?php
/**
 * Public Certificate Verification
 */

require_once __DIR__ . '/db/connect.php';

$certCode = isset($_GET['cert']) ? trim((string)$_GET['cert']) : '';
$found = false;
$record = null;

if ($certCode !== '') {
    $stmt = $db->prepare("
        SELECT 
            ac.certificate_code,
            ac.graduation_year,
            ac.date_issued,
            ac.status,
            s.Fname,
            s.Lname,
            p.program_name
        FROM alumni_certificates ac
        INNER JOIN students s ON ac.student_id = s.SID
        INNER JOIN programs p ON ac.program_code = p.program_code
        WHERE ac.certificate_code = ? AND (ac.status = 'Approved' OR ac.status = 'Verified')
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param('s', $certCode);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($record) {
            $found = true;
            // Increment verification count
            $increment = $db->prepare('UPDATE alumni_certificates SET verification_count = verification_count + 1 WHERE certificate_code = ?');
            if ($increment) {
                $increment->bind_param('s', $certCode);
                $increment->execute();
                $increment->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate Verification - ITC</title>
    <link href="/wucportal/assets/vendor/bootstrap/5.3.2/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/assets/vendor/fontawesome/6.4.0/css/all.min.css">
    <style>
        body { background: #faf9fd; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 2rem 0; }
        .cert-card { max-width: 580px; width: 100%; border: none; border-radius: 16px; box-shadow: 0 10px 30px rgba(111, 66, 193, 0.08); background: #ffffff; }
        .brand-header { background: linear-gradient(135deg, #1B2A4A, #0B1530); border-top-left-radius: 16px; border-top-right-radius: 16px; padding: 1.5rem; text-align: center; }
        .brand-header img { height: 40px; margin-bottom: 0.5rem; }
        .brand-header h2 { color: #fff; font-size: 1.25rem; font-weight: 700; margin: 0; }
        .verified-badge { color: #198754; font-size: 1rem; font-weight: 700; background: #e8f5e9; border-radius: 30px; padding: 0.5rem 1.25rem; display: inline-block; margin-bottom: 1.5rem; }
        .detail-row { border-bottom: 1px solid #f1f0f6; padding: 0.85rem 0; display: flex; justify-content: space-between; }
        .detail-label { color: #6c757d; font-weight: 600; font-size: 0.9rem; }
        .detail-val { color: #212529; font-weight: 700; font-size: 0.9rem; text-align: right; }
    </style>
</head>
<body>

<div class="container d-flex justify-content-center">
    <div class="card cert-card">
        <div class="brand-header">
            <img src="/wucportal/images/favicon.png" alt="ITC Logo" onerror="this.style.display='none'">
            <h2>Industrial Training Centre</h2>
            <small class="text-white-50">Official Certificate Verification Registry</small>
        </div>

        <div class="card-body p-4 text-center">
            <?php if ($certCode === ''): ?>
                <div class="py-4">
                    <i class="fas fa-search fa-3x text-muted mb-3"></i>
                    <h5 class="fw-bold">Verify Graduate Certificate</h5>
                    <p class="text-muted small mb-4">Enter a certificate verification code below to validate academic records.</p>
                    
                    <form method="get" action="">
                        <div class="input-group mb-3">
                            <input type="text" name="cert" class="form-control" placeholder="Enter Certificate Code (e.g. CERT-8382-XYZ)" required>
                            <button class="btn btn-primary bg-purple border-purple" type="submit">Verify</button>
                        </div>
                    </form>
                </div>
            <?php elseif ($found && $record): ?>
                <div class="py-2">
                    <span class="verified-badge"><i class="fas fa-check-circle me-2"></i>OFFICIALLY VERIFIED CREDENTIAL</span>
                    
                    <div class="text-start mb-4">
                        <div class="detail-row">
                            <span class="detail-label">Graduate Name</span>
                            <span class="detail-val"><?= htmlspecialchars($record['Fname'] . ' ' . $record['Lname']) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Program Completed</span>
                            <span class="detail-val"><?= htmlspecialchars($record['program_name']) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Graduation Year</span>
                            <span class="detail-val"><?= htmlspecialchars((string)$record['graduation_year']) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Certificate Serial Code</span>
                            <span class="detail-val"><code><?= htmlspecialchars($record['certificate_code']) ?></code></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Date Issued</span>
                            <span class="detail-val"><?= date('F d, Y', strtotime($record['date_issued'])) ?></span>
                        </div>
                        <div class="detail-row">
                            <span class="detail-label">Registry Status</span>
                            <span class="detail-val text-success">Valid &amp; Approved</span>
                        </div>
                    </div>

                    <p class="text-muted small mb-0"><i class="fas fa-shield-halved me-1"></i>Record matched the approved Industrial Training Centre certificate registry.</p>
                </div>
            <?php else: ?>
                <div class="py-4">
                    <i class="fas fa-circle-xmark fa-4x text-danger mb-3"></i>
                    <h4 class="fw-bold text-danger">Verification Failed</h4>
                    <p class="text-muted">The certificate code <code><?= htmlspecialchars($certCode) ?></code> is either invalid, revoked, or pending academic approval.</p>
                    <a href="verify_certificate.php" class="btn btn-outline-secondary btn-sm mt-3">Try Another Code</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .bg-purple { background-color: #6f42c1 !important; }
    .border-purple { border-color: #6f42c1 !important; }
</style>

</body>
</html>
