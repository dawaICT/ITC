<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/guard.php';
require_once dirname(__DIR__) . '/db/connect.php';
$studentId = (string)($_SESSION['Sid'] ?? $_SESSION['student_id'] ?? '');
$skillDiscoveryFormAction = 'skill_discovery.php';
$skillDiscoveryEyebrow = 'Student Services';
require_once __DIR__ . '/includes/skill_discovery_run.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Skill Discovery - ITC</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/admin-style.css">
    <link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
    <link rel="stylesheet" href="/wucportal/students/css/skill-discovery.css?v=20260722">
</head>
<body class="bg-light student-portal">
<?php require_once __DIR__ . '/includes/navbar.php'; ?>

<main class="content-wrapper pt-3 pb-5">
  <div class="skill-page">
    <?php require __DIR__ . '/includes/skill_discovery_body.php'; ?>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php require __DIR__ . '/includes/skill_discovery_scripts.php'; ?>
</body>
</html>
