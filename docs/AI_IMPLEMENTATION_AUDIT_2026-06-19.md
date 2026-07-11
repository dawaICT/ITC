# AI Implementation Audit — 2026-06-19

## Scope

Reviewed the shared AI service, local/cloud providers, Markdown rendering, student assistants, lecturer tools, admin reports, admissions applicant review, and the recruitment skill-matching engine.

## Architecture observed

- `includes/ai_portal.php` is the shared generation, rate-limit, fallback, and telemetry layer.
- `includes/ai_cloud.php` provides ordered cloud failover; `ai/ollama.php` provides local chat and embeddings.
- Role-specific pages construct pre-authorized context before calling the shared layer.
- AI-generated Markdown is escaped before the renderer adds its own safe markup.
- Admin reporting uses fixed report handlers rather than model-generated SQL.
- Recruitment matching uses local embeddings plus lexical evidence against `ai_skill_taxonomy`.

## High-priority findings fixed

1. The recruitment taxonomy table was missing, so matching could not run. Added a deployable migration, created the live table, seeded 40 skills, and generated all 40 embeddings.
2. The active local Ollama endpoint is a development mock. Its deterministic vectors are not semantically meaningful. Recruitment matching now detects mock model digests and falls back to lexical evidence instead of presenting random semantic rankings.
3. Recruitment lexical matching did not normalize common word forms (`sell`/`selling`, `record`/`records`). Added bounded normalization, input/result limits, and vector validation.
4. Admissions AI accepted tampered applicant program/results fields even when a stored applicant was selected. Stored non-empty evidence is now authoritative.
5. Admissions AI accepted unknown programs and listed inactive programs. Program values are now allow-listed against active database records.
6. Any global staff session could reach admissions AI. Global sessions now require an admissions-capable role; the legacy admissions login remains supported.
7. The applicant tool asked AI to recommend admission outcomes and included sponsor data. It now only organizes evidence and verification gaps, excludes sponsor data, and explicitly prohibits ranking or outcome recommendations.
8. Invalid applicant submissions consumed rate-limit quota. Quota is now consumed only after validation succeeds.
9. Oversized context was truncated mid-JSON. The shared helper now always returns a valid bounded JSON envelope.
10. AI telemetry retained prompt and response excerpts by default. New requests retain operational metadata but not content unless a caller explicitly opts in.
11. Provider tests, setup utilities, and potential key files under `/ai` were directly web-addressable. The directory is now HTTP-denied, and database loaders also enforce CLI-only execution.
12. Admissions error display could be enabled by any `?debug` query. Debug output now depends only on the development environment.

## Verification

- All audited AI PHP files passed PHP syntax checks.
- Live schema was checked for `ai_portal_logs`, `processed_applicants`, `programs`, staff role tables, and `ai_skill_taxonomy`.
- Recruitment taxonomy: 40 rows, 40 embeddings, one consistent embedding model.
- Representative recruitment text now surfaces records, customer service, selling, payments, and bookkeeping instead of mock-vector noise.
- Oversized context test produced valid JSON within its byte limit.
- Browser checks confirmed `/ai` returns 403 and an unauthenticated admissions AI request redirects to staff login.
- Existing AI telemetry shows successful student chat, staff chat, and admin report calls; historical failures were provider queue limits or the previously offline local service.

## Remaining operational considerations

- Replace the development Ollama mock with a real embedding service before relying on semantic similarity; lexical fallback remains intentionally conservative.
- The recruitment matcher is a backend/CLI engine and proof-of-concept UI only; it is not yet a production recruitment workflow with vacancies, candidates, consent, review, and audit screens.
- Existing historical telemetry excerpts remain in the database. Decide a retention period and purge policy before production rollout.
- Run authenticated browser acceptance tests with a student, lecturer, admissions officer, and systems administrator account before release.
