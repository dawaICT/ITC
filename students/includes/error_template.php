<?php
/**
 * Error Template for Student Portal
 *
 * Expected variables:
 * - $error_title:   string  Title of the error
 * - $error_message: string  HTML message describing the error
 * - $is_dev_mode:   bool    Whether to show debug panel
 */

$error_title   = $error_title   ?? 'Error';
$error_message = $error_message ?? 'An unexpected error occurred.';
$is_dev_mode   = $is_dev_mode   ?? false;

$is_program_error = strpos($error_title, 'Program') !== false;
require_once dirname(__DIR__, 2) . '/includes/page_meta.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(wuc_portal_title($error_title), ENT_QUOTES, 'UTF-8') ?></title>
    <?php wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f0f4ff 0%, #e8ecf8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .error-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 8px 40px rgba(80, 60, 180, 0.10), 0 1.5px 6px rgba(0,0,0,0.06);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }

        .error-header {
            background: linear-gradient(135deg, #6f42c1 0%, #4a2b9c 100%);
            padding: 36px 32px 28px;
            text-align: center;
            color: #fff;
        }

        .error-icon-wrap {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            background: rgba(255,255,255,0.18);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 18px;
            font-size: 32px;
        }

        .error-header h1 {
            font-size: 1.25rem;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.01em;
        }

        .error-body {
            padding: 28px 32px 32px;
        }

        .error-message {
            color: #374151;
            font-size: 0.95rem;
            line-height: 1.65;
            background: #f8f7ff;
            border-left: 4px solid #6f42c1;
            border-radius: 0 10px 10px 0;
            padding: 14px 16px;
            margin-bottom: 20px;
        }

        .error-checklist {
            list-style: none;
            padding: 0;
            margin: 0 0 24px;
        }

        .error-checklist li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            font-size: 0.88rem;
            color: #4b5563;
            padding: 7px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .error-checklist li:last-child { border-bottom: none; }

        .error-checklist .icon {
            color: #6f42c1;
            margin-top: 2px;
            flex-shrink: 0;
            width: 16px;
            text-align: center;
        }

        .contact-note {
            font-size: 0.88rem;
            color: #6b7280;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 12px 14px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 24px;
        }

        .contact-note .icon { color: #d97706; flex-shrink: 0; margin-top: 2px; }

        .error-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-portal {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 10px 20px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.18s ease;
            flex: 1;
            justify-content: center;
            min-width: 120px;
        }

        .btn-portal-primary {
            background: linear-gradient(135deg, #6f42c1, #5a32a3);
            color: #fff;
            border: none;
        }

        .btn-portal-primary:hover {
            background: linear-gradient(135deg, #5a32a3, #4a2b9c);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(111,66,193,0.35);
        }

        .btn-portal-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
        }

        .btn-portal-secondary:hover {
            background: #e2e8f0;
            color: #334155;
        }

        /* Debug panel */
        .debug-panel {
            margin-top: 24px;
            background: #fffbeb;
            border: 1px solid #fcd34d;
            border-radius: 12px;
            padding: 16px;
        }

        .debug-panel h4 {
            font-size: 0.8rem;
            font-weight: 700;
            color: #92400e;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .debug-panel .debug-row {
            font-size: 0.8rem;
            color: #78350f;
            margin-bottom: 8px;
        }

        .debug-panel code {
            background: #fef9c3;
            padding: 2px 7px;
            border-radius: 5px;
            font-size: 0.78rem;
            font-family: 'Courier New', monospace;
        }

        .debug-panel ul {
            margin: 8px 0;
            padding-left: 18px;
            font-size: 0.8rem;
            color: #78350f;
        }

        .debug-panel a { color: #6f42c1; font-size: 0.8rem; }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-header">
            <div class="error-icon-wrap">
                <?php if ($is_program_error): ?>
                    <i class="fas fa-graduation-cap"></i>
                <?php else: ?>
                    <i class="fas fa-user-slash"></i>
                <?php endif; ?>
            </div>
            <h1><?= htmlspecialchars($error_title) ?></h1>
        </div>

        <div class="error-body">
            <div class="error-message">
                <?= $error_message ?>
            </div>

            <?php if ($is_program_error): ?>
                <div class="contact-note">
                    <span class="icon"><i class="fas fa-triangle-exclamation"></i></span>
                    <span>Please contact the <strong>Admissions Office</strong> to have your academic program assigned before you can access the portal.</span>
                </div>
            <?php else: ?>
                <p style="font-size:0.88rem;color:#6b7280;margin-bottom:12px;">This may happen if:</p>
                <ul class="error-checklist">
                    <li>
                        <span class="icon"><i class="fas fa-circle-dot"></i></span>
                        <span>Your account has not been fully registered in the system</span>
                    </li>
                    <li>
                        <span class="icon"><i class="fas fa-circle-dot"></i></span>
                        <span>There is a mismatch in your student ID records</span>
                    </li>
                    <li>
                        <span class="icon"><i class="fas fa-circle-dot"></i></span>
                        <span>Your login session has expired — try logging in again</span>
                    </li>
                </ul>
            <?php endif; ?>

            <div class="error-actions">
                <a href="../student_login.php" class="btn-portal btn-portal-primary">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
                <a href="mailto:admin@wuc.edu?subject=Portal%20Issue%20-%20<?= urlencode($student_id ?? 'Unknown') ?>"
                   class="btn-portal btn-portal-secondary">
                    <i class="fas fa-envelope"></i> Contact Support
                </a>
            </div>

            <?php if ($is_dev_mode && isset($db)): ?>
            <div class="debug-panel">
                <h4><i class="fas fa-wrench"></i> Dev Debug Info</h4>
                <div class="debug-row">
                    <strong>Session SID:</strong> <code><?= htmlspecialchars($_SESSION['Sid'] ?? 'not set') ?></code>
                </div>
                <?php
                $sample_students = [];
                $sample_res = $db->query("SELECT SID, Fname, Lname FROM students LIMIT 5");
                if ($sample_res) {
                    while ($row = $sample_res->fetch_assoc()) {
                        $sample_students[] = $row;
                    }
                }
                if (!empty($sample_students)): ?>
                <div class="debug-row"><strong>Valid SIDs in DB:</strong></div>
                <ul>
                    <?php foreach ($sample_students as $s): ?>
                    <li>
                        <code><?= htmlspecialchars($s['SID']) ?></code>
                        &mdash; <?= htmlspecialchars($s['Fname'] . ' ' . $s['Lname']) ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <a href="?dev=1&dev_as=<?= htmlspecialchars($sample_students[0]['SID'] ?? '') ?>">
                    <i class="fas fa-play"></i> Try with first valid SID
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
