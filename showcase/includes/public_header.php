<?php
declare(strict_types=1);

/**
 * Public Skills-to-Trade showcase chrome (header).
 * Expects optional: $pageTitle, $pageDescription, $bodyClass, $extraHead.
 */

if (!isset($pageTitle) || !is_string($pageTitle) || $pageTitle === '') {
    $pageTitle = 'Skills-to-Trade and Investment Hub';
}
$pageDescription = isset($pageDescription) && is_string($pageDescription)
    ? $pageDescription
    : 'Public catalogue of trade-ready products, services and investment opportunities from ITC.';
$bodyClass = isset($bodyClass) && is_string($bodyClass) ? $bodyClass : '';
$extraHead = isset($extraHead) && is_string($extraHead) ? $extraHead : '';

$currentScript = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$exhibitionEnabled = isset($db) && $db instanceof mysqli && function_exists('eh_exhibition_mode')
    ? eh_exhibition_mode($db)
    : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo eh_h($pageDescription); ?>">
    <title><?php echo eh_h($pageTitle); ?> | ITC</title>
    <link rel="icon" href="/wucportal/images/favicon.png">
    <link rel="stylesheet" href="/wucportal/assets/vendor/bootstrap/5.3.2/bootstrap.min.css">
    <link rel="stylesheet" href="/wucportal/assets/vendor/fontawesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/wucportal/css/enterprise-hub.css?v=20260721">
    <style>
        :root {
            --eh-primary: #6f42c1;
            --eh-primary-dark: #5a32a3;
            --eh-navy: #1B2A4A;
            --eh-ink: #212529;
            --eh-muted: #6c757d;
            --eh-surface: #f7f5fb;
            --eh-card: #ffffff;
            --eh-border: #e8e4f2;
        }
        body {
            font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: var(--eh-surface);
            color: var(--eh-ink);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .eh-navbar {
            background: linear-gradient(135deg, var(--eh-navy), #0B1530);
            box-shadow: 0 4px 18px rgba(11, 21, 48, 0.25);
        }
        .eh-navbar .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.02em;
            color: #fff !important;
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }
        .eh-navbar .navbar-brand img {
            height: 36px;
            width: auto;
        }
        .eh-navbar .brand-sub {
            display: block;
            font-size: 0.72rem;
            font-weight: 500;
            opacity: 0.8;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .eh-navbar .nav-link {
            color: rgba(255, 255, 255, 0.88) !important;
            font-weight: 560;
            padding: 0.45rem 0.85rem !important;
            border-radius: 0.4rem;
        }
        .eh-navbar .nav-link:hover,
        .eh-navbar .nav-link.active {
            color: #fff !important;
            background: rgba(255, 255, 255, 0.12);
        }
        .eh-theme-banner {
            background:
                radial-gradient(ellipse at 20% 30%, rgba(111, 66, 193, 0.35), transparent 55%),
                radial-gradient(ellipse at 80% 70%, rgba(27, 42, 74, 0.55), transparent 50%),
                linear-gradient(135deg, #1B2A4A 0%, #4a2b9c 55%, #6f42c1 100%);
            color: #fff;
            padding: 2.25rem 0;
            position: relative;
            overflow: hidden;
        }
        .eh-theme-banner::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, transparent 60%, rgba(0, 0, 0, 0.18));
            pointer-events: none;
        }
        .eh-theme-banner .banner-year {
            display: inline-block;
            font-size: 0.8rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            opacity: 0.9;
            border: 1px solid rgba(255, 255, 255, 0.35);
            padding: 0.25rem 0.7rem;
            border-radius: 999px;
            margin-bottom: 0.75rem;
        }
        .eh-theme-banner h1 {
            font-size: clamp(1.55rem, 3.2vw, 2.35rem);
            font-weight: 800;
            margin-bottom: 0.4rem;
            position: relative;
            z-index: 1;
        }
        .eh-theme-banner p {
            margin: 0;
            max-width: 40rem;
            opacity: 0.92;
            position: relative;
            z-index: 1;
        }
        .eh-main {
            flex: 1 0 auto;
            padding: 1.75rem 0 2.5rem;
        }
        .eh-card {
            background: var(--eh-card);
            border: 1px solid var(--eh-border);
            border-radius: 14px;
            box-shadow: 0 8px 28px rgba(80, 60, 180, 0.06);
            overflow: hidden;
            height: 100%;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }
        .eh-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(80, 60, 180, 0.12);
        }
        .eh-card a.stretched-link::after {
            z-index: 2;
        }
        .eh-card-img {
            aspect-ratio: 16 / 10;
            object-fit: cover;
            width: 100%;
            background: #ece8f6;
        }
        .eh-card-img-placeholder {
            aspect-ratio: 16 / 10;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #ece8f6, #d9d0ef);
            color: var(--eh-primary);
            font-size: 2rem;
        }
        .eh-badge-verified {
            background: #e8f5e9;
            color: #198754;
            font-weight: 700;
            font-size: 0.75rem;
            border-radius: 999px;
            padding: 0.28rem 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            line-height: 1.2;
        }
        .eh-card .p-3 {
            display: flex;
            flex-direction: column;
            min-height: 11.5rem;
        }
        .eh-card .p-3 > .d-flex.flex-wrap.justify-content-between {
            margin-top: auto;
            padding-top: 0.35rem;
        }
        .eh-card .badge {
            font-weight: 600;
        }
        .eh-filter-panel {
            background: #fff;
            border: 1px solid var(--eh-border);
            border-radius: 14px;
            padding: 1.25rem 1.15rem 1.35rem;
            box-shadow: 0 6px 20px rgba(80, 60, 180, 0.05);
            position: sticky;
            top: 1rem;
        }
        .eh-filter-panel .btn {
            min-height: 2.35rem;
        }
        .btn-eh-primary {
            background: linear-gradient(135deg, var(--eh-primary), var(--eh-primary-dark));
            border: none;
            color: #fff;
            font-weight: 600;
        }
        .btn-eh-primary:hover {
            background: linear-gradient(135deg, var(--eh-primary-dark), #4a2b9c);
            color: #fff;
        }
        .eh-section-title {
            font-weight: 750;
            font-size: 1.25rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .eh-section-title i {
            color: var(--eh-primary);
        }
        .eh-meta {
            color: var(--eh-muted);
            font-size: 0.875rem;
        }
        .eh-disclaimer {
            font-size: 0.85rem;
            color: var(--eh-muted);
            background: #f8f7fb;
            border-left: 3px solid var(--eh-primary);
            padding: 0.85rem 1rem;
            border-radius: 0 8px 8px 0;
        }
        .eh-footer {
            background: var(--eh-navy);
            color: rgba(255, 255, 255, 0.82);
            padding: 1.5rem 0;
            margin-top: auto;
            font-size: 0.9rem;
        }
        .eh-footer a {
            color: #fff;
            text-decoration: none;
        }
        .eh-footer a:hover {
            text-decoration: underline;
        }
        body.eh-exhibition {
            background: #0b1220;
            color: #f3f4f6;
        }
        body.eh-exhibition .eh-main {
            background: #0b1220;
        }
        body.eh-exhibition .eh-card,
        body.eh-exhibition .eh-filter-panel {
            background: #111827;
            border-color: #374151;
            color: #f9fafb;
        }
        body.eh-exhibition .eh-card h3 a,
        body.eh-exhibition .eh-card .text-dark {
            color: #f9fafb !important;
        }
        body.eh-exhibition .text-muted,
        body.eh-exhibition .eh-meta {
            color: #9ca3af !important;
        }
        body.eh-exhibition .badge.bg-light {
            background: #1f2937 !important;
            color: #e5e7eb !important;
            border-color: #4b5563 !important;
        }
        .eh-exhibition .eh-stat {
            background: #111827;
            color: #fff;
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            border: 2px solid #4b5563;
            box-shadow: 0 0 0 1px #000;
        }
        .eh-exhibition .eh-stat .value {
            font-size: clamp(1.8rem, 4vw, 2.8rem);
            font-weight: 800;
            line-height: 1.1;
            color: #fbbf24;
        }
        .eh-exhibition .eh-stat .label {
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-size: 0.8rem;
            opacity: 0.9;
            margin-top: 0.35rem;
        }
        .visually-hidden-honeypot {
            position: absolute !important;
            left: -9999px !important;
            height: 1px;
            width: 1px;
            overflow: hidden;
        }
        @media (max-width: 575.98px) {
            .eh-theme-banner {
                padding: 1.6rem 0;
            }
            .eh-navbar .brand-text {
                font-size: 0.95rem;
            }
        }
    </style>
    <?php echo $extraHead; ?>
</head>
<body class="<?php echo eh_h($bodyClass); ?>">
<nav class="navbar navbar-expand-lg eh-navbar sticky-top">
    <div class="container">
        <a class="navbar-brand" href="/wucportal/showcase/index.php">
            <img src="/wucportal/images/itc_logo.png" alt="ITC"
                 onerror="this.onerror=null;this.src='/wucportal/images/favicon.png';">
            <span class="brand-text">
                Skills-to-Trade
                <span class="brand-sub">Investment Hub · ITC</span>
            </span>
        </a>
        <button class="navbar-toggler border-0 text-white" type="button" data-bs-toggle="collapse"
                data-bs-target="#ehPublicNav" aria-controls="ehPublicNav" aria-expanded="false"
                aria-label="Toggle navigation">
            <i class="fas fa-bars"></i>
        </button>
        <div class="collapse navbar-collapse" id="ehPublicNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-lg-1">
                <li class="nav-item">
                    <a class="nav-link<?php echo $currentScript === 'index.php' ? ' active' : ''; ?>"
                       href="/wucportal/showcase/index.php">Catalogue</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link<?php echo $currentScript === 'search.php' ? ' active' : ''; ?>"
                       href="/wucportal/showcase/search.php">Search</a>
                </li>
                <?php if ($exhibitionEnabled): ?>
                <li class="nav-item">
                    <a class="nav-link<?php echo $currentScript === 'exhibition.php' ? ' active' : ''; ?>"
                       href="/wucportal/showcase/exhibition.php">Exhibition</a>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
