/*
 * sidebar-collapsible.js — Collapsible portal sidebar categories.
 *
 * Progressive enhancement for the unified sidebar contract shared by every
 * portal module (staff via includes/nav_unified.php, students via
 * students/includes/navbar.php):
 *
 *     nav.sidebar > .sidebar-content > .nav-section > (.nav-section-title + a.nav-item…)
 *
 * This script turns each multi-item ".nav-section-title" into an accessible
 * expand/collapse toggle. It does NOT touch which items are rendered — role,
 * permission and portal-access filtering all happen server-side before the
 * markup reaches the browser — so collapsing a category can never expose a
 * link the backend already withheld.
 *
 * Behaviour:
 *   - Sections with fewer than 2 items (e.g. Dashboard / Notifications) stay
 *     static so primary entry points are always one click away.
 *   - The section containing the active page is force-expanded on load and
 *     scrolled into view, regardless of any stored preference.
 *   - Open/closed state is remembered per sidebar in localStorage.
 *   - Fully keyboard operable (Enter / Space) with correct ARIA state.
 *   - Without JS the sidebar renders exactly as before (all sections open).
 *
 * Idempotent: safe to run more than once; the sidebar is flagged after wiring.
 */
(function () {
    'use strict';

    var STORAGE_PREFIX = 'wuc.sb.';

    function slugify(text) {
        return String(text || '')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'section';
    }

    function storageKey(scope, slug) {
        return STORAGE_PREFIX + scope + '.' + slug;
    }

    function readState(key) {
        try { return window.localStorage.getItem(key); }
        catch (e) { return null; }
    }

    function writeState(key, value) {
        try { window.localStorage.setItem(key, value); }
        catch (e) { /* private mode / disabled — degrade to no persistence */ }
    }

    function directChildren(parent, selector) {
        var out = [];
        for (var i = 0; i < parent.children.length; i++) {
            if (parent.children[i].matches(selector)) {
                out.push(parent.children[i]);
            }
        }
        return out;
    }

    function setExpanded(section, title, expanded, persist, key) {
        section.classList.toggle('collapsed', !expanded);
        title.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        if (persist && key) {
            writeState(key, expanded ? 'open' : 'collapsed');
        }
    }

    function enhanceSection(section, scope, index) {
        // Skip if this section was already wired.
        if (section.dataset.collapsibleReady === 'true') { return; }

        var titles = directChildren(section, '.nav-section-title');
        if (!titles.length) { return; }                 // no header → not a category
        var title = titles[0];

        var items = directChildren(section, '.nav-item');
        if (items.length < 2) { return; }               // single link → keep static

        section.dataset.collapsibleReady = 'true';
        section.classList.add('is-collapsible');

        // Wrap the items so their combined height can animate cleanly.
        var wrapper = document.createElement('div');
        wrapper.className = 'nav-section-items';
        wrapper.id = 'wuc-navsec-' + scope + '-' + index;
        title.insertAdjacentElement('afterend', wrapper);
        items.forEach(function (item) { wrapper.appendChild(item); });

        // Caret affordance.
        var caret = document.createElement('i');
        caret.className = 'fas fa-chevron-down nav-section-caret';
        caret.setAttribute('aria-hidden', 'true');
        title.appendChild(caret);

        // Accessible toggle semantics on the (non-button) title div.
        var slug = slugify(title.textContent);
        var key = storageKey(scope, slug);
        title.setAttribute('role', 'button');
        title.setAttribute('tabindex', '0');
        title.setAttribute('aria-controls', wrapper.id);

        // Initial state: active section always open; otherwise remembered
        // preference; default to open on first visit.
        var hasActive = !!wrapper.querySelector('.nav-item.active');
        var stored = readState(key);
        var expanded = hasActive ? true : (stored !== 'collapsed');
        // Reflect (but do not overwrite) stored preference when force-opening
        // an active section, so the user's choice survives to other pages.
        setExpanded(section, title, expanded, false, key);

        function toggle() {
            var nowExpanded = section.classList.contains('collapsed'); // about to open
            setExpanded(section, title, nowExpanded, true, key);
        }

        title.addEventListener('click', function (event) {
            event.preventDefault();
            toggle();
        });

        title.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
                event.preventDefault();
                toggle();
            }
        });
    }

    function scrollActiveIntoView(sidebar) {
        try {
            var active = sidebar.querySelector('.nav-item.active');
            var content = sidebar.querySelector('.sidebar-content');
            if (!active || !content) { return; }
            var ar = active.getBoundingClientRect();
            var cr = content.getBoundingClientRect();
            if (ar.top < cr.top || ar.bottom > cr.bottom) {
                content.scrollTop += (ar.top - cr.top) - 24;
            }
        } catch (e) { /* non-fatal */ }
    }

    function enhanceSidebar(sidebar) {
        if (!sidebar || sidebar.dataset.collapsibleInit === 'true') { return; }
        sidebar.dataset.collapsibleInit = 'true';

        // Scope keys per sidebar so staff and student preferences never collide.
        var scope = sidebar.id || 'sidebar';
        var content = sidebar.querySelector('.sidebar-content');
        if (!content) { return; }

        var sections = directChildren(content, '.nav-section');
        sections.forEach(function (section, index) {
            enhanceSection(section, scope, index);
        });

        scrollActiveIntoView(sidebar);
    }

    function init() {
        var sidebars = document.querySelectorAll('nav.sidebar, .sidebar[id]');
        Array.prototype.forEach.call(sidebars, enhanceSidebar);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
