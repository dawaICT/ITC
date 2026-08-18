# Lead management

Every valid interest becomes a lead with statuses from `new` through `converted` / closed states.

- Assignment and status changes via `ep_update_lead_status`
- Closure requires a reason
- Age labels: New / Requires Attention / Overdue / Escalated (configurable day thresholds)
- Converted leads can link to outcomes; enquiries are not outcomes
