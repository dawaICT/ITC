# Full Debug Performance Report

## Findings

| Item | Status |
|------|--------|
| SMS send in web request | Queued (mock); no synchronous provider wait |
| USSD handle | Local DB only |
| Agriculture dashboards | COUNT queries; acceptable for MVP |
| Farmer list | LIMIT 100–200 |
| Price current query | LIMIT 100 + expiry filters in SQL |

## Recommendations

1. Add pagination to farmer/produce/demand tables when >500 rows.
2. Run `ep_expire_*` via cron instead of only on page load.
3. Index review after pilot volume (already seeded in migration).

No blocking performance defects for pilot scale.
