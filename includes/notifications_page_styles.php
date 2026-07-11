<style>
    body { background: #eef2f7; font-family: Inter, 'Segoe UI', system-ui, sans-serif; color: #0f172a; }
    .notification-shell { max-width: 1180px; margin: 0 auto; padding: 1.25rem; }
    .notification-hero { background: #fff; border: 1px solid #e2e8f0; border-left: 4px solid #6f42c1; border-radius: 12px; padding: 1rem 1.15rem; box-shadow: 0 8px 24px rgba(80,60,180,0.08); }
    .notification-hero h1 { font-size: 1.35rem; font-weight: 700; margin: 0; }
    .notification-hero p { margin: .25rem 0 0; color: #64748b; }
    .notification-stat { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: .9rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); min-height: 100%; }
    .notification-stat span { display: block; color: #64748b; font-size: .75rem; text-transform: uppercase; font-weight: 700; }
    .notification-stat strong { display: block; font-size: 1.55rem; line-height: 1.1; margin-top: .2rem; }
    .notification-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 8px 24px rgba(80,60,180,0.08); overflow: hidden; }
    .notification-item { display: grid; grid-template-columns: 42px minmax(0,1fr) auto; gap: .85rem; padding: 1rem; border-bottom: 1px solid #edf2f7; border-left: 3px solid transparent; align-items: start; }
    .notification-item:last-child { border-bottom: 0; }
    .notification-item.unread { background: #f8f7fd; border-left-color: #6f42c1; }
    .notification-icon { width: 42px; height: 42px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: #eff6ff; color: #1d4ed8; }
    .notification-icon.warning { background: #fffbeb; color: #b45309; }
    .notification-icon.critical { background: #fef2f2; color: #b91c1c; }
    .notification-title { font-weight: 700; color: #0f172a; overflow-wrap: anywhere; }
    .notification-message { color: #475569; margin-top: .15rem; overflow-wrap: anywhere; }
    .notification-meta { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .55rem; }
    .notification-actions { display: flex; gap: .4rem; flex-wrap: wrap; justify-content: flex-end; }
    .empty-state { text-align: center; padding: 2.5rem 1rem; color: #64748b; }
    .empty-state i { display: block; font-size: 2rem; opacity: .35; margin-bottom: .65rem; }
    @media (max-width: 767.98px) {
        .notification-shell { padding: .9rem; }
        .notification-item { grid-template-columns: 36px minmax(0,1fr); }
        .notification-icon { width: 36px; height: 36px; }
        .notification-actions { grid-column: 1 / -1; justify-content: stretch; }
        .notification-actions .btn, .notification-actions form { flex: 1 1 140px; }
        .notification-actions .btn { width: 100%; }
    }
</style>
