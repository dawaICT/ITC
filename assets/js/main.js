/**
 * WUC Portal — Main JavaScript
 * Sidebar toggle, table responsive wrappers, and shared utilities.
 */
document.addEventListener('DOMContentLoaded', function () {

    // ── Mobile Sidebar Toggle ──────────────────────
    var toggleBtn = document.querySelector('.sidebar-toggle');
    var sidebar = document.querySelector('.sidebar');

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            sidebar.classList.toggle('show');
            document.body.classList.toggle('sidebar-open');
            var expanded = sidebar.classList.contains('show');
            toggleBtn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function (event) {
            if (
                sidebar.classList.contains('show') &&
                !sidebar.contains(event.target) &&
                !toggleBtn.contains(event.target)
            ) {
                sidebar.classList.remove('show');
                document.body.classList.remove('sidebar-open');
                toggleBtn.setAttribute('aria-expanded', 'false');
            }
        });

        // Close sidebar when a nav item is clicked (mobile)
        var navLinks = sidebar.querySelectorAll('.nav-item');
        navLinks.forEach(function (link) {
            link.addEventListener('click', function () {
                if (sidebar.classList.contains('show')) {
                    sidebar.classList.remove('show');
                    document.body.classList.remove('sidebar-open');
                    if (toggleBtn) {
                        toggleBtn.setAttribute('aria-expanded', 'false');
                    }
                }
            });
        });
    }

    // ── Auto-wrap bare tables for responsiveness ───
    var tables = document.querySelectorAll(
        '.content-wrapper table, .dash-content table, .main-content table'
    );
    tables.forEach(function (table) {
        // Skip already-wrapped tables
        if (table.closest('.table-responsive, .table-container, .responsive-table, .student-table-scroll')) {
            return;
        }
        var wrapper = document.createElement('div');
        wrapper.className = 'table-responsive';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });

    // ── Bootstrap Tooltip init ─────────────────────
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        var tooltipTriggerList = [].slice.call(
            document.querySelectorAll('[data-bs-toggle="tooltip"]')
        );
        tooltipTriggerList.forEach(function (el) {
            new bootstrap.Tooltip(el);
        });
    }

    // ── Login page: password toggle ────────────────
    var togglePw = document.getElementById('togglePassword');
    var pwField = document.getElementById('password') || document.getElementById('Password');
    if (togglePw && pwField) {
        togglePw.addEventListener('click', function () {
            var icon = togglePw.querySelector('i');
            pwField.type = pwField.type === 'password' ? 'text' : 'password';
            if (icon) {
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
            }
        });
    }

    // ── Login page: slideshow ──────────────────────
    var slides = document.querySelectorAll('.slide');
    var indicatorBox = document.getElementById('indicators');
    if (slides.length > 0 && indicatorBox) {
        var current = 0;
        var total = slides.length;

        slides.forEach(function (_, i) {
            var btn = document.createElement('button');
            btn.className = 'indicator' + (i === 0 ? ' active' : '');
            btn.setAttribute('aria-label', 'Go to slide ' + (i + 1));
            btn.addEventListener('click', function () { goTo(i); });
            indicatorBox.appendChild(btn);
        });

        function goTo(n) {
            slides[current].classList.remove('active');
            indicatorBox.children[current].classList.remove('active');
            current = n;
            slides[current].classList.add('active');
            indicatorBox.children[current].classList.add('active');
        }

        setInterval(function () { goTo((current + 1) % total); }, 6000);
    }
});
