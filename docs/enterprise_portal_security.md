# Security

- Prepared statements
- Server-side authZ + ownership checks
- CSRF on state-changing POSTs
- Output escaping (`ep_h`)
- Secure uploads (MIME inspect, random names, size/dimension limits, storage deny)
- Interest throttling + duplicate detection
- Audit via existing audit logger
- Public published-only queries
- No PHP/SQL errors exposed to end users in production pages

Test script: `scripts/test_enterprise_portal_smoke.php`
