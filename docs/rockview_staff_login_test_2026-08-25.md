# Rockview University — Staff Login Security Test Report

- **Target**: `http://41.60.17.145/staffLogin.php` (Staff Portal login)
- **Application identified**: Rockview University (Lusaka, Zambia) — `http://www.rockview.ac.zm`
- **Developer attribution (page footer)**: Zeducation Limited
- **Test date**: 25 Aug 2026
- **Tester role**: white-hat / authorized (signed scope asserted by requester)
- **Method**: manual, low-volume, non-destructive request-level testing (~9 requests total)
- **Out of scope (not performed)**: credential brute-force, data extraction, time-based
  injection, DoS, and any post-auth activity

> **Scope disclaimer**: this test was performed based on the requester's assertion of
> written authorization from Rockview University / Zeducation Limited. Re-testing without
> that authorization is not permitted.

---

## 1. Test Methodology (requests sent)

| # | Request | Input | Observed outcome |
|---|---------|-------|------------------|
| R1 | `GET /staffLogin.php` | — | HTTP 200, login form rendered (no redirect) |
| R2 | `GET /staffLogin.php` (follow) | — | HTTP 200, same form; `PHPSESSID` issued |
| R3 | `POST` empty form | `StaffNumber=` , `Password=` | Generic error: `Staff ID/password combination incorrect.` |
| R4 | `POST` quote probe | `StaffNumber=ZZZADMIN'` | Same generic error — **no SQL error leaked** |
| R5 | `POST` Boolean payload | `StaffNumber=' OR '1'='1' -- ` | Same generic error — **no login bypass** |
| R6 | `POST` password-field quote | `Password=' OR '1'='1` | Same generic error — no bypass, no SQL error |
| R7 | `POST` password-field Boolean | `Password=' OR '1'='1' -- ` | Same generic error — no bypass |
| R8 | `GET https://41.60.17.145/staffLogin.php` | — | **Connection failed (code 000)** — no TLS service |
| R9 | `GET http://41.60.17.145/staffLogin.php` (baseline) | — | HTTP 200 — **credentials travel in cleartext** |

---

## 2. Findings

### HIGH — Cleartext credential transport (no HTTPS)
- The service is reachable only over plain HTTP (`HTTP/1.1 200 OK`); HTTPS refused.
- Staff IDs and passwords are transmitted in cleartext and can be intercepted by any
  on-path attacker (same LAN, ISP-level sniffing, rogue Wi-Fi, etc.).
- There is no HSTS header and no redirect to HTTPS.

### HIGH — Session cookie not hardened
- Response header: `Set-Cookie: PHPSESSID=dto2hl61g0nbr6u9n7c7hqnpvv; path=/`
- Missing: `HttpOnly`, `Secure`, and `SameSite=Lax/Strict` attributes.
- Risk: cookie theft via XSS on any same-origin page (no HttpOnly), and cookie capture
  across the insecure channel (no Secure).

### MEDIUM — Server version disclosure
- `Server: Apache/2.4.29 (Ubuntu)` exposed.
- Apache 2.4.29 is an old build (first release 2018) with known CVEs; version disclosure
  lowers the attacker's effort.

### MEDIUM — Missing security headers
- Absent: `X-Content-Type-Options`, `X-Frame-Options`/CSP frame-ancestors,
  `Referrer-Policy`, `Permissions-Policy`.
- Risk: clickjacking possible if the legacy layout is framed; MIME-sniffing attacks.

### MEDIUM — No CSRF token on the login action
- The form posts to `staffLogin.php` with no anti-CSRF token.
- Impact for a login form is lower than for state-changing admin actions, but it enables
  login-CSRF (attacker forcing a victim's credentialed session) and indicates the
  framework lacks CSRF protections generally.

### LOW/MEDIUM — No login rate limiting observed
- 5 failed attempts in rapid succession (with distinct sessions) produced identical
  responses; no throttle/lockout message. Per-IP or per-session limits not evident.
- Real risk applies to the **student login** too if shared backend; brute-force and
  account-lockout (DoS) scenarios should be considered.

### LOW — Information disclosure in footer
- Developer's personal email and mobile numbers are embedded in every page.

### LOW — Ineffective client-side validation on the login form
- `onSubmit="return validateForm1();"` references fields on an unrelated contact form
  (`form1`); validation is a no-op for login fields. Not a security boundary, but
  indicates the intent to enforce non-empty fields server-side is absent.

---

## 3. Positives (things that are working)

| Check | Result |
|-------|--------|
| SQL injection in `StaffNumber` | **Not** exploitable via tested payloads — inputs are escaped/parameterized; no error leakage, no Boolean bypass |
| SQL injection in `Password` | **Not** exploitable via tested payloads |
| Error handling | Generic, non-enumerating error message (`Staff ID/password combination incorrect.`) |
| Input reflection / reflected XSS on error page | **No** — submitted values are not echoed back into the response |
| Session regeneration | Not verifiable without valid credentials; nothing observed contradicts standard PHP behavior |

---

## 4. Risk Summary

| Area | Status | Severity |
|------|--------|----------|
| Authentication bypass (SQLi / Boolean) | Resists tested payloads | ✅ Good |
| Transport security (TLS) | **Missing — cleartext** | 🔴 High |
| Session cookie hardening | **Missing** | 🔴 High |
| Server/version disclosure | Present | 🟠 Medium |
| Security headers | **Missing** | 🟠 Medium |
| CSRF protection | **Missing** | 🟠 Medium |
| Rate limiting / lockout | Not observed | 🟠 Medium |
| User enumeration | Not present via login error | ✅ Good |

**Overall**: the login logic itself is reasonably defended against the standard
SQLi/Boolean bypass attempts, but the system is exposed through a set of hardening gaps —
most critically the **absence of TLS** for credential transport and **unhardened session
cookies**.

---

## 5. Recommended Remediation (priority order)

1. **Enable HTTPS** (valid certificate, port 443) and force redirect HTTP → HTTPS; add
   HSTS once TLS is in place.
2. **Harden the session cookie**: `HttpOnly; Secure; SameSite=Lax` + regenerate the
   session ID on login.
3. **Add security headers**: `X-Content-Type-Options: nosniff`,
   `X-Frame-Options: DENY` (or CSP `frame-ancestors 'none'`), `Referrer-Policy`,
   `Permissions-Policy`.
4. **Introduce CSRF tokens** on all POST forms (login included).
5. **Implement login rate limiting / account lockout** (per-IP and per-account) with
   generic error output; add CAPTCHA after repeated failures.
6. **Suppress server banner**: `ServerTokens Prod` / `ServerSignature Off`.
7. **Migrate from HTML 4 / legacy inline script patterns**; add input-length and format
   validation server-side; ensure output encoding on all dynamic pages.
8. **Remove personal contact details** from publicly served pages; use role-based
   contact channels instead.
9. Upgrade the platform (Apache 2.4.29 is EOL-risk) and keep PHP/DB drivers patched.

---

*Generated for authorized security testing by the requester. Evidence saved to
`test_output/` (t1_empty – t5_pwOR responses and cookie jars).*