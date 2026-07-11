<?php
/**
 * Shared print-report helpers.
 *
 * Use these from any portal report page to guarantee a consistent printed
 * layout that includes the institution logo, a clear report title, the
 * generation date, and any filter/meta context.
 *
 * Typical usage in a report page (inside the body):
 *
 *   require_once __DIR__ . '/../includes/report_print.php';
 *   render_report_print_styles();
 *   render_report_print_script();
 *
 *   // Place the print header *outside* (above) the chrome you want hidden:
 *   render_report_print_header(
 *       'Student Enrollment Report',
 *       'For ' . $program_name,
 *       ['Program' => $program_name, 'Year' => $year, 'Semester' => $sem]
 *   );
 *
 *   // Trigger from a button: onclick="printReport('Student Enrollment Report')"
 */

if (!function_exists('render_report_print_styles')) {
    /**
     * Emits a <style> block with @media print rules that hide common chrome
     * (sidebar, navbar, page header, filter form, action buttons, DataTables
     * paging) and reveal elements marked .print-only / .report-print-header.
     */
    function render_report_print_styles() {
        ?>
<style>
/* Print header is hidden on screen, shown only when printing. */
.report-print-header { display: none; }

@page {
    size: A4 portrait;
    margin: 12mm;
}

@media print {
    /* Reset page chrome */
    *,
    *::before,
    *::after {
        box-shadow: none !important;
        text-shadow: none !important;
    }

    body, html {
        background: #fff !important;
        margin: 0 !important;
        padding: 0 !important;
        color: #000 !important;
        width: auto !important;
        min-width: 0 !important;
        overflow: visible !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    /* Hide sidebars, nav, top bars, page headers, filter forms, buttons */
    .sidebar, .admin-sidebar, .lecturer-sidebar,
    .navbar, nav, .topbar, .top-bar,
    .dashboard-header, .page-header,
    .d-print-none, .header-actions,
    .report-filters, form.report-filter, .filter-card,
    footer, .footer,
    button, .btn,
    .dataTables_paginate, .dataTables_info,
    .dataTables_length, .dataTables_filter,
    .report-search, #studentSearch {
        display: none !important;
    }

    /* Show print-only blocks */
    .report-print-header, .print-only { display: block !important; }

    .no-print { display: none !important; }

    /* Reset portal/sidebar layout offsets so content starts at the A4 margin. */
    body.has-unified-sidebar,
    body.sidebar-open,
    .main-wrapper,
    .content-wrapper,
    .main-content,
    .admin-dashboard,
    .portal-dashboard,
    .accounts-page,
    .hod-page,
    .students-page,
    .lecturer-page {
        margin: 0 !important;
        padding: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        min-width: 0 !important;
        left: auto !important;
        right: auto !important;
        transform: none !important;
        position: static !important;
    }

    /* Collapse containers so the report uses full page width */
    .container, .container-fluid {
        padding: 0 !important;
        margin: 0 !important;
        max-width: 100% !important;
        width: 100% !important;
    }

    /* Strip card chrome */
    .data-table-card, .card, .stat-card {
        box-shadow: none !important;
        border: 0 !important;
        background: #fff !important;
        margin: 0 0 8px 0 !important;
    }
    .data-table-card .card-header,
    .card .card-header {
        background: #fff !important;
        border-bottom: 1px solid #ccc !important;
        padding: 4px 0 !important;
        color: #000 !important;
    }
    .data-table-card .card-body,
    .card .card-body { padding: 4px 0 !important; }

    .table-responsive,
    .table-container,
    .dataTables_wrapper {
        overflow: visible !important;
        width: 100% !important;
        max-width: 100% !important;
    }

    /* Tables: ensure readability on paper */
    table {
        width: 100% !important;
        max-width: 100% !important;
        border-collapse: collapse !important;
        table-layout: auto !important;
        font-size: 9.5pt !important;
        color: #000 !important;
    }
    table th, table td {
        border: 1px solid #444 !important;
        padding: 3px 5px !important;
        color: #000 !important;
        vertical-align: top !important;
        white-space: normal !important;
        overflow-wrap: anywhere !important;
        word-break: normal !important;
    }
    table thead th {
        background: #e9ecef !important;
        color: #000 !important;
        font-weight: 700 !important;
    }
    .badge {
        color: #000 !important;
        background: transparent !important;
        border: 1px solid #555 !important;
        padding: 1px 4px !important;
        font-weight: 600 !important;
    }
    .progress { display: none !important; }

    /* Avoid awkward table page breaks */
    tr, .stat-card { page-break-inside: avoid; }
    .data-table-card,
    .card,
    .report-section,
    .summary-card {
        break-inside: avoid;
        page-break-inside: avoid;
    }
    thead { display: table-header-group; }
    tfoot { display: table-footer-group; }

    a[href]:after { content: ""; }

    /* Print header layout */
    .report-print-header {
        text-align: center;
        margin: 0 0 14px 0;
        padding-bottom: 10px;
        border-bottom: 2px solid #000;
        break-after: avoid;
        page-break-after: avoid;
    }
    .wuc-print-logo-frame {
        display: flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        min-width: 0;
        min-height: 78px;
        margin: 0 auto 8px;
        text-align: center;
    }
    .wuc-print-logo-frame img,
    img.report-logo {
        display: block;
        max-width: 100%;
        height: 72px;
        width: auto;
        max-height: 72px;
        margin: 0 auto;
        object-fit: contain;
        object-position: center center;
    }
    .report-print-header .logo-row {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 100%;
        margin: 0 auto;
        text-align: center;
    }
    .report-print-header .logo-row > * {
        display: block;
    }
    .report-print-header .institution-block {
        text-align: center;
        padding-left: 0;
        margin-top: 4px;
    }
    .report-print-header .institution-name {
        font-weight: 700;
        font-size: 16pt;
        text-transform: uppercase;
        line-height: 1.1;
        color: #000;
    }
    .report-print-header .institution-tag {
        font-size: 9.5pt;
        color: #444;
        font-weight: 400;
        margin-top: 2px;
    }
    .report-print-header .report-title {
        font-size: 14pt;
        font-weight: 700;
        margin-top: 12px;
        text-transform: uppercase;
        color: #000;
    }
    .report-print-header .report-subtitle {
        font-size: 11pt;
        color: #333;
        margin-top: 2px;
    }
    .report-print-header .report-meta {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 18px;
        margin-top: 10px;
        font-size: 10pt;
        color: #222;
    }
    .report-print-header .report-meta strong {
        font-weight: 600;
        margin-right: 4px;
    }

    /* Single Page Document print optimizations */
    body.single-page-document {
        margin: 0 !important;
        padding: 0 !important;
        font-size: 9.5pt !important;
        line-height: 1.35 !important;
        box-sizing: border-box !important;
        background: #fff !important;
        page-break-after: avoid;
        break-after: avoid;
    }
    body.single-page-document .main-content,
    body.single-page-document .content-wrapper,
    body.single-page-document .main-wrapper,
    body.single-page-document .container,
    body.single-page-document .container-fluid,
    body.single-page-document .card,
    body.single-page-document .card-body,
    body.single-page-document .invoice-container,
    body.single-page-document .letter-container,
    body.single-page-document .report-container {
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
        background: transparent !important;
        max-height: 100vh !important;
    }
    body.single-page-document h1,
    body.single-page-document .h1,
    body.single-page-document h2,
    body.single-page-document .h2,
    body.single-page-document h3,
    body.single-page-document .h3 {
        font-size: 14pt !important;
        margin-top: 5px !important;
        margin-bottom: 5px !important;
    }
    body.single-page-document h4,
    body.single-page-document .h4,
    body.single-page-document h5,
    body.single-page-document .h5 {
        font-size: 11pt !important;
        margin-top: 4px !important;
        margin-bottom: 4px !important;
    }
    body.single-page-document p,
    body.single-page-document li {
        margin-bottom: 4px !important;
        font-size: 9.5pt !important;
    }
    body.single-page-document table {
        font-size: 9pt !important;
        margin-bottom: 8px !important;
    }
    body.single-page-document table th,
    body.single-page-document table td {
        padding: 4px 6px !important;
    }
    body.single-page-document .wuc-print-letterhead,
    body.single-page-document .report-print-header,
    body.single-page-document .receipt-header,
    body.single-page-document .header {
        margin-bottom: 10px !important;
        padding-bottom: 8px !important;
    }
    body.single-page-document .wuc-print-letterhead img.report-logo,
    body.single-page-document .report-print-header img.report-logo,
    body.single-page-document .wuc-print-logo-frame img,
    body.single-page-document .logo,
    body.single-page-document .receipt-header img {
        height: 48px !important;
        width: auto !important;
        max-height: 48px !important;
        margin-bottom: 4px !important;
    }
    body.single-page-document .wuc-print-logo-frame {
        width: 100% !important;
        min-width: 0 !important;
        height: auto !important;
        min-height: 52px !important;
    }
    body.single-page-document .wuc-print-letterhead .institution-name,
    body.single-page-document .report-print-header .institution-name,
    body.single-page-document .university-name {
        font-size: 13pt !important;
    }
    body.single-page-document .wuc-print-letterhead .report-title,
    body.single-page-document .report-print-header .report-title,
    body.single-page-document .letter-title,
    body.single-page-document .receipt-number {
        font-size: 11pt !important;
        margin-top: 4px !important;
        padding: 4px 10px !important;
    }
    body.single-page-document .signature-section {
        margin-top: 15px !important;
    }
    body.single-page-document .signature-line {
        margin-top: 25px !important;
    }
}
</style>
        <?php
    }
}

if (!function_exists('render_report_print_header')) {
    /**
     * Print-only header block: logo + institution + report title + generated date + meta.
     *
     * @param string $title       Report title (e.g. "Student Enrollment Report").
     * @param string $subtitle    Optional one-line subtitle (e.g. program name).
     * @param array  $meta        Optional label => value pairs to render under the title
     *                            (e.g. ['Program' => 'BSc CS', 'Year' => '2026']).
     * @param string $logo_url    Optional override for the logo URL.
     * @param string $institution Optional institution name override.
     */
    function render_report_print_header(
        $title,
        $subtitle = '',
        array $meta = [],
        $logo_url = '',
        $institution = ''
    ) {
        if ($logo_url === '')    { $logo_url = '/wucportal/images/itc_logo.png'; }
        if ($institution === '') { $institution = 'Industrial Training Centre'; }
        $generated = date('d M Y, H:i');
        ?>
<div class="report-print-header">
    <div class="logo-row">
        <span class="wuc-print-logo-frame">
            <img src="<?php echo htmlspecialchars($logo_url); ?>"
                 alt="<?php echo htmlspecialchars($institution); ?> Logo"
                 class="report-logo">
        </span>
        <div class="institution-block">
            <div class="institution-name"><?php echo htmlspecialchars($institution); ?></div>
            <div class="institution-tag">Official Report &middot; Student Portal</div>
        </div>
    </div>
    <div class="report-title"><?php echo htmlspecialchars($title); ?></div>
    <?php if ($subtitle !== ''): ?>
        <div class="report-subtitle"><?php echo htmlspecialchars($subtitle); ?></div>
    <?php endif; ?>
    <div class="report-meta">
        <div><strong>Generated:</strong><?php echo htmlspecialchars($generated); ?></div>
        <?php foreach ($meta as $label => $value):
            if ($value === '' || $value === null) { continue; } ?>
            <div><strong><?php echo htmlspecialchars((string)$label); ?>:</strong><?php echo htmlspecialchars((string)$value); ?></div>
        <?php endforeach; ?>
    </div>
</div>
        <?php
    }
}

if (!function_exists('render_wuc_a4_print_script')) {
    /**
     * Loads the shared A4 single-page fit helper (scale-to-fit before print).
     */
    function render_wuc_a4_print_script() {
        ?>
<script src="/wucportal/js/wuc-print-fit.js?v=20260702" defer></script>
        <?php
    }
}

if (!function_exists('render_report_print_script')) {
    /**
     * Emits a small script that exposes window.printReport(title).
     * Setting document.title before window.print() means the browser's
     * print header / "Save as PDF" filename will carry the report title
     * and date instead of the page slug.
     */
    function render_report_print_script() {
        ?>
<script>
(function () {
    // DataTables keeps only the current page's rows in the DOM, so a plain
    // window.print() would print just those ~10 rows. Expand every DataTable to
    // show all rows before printing (covers both the print button and Ctrl+P),
    // then restore the on-screen pagination afterwards.
    var __wucDtPrintState = [];
    function __wucExpandTablesForPrint() {
        __wucDtPrintState = [];
        if (!(window.jQuery && jQuery.fn && jQuery.fn.DataTable)) { return; }
        jQuery('table').each(function () {
            if (jQuery.fn.DataTable.isDataTable(this)) {
                try {
                    var dt = jQuery(this).DataTable();
                    __wucDtPrintState.push({ dt: dt, len: dt.page.len() });
                    dt.page.len(-1).draw(false);
                } catch (e) {}
            }
        });
    }
    function __wucRestoreTablesAfterPrint() {
        __wucDtPrintState.forEach(function (s) {
            try { s.dt.page.len(s.len).draw(false); } catch (e) {}
        });
        __wucDtPrintState = [];
    }

    if (!window.__wucPrintHooksInstalled) {
        window.__wucPrintHooksInstalled = true;
        window.addEventListener('beforeprint', function () {
            document.documentElement.classList.add('wuc-printing');
            document.body && document.body.classList.add('wuc-printing');
            __wucExpandTablesForPrint();
        });
        window.addEventListener('afterprint', function () {
            document.documentElement.classList.remove('wuc-printing');
            document.body && document.body.classList.remove('wuc-printing');
            __wucRestoreTablesAfterPrint();
        });
    }

    window.printReport = function (title) {
        var prev = document.title;
        if (title) {
            var d = new Date();
            var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
            var stamp = d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
            document.title = title + ' - ' + stamp;
        }

        document.documentElement.classList.add('wuc-printing');
        document.body && document.body.classList.add('wuc-printing');

        var cleanup = function () {
            document.title = prev;
            document.documentElement.classList.remove('wuc-printing');
            document.body && document.body.classList.remove('wuc-printing');
        };

        var printNow = function () {
            __wucExpandTablesForPrint();
            var restored = false;
            var finish = function () {
                if (restored) { return; }
                restored = true;
                cleanup();
            };
            window.addEventListener('afterprint', finish, { once: true });
            window.print();
            // Fallback when afterprint is not supported (older browsers).
            setTimeout(finish, 3000);
        };

        if (window.requestAnimationFrame) {
            requestAnimationFrame(function () {
                requestAnimationFrame(printNow);
            });
        } else {
            setTimeout(printNow, 50);
        }
    };
})();
</script>
        <?php
    }
}
