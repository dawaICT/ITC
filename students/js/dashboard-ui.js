/**
 * Shared dashboard UI: collapse toggle labels and notification bell menus.
 */
(function () {
    function initCollapseToggleLabels() {
        document.querySelectorAll('.card-toggle[data-bs-toggle="collapse"]').forEach(function (button) {
            var targetSelector = button.getAttribute('data-bs-target');
            if (!targetSelector) {
                return;
            }
            var target = document.querySelector(targetSelector);
            if (!target) {
                return;
            }
            var label = button.querySelector('.card-toggle-label');
            var sync = function () {
                var expanded = target.classList.contains('show');
                button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                if (label) {
                    label.textContent = expanded ? 'Collapse' : 'Expand';
                }
            };
            target.addEventListener('shown.bs.collapse', sync);
            target.addEventListener('hidden.bs.collapse', sync);
            sync();
        });
    }

    function initNotificationBell(bellId, menuId) {
        var bell = document.getElementById(bellId);
        var menu = document.getElementById(menuId);
        if (!bell || !menu) {
            return;
        }

        function setOpen(open) {
            menu.classList.toggle('show', open);
            bell.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        bell.addEventListener('click', function (event) {
            event.stopPropagation();
            setOpen(!menu.classList.contains('show'));
        });

        document.addEventListener('click', function (event) {
            if (!menu.contains(event.target) && !bell.contains(event.target)) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                setOpen(false);
                bell.focus();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCollapseToggleLabels();
        initNotificationBell('dashboardAttentionBell', 'dashboardAttentionMenu');
        initNotificationBell('elDashboardBell', 'elDashboardMenu');

        var launchAiTutor = document.getElementById('launchAiTutorBtn');
        if (launchAiTutor) {
            launchAiTutor.addEventListener('click', function () {
                var panel = document.getElementById('wucAiwPanel');
                var input = document.getElementById('wucAiwInput');
                if (panel) {
                    panel.classList.add('open');
                }
                if (input) {
                    input.focus();
                }
            });
        }
    });
})();
