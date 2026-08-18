    <footer class="ep-page-footer">
        Skills and Enterprise Portal — verified skills, products, services and innovations connected to real opportunities.
        Institutional verification does not guarantee employment, sales, funding or investment returns.
    </footer>
</main>
<script src="/wucportal/assets/vendor/bootstrap/5.3.2/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.body.classList.add('enterprise-portal', 'has-unified-sidebar');
    var toggleBtn = document.querySelector('.sidebar-toggle');
    var sidebar = document.getElementById('enterpriseSidebar');
    var backdrop = document.querySelector('[data-enterprise-sidebar-backdrop]');

    function setSidebar(open) {
        if (!sidebar || !toggleBtn) return;
        sidebar.classList.toggle('show', open);
        toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.classList.toggle('sidebar-open', open);
        if (backdrop) backdrop.classList.toggle('show', open);
    }

    if (toggleBtn && sidebar) {
        toggleBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            setSidebar(!sidebar.classList.contains('show'));
        });
        document.addEventListener('click', function (event) {
            if (!sidebar.classList.contains('show')) {
                return;
            }
            if (!sidebar.contains(event.target) && !toggleBtn.contains(event.target)) {
                setSidebar(false);
            }
        });
        if (backdrop) backdrop.addEventListener('click', function () { setSidebar(false); });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') setSidebar(false);
        });
    }

    document.querySelectorAll('.sidebar .nav-item').forEach(function (link) {
        link.addEventListener('click', function () {
            if (sidebar && sidebar.classList.contains('show')) setSidebar(false);
        });
    });

    document.querySelectorAll('#enterpriseSidebar .nav-section.is-collapsible').forEach(function (section) {
        var hasActive = section.querySelector('.nav-item.active');
        if (hasActive) {
            section.classList.remove('collapsed');
            var title = section.querySelector('.nav-section-title');
            if (title) {
                title.setAttribute('aria-expanded', 'true');
            }
        }
    });

    document.querySelectorAll('.dash-content table, .portal-dashboard table').forEach(function (table) {
        if (table.closest('.table-responsive, .table-container')) return;
        var wrapper = document.createElement('div');
        wrapper.className = 'table-responsive';
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
    });
});
</script>
</body>
</html>
