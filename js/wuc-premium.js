/* ============================================================================
   WUC PORTAL — PREMIUM INTERACTION LAYER
   ----------------------------------------------------------------------------
   Vanilla JS, no inline handlers (CSP-friendly), progressive enhancement only:
   every page works identically with this file absent — it just feels better
   with it present.

   Features
     1. Scroll-reveal for cards / stat tiles / tables
     2. Count-up animation for numeric stat values
     3. Auto-dismissing alerts (opt out with .alert-permanent / data-persist)
     4. Submit-button loading state (prevents double submits — security win)
     5. Auto-wrap bare tables in .table-responsive (mobile safety)
     6. rel="noopener noreferrer" enforcement on target=_blank links (security)
     7. Caps-lock hint on password fields
     8. window.wucToast(message, type) helper
   ========================================================================== */
(function () {
    'use strict';

    var reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function onReady(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    /* 1 ─ Scroll reveal ─────────────────────────────────────────────────── */
    function initReveal() {
        if (reduceMotion || !('IntersectionObserver' in window)) { return; }
        var targets = document.querySelectorAll(
            '.portal-dashboard .stat-card, .portal-dashboard .card, ' +
            '.portal-dashboard .data-table-card, .portal-dashboard .dashboard-header'
        );
        if (!targets.length) { return; }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (e) {
                if (e.isIntersecting) {
                    e.target.classList.add('wuc-in');
                    io.unobserve(e.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.05 });
        targets.forEach(function (el, i) {
            // Only animate elements below the fold-ish; everything visible on
            // load just gets a tiny stagger so the page feels composed.
            el.classList.add('wuc-reveal');
            el.style.transitionDelay = Math.min(i * 40, 240) + 'ms';
            io.observe(el);
        });
    }

    /* 2 ─ Stat value count-up ───────────────────────────────────────────── */
    function initCountUp() {
        if (reduceMotion) { return; }
        var values = document.querySelectorAll('.portal-dashboard .stat-value');
        values.forEach(function (el) {
            var raw = (el.textContent || '').trim();
            // Pure integers (optionally with thousands separators) only —
            // never touch text statuses like "Registered" or "K1,200.50".
            var m = raw.match(/^(\d{1,3}(?:,\d{3})*|\d+)$/);
            if (!m) { return; }
            var target = parseInt(raw.replace(/,/g, ''), 10);
            if (!isFinite(target) || target <= 0 || target > 1000000) { return; }
            var useCommas = raw.indexOf(',') !== -1;
            var start = null;
            var dur = Math.min(900, 350 + target);
            function fmt(n) {
                return useCommas ? n.toLocaleString('en-US') : String(n);
            }
            function step(ts) {
                if (start === null) { start = ts; }
                var p = Math.min((ts - start) / dur, 1);
                var eased = 1 - Math.pow(1 - p, 3);
                el.textContent = fmt(Math.round(target * eased));
                if (p < 1) { requestAnimationFrame(step); }
                else { el.textContent = raw; }
            }
            el.textContent = fmt(0);
            requestAnimationFrame(step);
        });
    }

    /* 3 ─ Auto-dismiss alerts ───────────────────────────────────────────── */
    function initAlerts() {
        var alerts = document.querySelectorAll(
            '.portal-dashboard .alert-success:not(.alert-permanent):not([data-persist]), ' +
            '.portal-dashboard .alert-info:not(.alert-permanent):not([data-persist])'
        );
        alerts.forEach(function (el) {
            setTimeout(function () {
                el.style.transition = 'opacity 400ms ease, max-height 400ms ease, margin 400ms ease, padding 400ms ease';
                el.style.overflow = 'hidden';
                el.style.maxHeight = el.scrollHeight + 'px';
                requestAnimationFrame(function () {
                    el.style.opacity = '0';
                    el.style.maxHeight = '0';
                    el.style.marginTop = '0';
                    el.style.marginBottom = '0';
                    el.style.paddingTop = '0';
                    el.style.paddingBottom = '0';
                });
                setTimeout(function () { el.remove(); }, 450);
            }, 6000);
        });
    }

    /* 4 ─ Submit-button loading state (double-submit guard) ────────────── */
    function initSubmitGuard() {
        document.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (!(form instanceof HTMLFormElement)) { return; }
            if (form.hasAttribute('data-wuc-no-loading')) { return; }
            // Respect HTML5 validation: only lock once the submit is real.
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) { return; }
            var btn = form.querySelector('button[type="submit"], input[type="submit"]');
            if (!btn || btn.classList.contains('wuc-loading')) { return; }
            // Lock after the current tick so the click that submits still lands.
            setTimeout(function () {
                btn.classList.add('wuc-loading');
                btn.setAttribute('aria-busy', 'true');
                // Failsafe: never leave a button locked forever (e.g. AJAX
                // forms that preventDefault and re-render in place).
                setTimeout(function () {
                    btn.classList.remove('wuc-loading');
                    btn.removeAttribute('aria-busy');
                }, 12000);
            }, 0);
        }, true);
    }

    /* 5 ─ Responsive table safety net ───────────────────────────────────── */
    function initResponsiveTables() {
        var tables = document.querySelectorAll('.portal-dashboard table.table');
        tables.forEach(function (t) {
            if (t.closest('.table-responsive') || t.closest('.dataTables_wrapper')) { return; }
            var wrap = document.createElement('div');
            wrap.className = 'table-responsive';
            t.parentNode.insertBefore(wrap, t);
            wrap.appendChild(t);
        });
    }

    /* 6 ─ Safe external links ───────────────────────────────────────────── */
    function initSafeLinks() {
        document.querySelectorAll('a[target="_blank"]').forEach(function (a) {
            var rel = (a.getAttribute('rel') || '').split(/\s+/).filter(Boolean);
            if (rel.indexOf('noopener') === -1) { rel.push('noopener'); }
            if (rel.indexOf('noreferrer') === -1) { rel.push('noreferrer'); }
            a.setAttribute('rel', rel.join(' '));
        });
    }

    /* 7 ─ Caps-lock hint on password fields ─────────────────────────────── */
    function initCapsLockHints() {
        function handler(ev) {
            var input = ev.target;
            if (!input || input.type !== 'password') { return; }
            if (typeof ev.getModifierState !== 'function') { return; }
            var on = ev.getModifierState('CapsLock');
            var hint = input.parentNode.querySelector('.wuc-capslock-hint');
            if (on && !hint) {
                hint = document.createElement('span');
                hint.className = 'wuc-capslock-hint';
                hint.innerHTML = '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Caps Lock is on';
                // Place after the wrapping group so layout isn't disturbed.
                (input.closest('.input-wrap') || input).insertAdjacentElement('afterend', hint);
            } else if (!on && hint) {
                hint.remove();
            }
        }
        document.addEventListener('keyup', handler, true);
        document.addEventListener('focusout', function (ev) {
            var input = ev.target;
            if (input && input.type === 'password') {
                var hint = input.parentNode.querySelector('.wuc-capslock-hint');
                if (hint) { hint.remove(); }
            }
        }, true);
    }

    /* 8 ─ Toast helper ───────────────────────────────────────────────────── */
    function ensureToastStack() {
        var stack = document.querySelector('.wuc-toast-stack');
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'wuc-toast-stack';
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        return stack;
    }
    window.wucToast = function (message, type, timeoutMs) {
        var stack = ensureToastStack();
        var toast = document.createElement('div');
        toast.className = 'wuc-toast' + (type ? ' ' + type : '');
        var icon = type === 'success' ? 'fa-check-circle'
                 : type === 'danger' ? 'fa-exclamation-circle'
                 : 'fa-info-circle';
        var i = document.createElement('i');
        i.className = 'fas ' + icon;
        i.setAttribute('aria-hidden', 'true');
        var span = document.createElement('span');
        span.textContent = String(message); // textContent — never HTML-inject
        toast.appendChild(i);
        toast.appendChild(span);
        stack.appendChild(toast);
        setTimeout(function () {
            toast.classList.add('leaving');
            setTimeout(function () { toast.remove(); }, 260);
        }, timeoutMs || 4200);
        return toast;
    };

    /* 9 ─ Print letterhead ──────────────────────────────────────────────
       Inject a print-only logo + institution header at the top of <body> so
       every printout carries branding, without editing every page. Pages that
       already render their own letterhead (receipts, admission letters,
       transcripts) are detected and skipped; opt out explicitly with
       <body class="no-auto-print"> or a [data-print-letterhead] element. */
    function initPrintLetterhead() {
        if (!document.body) { return; }
        
        if (document.body.classList.contains('no-auto-print')) { return; }
        // Already has a custom or shared letterhead? Leave it alone.
        if (document.querySelector(
            '.wuc-print-letterhead, .report-print-header, [data-print-letterhead], ' +
            '.receipt-header, .letterhead, .institution-header, .admission-letter, ' +
            '.print-letterhead, .report-header, .document-header, .certificate-header, ' +
            '.ca-header-container, .transcript-header-container, .receipt-logo, ' +
            '.print-registers-logo, .admin-slip-logo'
        )) { return; }

        // Extract print details
        var userNameEl = document.querySelector('.sidebar-footer .user-name, .students-sidebar .user-name, .user-name');
        var userName = userNameEl ? userNameEl.textContent.trim() : '';
        
        var userRoleEl = document.querySelector('.sidebar-footer .user-role, .students-sidebar .user-role, .user-role');
        var userRole = userRoleEl ? userRoleEl.textContent.trim() : '';

        var docTitle = document.title || 'Official Document';
        // Clean up common website title suffixes
        docTitle = docTitle.replace(/\s*[|:-]\s*(Student Portal|ITC Portal|Administrator|Admissions|Registrar|Dean|HOD|Lecturer|Accounts|Vice Chancellor|The College University)/gi, '');
        
        var dateStr = new Date().toLocaleString('en-GB', {
            day: '2-digit', month: 'short', year: 'numeric',
            hour: '2-digit', minute: '2-digit'
        });

        var metaHtml = '<div><strong>Document:</strong> ' + docTitle + '</div>' +
                       '<div><strong>Printed:</strong> ' + dateStr + '</div>';
        if (userName) {
            metaHtml += '<div><strong>User:</strong> ' + userName + (userRole ? ' (' + userRole + ')' : '') + '</div>';
        }

        var head = document.createElement('div');
        head.className = 'wuc-print-letterhead';
        // Hidden on screen; css/wuc-print.css (media=print) reveals it on paper.
        head.setAttribute('style', 'display:none;');
        head.setAttribute('aria-hidden', 'true');
        head.innerHTML =
            '<div class="logo-row">' +
                '<span class="wuc-print-logo-frame">' +
                    '<img class="report-logo" src="/wucportal/images/itc_logo.png" alt="ITC Logo">' +
                '</span>' +
                '<div class="institution-block">' +
                    '<div class="institution-name">Industrial Training Centre</div>' +
                    '<div class="institution-tag">Official Document &middot; Student Portal</div>' +
                '</div>' +
            '</div>' +
            '<div class="report-title">Document Printout</div>' +
            '<div class="report-meta">' + metaHtml + '</div>';
        document.body.insertBefore(head, document.body.firstChild);
    }

    /* 10 ─ DataTable unified enhancements (matching lecturers.php look) ──── */
    function initDataTableEnhancements() {
        if (typeof $ === 'undefined' || !$.fn.dataTable) { return; }

        // Set global DataTable defaults
        $.extend(true, $.fn.dataTable.defaults, {
            pageLength: 15,
            language: {
                search: "",
                searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_ entries",
                paginate: {
                    first: '<i class="fas fa-angle-double-left"></i>',
                    last: '<i class="fas fa-angle-double-right"></i>',
                    next: '<i class="fas fa-angle-right"></i>',
                    previous: '<i class="fas fa-angle-left"></i>'
                }
            }
        });

        // Event listener for any DataTable draw event to dynamically clean up labels and arrows
        $(document).on('draw.dt init.dt', function (ev, settings) {
            var $wrapper = $(settings.nTableWrapper);
            if (!$wrapper.length) { return; }

            // If length/filter are not wrapped in nested Bootstrap rows, enable our custom CSS grid layout
            if (!$wrapper.find('> .row').length && !$wrapper.hasClass('wuc-grid-layout')) {
                $wrapper.addClass('wuc-grid-layout');
            }

            // Ensure table gets the correct responsive/full width styling classes
            var $table = $(settings.nTable);
            if ($table.length) {
                $table.addClass('table table-hover align-middle table-full-width');
                if (!$table.parent().hasClass('table-responsive') && !$table.parent().hasClass('dataTables_scrollBody')) {
                    $table.wrap('<div class="table-responsive"></div>');
                }
            }

            // Replace page navigation text with FontAwesome chevrons
            $wrapper.find('.paginate_button.previous, .page-item.previous a, .page-link[aria-label="Previous"]').html('<i class="fas fa-angle-left"></i>');
            $wrapper.find('.paginate_button.next, .page-item.next a, .page-link[aria-label="Next"]').html('<i class="fas fa-angle-right"></i>');
            $wrapper.find('.paginate_button.first, .page-item.first a').html('<i class="fas fa-angle-double-left"></i>');
            $wrapper.find('.paginate_button.last, .page-item.last a').html('<i class="fas fa-angle-double-right"></i>');

            // Strip out raw text "Search:" from label wrapper to leave only a clean input box
            $wrapper.find('.dataTables_filter label').each(function () {
                var $label = $(this);
                if ($label.text().indexOf('Search:') !== -1) {
                    var $input = $label.find('input');
                    var placeholder = $input.attr('placeholder') || 'Search...';
                    $input.attr('placeholder', placeholder);
                    $label.contents().filter(function() {
                        return this.nodeType === 3;
                    }).remove();
                }
            });
        });

        // Proactively trigger a redraw on any DataTable initialized before this script executed
        $('.dataTables_wrapper').each(function () {
            var $t = $(this).find('table');
            if ($t.length && $.fn.DataTable.isDataTable($t[0])) {
                try {
                    var api = $t.DataTable();
                    if (api) { api.draw(false); }
                } catch (e) {
                    console.warn('Failed to force draw on table:', e);
                }
            }
        });
    }

    onReady(function () {
        initResponsiveTables();
        initReveal();
        initCountUp();
        initAlerts();
        initSubmitGuard();
        initSafeLinks();
        initCapsLockHints();
        initPrintLetterhead();
        initDataTableEnhancements();
    });
})();
