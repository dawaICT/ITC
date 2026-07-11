# WUCPortal Zero-Cost AI Capability — Implementation Plan

**Goal:** Make WUCPortal smarter, more automated, and more insight-driven using local logic, open-source tools, rule-based algorithms, existing database data, and optional free/local AI models — **without paid APIs, paid cloud services, or expensive infrastructure**.

**Stack:** XAMPP, PHP, MySQL, JavaScript, optional Ollama on localhost.

**Core rule:** Every feature must work with **no external API**. Deterministic rules + templates + DB analytics are the source of truth. LLM output is narrative polish only.

**Audit date:** 2026-07-02  
**Based on:** Codebase review of `includes/ai_portal.php`, `includes/academic_risk_engine.php`, chat widgets, report summaries, Ollama client, and related migrations.

---

## Executive Summary

| Category | Status |
|---|---|
| **Already implemented** | Academic risk engine, student/lecturer/admin AI pages, chatbot with DB memory, report AI summaries, admissions assistants, Ollama + fallbacks |
| **Partially implemented** | Notifications, smart search, HOD/HOS section intelligence, course recommendations, eLearning engagement |
| **Not implemented** | FAQ/knowledge-base chatbot layer, unified alert hub, portal-wide search, structured `report_insights` store |
| **Cost model** | Rule-based PHP/MySQL first; optional local Ollama; disable cloud providers for strict zero-cost mode (`WUC_AI_BACKEND=local`) |

WUCPortal is **~60% AI-ready**. This plan consolidates rule-based intelligence, fills gaps, and treats local AI as optional enhancement only.

---

## Phase 1 — Module-by-Module AI Readiness Audit

### Legend

- **Ready** — production-usable today
- **Partial** — exists but incomplete or inconsistent
- **Gap** — needs new work

| Module | Readiness | Existing assets | Best zero-cost opportunities |
|---|---|---|---|
| **Admin** | Partial | `admin/ai_reports.php`, analytics dashboard, `student_risk_summary`, `ai_portal_logs` | Unified insight dashboard; batch risk re-score cron; missing-CA/fee alerts |
| **Registrar** | Gap | Staff chat widget, `ai_contexts` seed row | Registration completeness checker; missing semester/course reg alerts; progression blockers |
| **Admissions** | Ready | `ai_applicant_assistant.php`, `ai_letter_drafter.php`, report summaries | Rule-based doc checklist, program-fit matrix, incomplete-app flags (reduce LLM reliance) |
| **Students** | Ready | Course advisor, study assistant, personal chat, risk card, skill discovery | Course recommendation engine; study checklist from CA gaps; eLearning engagement nudges |
| **Staff (generic)** | Partial | Unified nav AI widget, `ai_staff_chat.php` | Role-scoped FAQ intents; task alerts by module |
| **Lecturers** | Ready | Progression insights, question bank, feedback AI, risk panels on dashboard | Missing-CA list, class stats, pass/fail distribution (rules, not LLM) |
| **HOD / HOS** | Partial | Department risk summary, alerts panel, section helpers, transport vs academic split | Section-scoped report insights; lecturer workload; CA upload compliance |
| **Accounts** | Partial | Fees reports, `student_fee_accounts`, `student_payments` | Unpaid balance alerts; registration gate warnings; fee trend summaries |
| **Library** | Gap | `students/digital_library.php` (text search + AI chat) | Overdue books, inactive borrowers, reading recommendations from program |
| **Reports** | Partial | `trx_ai_summary()` across admin/dean/VC/HOD/transport | Standard insight block: summary, warnings, trends, actions (template-driven) |
| **eLearning** | Partial | Materials, submissions, live sessions, AI writing flags | Unread/incomplete tracking; inactive student flags; revision topics from low CA |
| **Course registration** | Partial | `RegistrationDataService`, eligibility services | Missing/repeat course detection; term/semester-aware recommendations |
| **CA / results upload** | Partial | `semester_assessment`, `ca_helpers.php`, upload pages | Missing CA detection; below-threshold alerts; lecturer upload SLA |
| **Attendance** | Partial | Hooks in risk engine (if table exists) | Schema-guarded attendance scoring; already partially in `academic_risk_engine.php` |
| **Notifications** | Partial | `academic_alerts`, `el_student_notifications`, flash alerts | Central `portal_alerts` hub on dashboards (no SMS/email required) |

---

## Phase 2 — Zero-Cost Intelligence Features

### Priority matrix

| # | Feature | Current state | Plan action | Effort |
|---|---|---|---|---|
| 1 | **Student risk detection** | Implemented (`academic_risk_engine.php`) | Extend signals (fees, failed courses); scheduled batch job; registrar view | M |
| 2 | **Lecturer dashboard insights** | Partial (risk panels exist) | Deterministic stats: missing CA, class avg, pass/fail, weak courses | M |
| 3 | **HOD/HOS reports** | Partial | Section-filtered insight blocks; CA compliance; workload counts | M |
| 4 | **Admissions assistant** | Ready (LLM-heavy) | Rule layer before LLM: doc checklist, program eligibility matrix | M |
| 5 | **Course recommendation** | Partial (`ai_course_advisor` is LLM) | New `includes/course_recommendation_engine.php` (pure rules) | M |
| 6 | **Smart report generator** | Partial (`trx_ai_summary`) | `includes/report_insights_engine.php` — template insights + optional LLM narrative | L |
| 7 | **Smart search** | Gap (library text search only) | Portal search service: keyword + LIKE + recent searches; optional embeddings later | L |
| 8 | **Smart notifications** | Partial | `portal_alerts` table + dashboard widgets per role | M |
| 9 | **eLearning support** | Partial | Engagement scorer: unread, incomplete, inactive, CA-linked revision list | M |
| 10 | **Chatbot without paid AI** | Partial (LLM chat exists) | FAQ/knowledge base + intent router; LLM only if Ollama up | L |

### Feature specifications

#### 1. Smart Student Risk Detection

**Signals (extend existing engine):**

- Low CA marks
- Missing assessments
- Poor attendance (schema-guarded)
- Unpaid fees (`student_fee_accounts`, `payments`)
- Incomplete registration
- No eLearning activity
- Repeated failed courses

**Classification:** Low / Medium / High with stored reasons in `student_risk_summary`.

**Key file:** `includes/academic_risk_engine.php`

#### 2. Smart Lecturer Dashboard Insights

- Students missing CA
- Students below threshold
- Courses with weak performance
- Assessment submission status
- Class average, highest/lowest marks
- Pass/fail distribution

**Key file (new):** `includes/lecturer_insights_engine.php`

#### 3. Smart HOD/HOS Reports

- Department/section performance summary
- Program performance summary
- Course failure hotspots
- Lecturer workload summary
- Missing CA uploads
- Student progression issues
- Section-specific reports (Engineering/ICT vs Transport)

**Key files:** `includes/hos_report_insights.php`, `includes/hos_section_helpers.php`

#### 4. Smart Admissions Assistant

- Recommend programs from qualifications (rule matrix)
- Detect missing documents
- Explain admission status (templates)
- Generate professional notes (template + optional Ollama)
- Flag incomplete applications
- Suggest next steps

**Key file (new):** `includes/admissions_rules_engine.php`

#### 5. Smart Course Recommendation

- Courses student should register for (from `program_courses`)
- Repeat/retake courses
- Missing courses vs program curriculum
- Match program, level, term, semester, intake

**Key file (new):** `includes/course_recommendation_engine.php`

#### 6. Smart Report Generator

Every report should include (template-driven, no LLM required):

- Summary
- Key findings
- Warnings
- Trends
- Missing records
- Recommended actions
- Printable layout

**Key file (new):** `includes/report_insights_engine.php`

#### 7. Smart Search

- Keyword search across students, staff, programs, courses, departments, reports, admissions
- Filters by role visibility
- Recent searches
- No-result suggestions
- Optional: embedding search via `ai/match.php` pattern (Phase 2+)

**Key files (new):** `includes/portal_search.php`, `api/search_suggest.php`

#### 8. Smart Notifications

Internal dashboard alerts for:

- Missing CA uploads
- Pending registration
- Incomplete admission
- Unpaid balances
- Expiring deadlines
- Low performance
- Missing documents
- Lecturer pending tasks

**No paid SMS or email required.**

**Key file (new):** `includes/portal_alerts.php`

#### 9. Smart eLearning Support

- Recommend unread materials
- Show incomplete lessons
- Flag inactive students
- Course engagement summary
- Revision topics from poor CA
- Study checklist templates (no LLM)

**Key file (new):** `includes/elearning_insights_engine.php`

#### 10. Smart Chatbot Without Paid AI

- FAQ table + keyword/intent matching
- Program/course/admissions knowledge base
- Static response templates
- Escalation to staff when no match
- Conversation history stored locally
- LLM only when Ollama available

**Key file (new):** `includes/faq_chat_engine.php`

---

## Phase 3 — Optional Free/Open-Source AI Layer

### Existing infrastructure

| Component | File | Purpose |
|---|---|---|
| Local client | `ai/ollama.php` | Chat + embeddings via localhost:11434 |
| Config | `ai/config.php` | Models: `deepseek-r1:1.5b`, `nomic-embed-text` |
| High-level API | `includes/ai_portal.php` | `wuc_ai_generate()`, rate limits, logging, fallbacks |
| Cloud fallback | `includes/ai_cloud.php` | Pollinations/Groq (disable for strict zero-cost) |
| Dev mock | `ai/mock_server.php` | Demo only — not for production semantics |

### Strict zero-cost configuration

```env
WUC_AI_BACKEND=local
# Do not set WUC_AI_CLOUD_API_KEY
# Do not use Pollinations/Groq if policy requires offline-only
```

### Recommended local stack (XAMPP)

| Component | Purpose | Requirement |
|---|---|---|
| **Ollama** | Chat narratives, letter drafting, report summaries | 8GB+ RAM; `llama3.2:3b` or `deepseek-r1:1.5b` |
| **nomic-embed-text** | Semantic search (optional) | Same Ollama install |
| **PHP CLI cron** | Batch risk re-score, alert generation | Available in XAMPP |

**Fallback contract:** Every `wuc_ai_generate()` call must provide a `fallback` closure. Pages never hard-fail when Ollama is offline.

---

## Phase 4 — Database Plan (Additive Only)

### Already exists (use as-is)

| Table | Purpose |
|---|---|
| `student_risk_summary` | Risk scores + reasons |
| `academic_alerts` | Staff alert workflow |
| `student_interventions` | Follow-up tracking |
| `ai_report_summaries` | Persisted report narratives |
| `ai_threshold_settings` | Configurable thresholds |
| `ai_portal_logs` | AI call audit |
| `ai_contexts` | Role guardrails |
| `ai_skill_taxonomy` | Embedding skill labels |
| `chatbot_conversations`, `chatbot_messages`, `chatbot_memory` | Chat persistence (runtime DDL in `includes/chatbot_db.php`) |

### Recommended new tables

Migration file: `migrations/20260702_zero_cost_ai_foundations.sql`

```sql
-- 1. Unified portal alerts
CREATE TABLE IF NOT EXISTS portal_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    user_role VARCHAR(50) NOT NULL,
    alert_type VARCHAR(80) NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id VARCHAR(50) NULL,
    action_url VARCHAR(500) NULL,
    status ENUM('unread','read','dismissed') NOT NULL DEFAULT 'unread',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL,
    KEY idx_user_status (user_id, status),
    KEY idx_type_created (alert_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Rule-based recommendations (explainable)
CREATE TABLE IF NOT EXISTS ai_recommendations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_user_id VARCHAR(50) NOT NULL,
    target_role VARCHAR(50) NOT NULL,
    recommendation_type VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id VARCHAR(50) NULL,
    score DECIMAL(5,2) NULL,
    reasons_json JSON NOT NULL,
    status ENUM('active','accepted','dismissed','expired') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    KEY idx_target (target_user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. FAQ / knowledge base (zero-cost chatbot)
CREATE TABLE IF NOT EXISTS faq_knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(80) NOT NULL,
    intent_key VARCHAR(80) NOT NULL,
    keywords TEXT NOT NULL,
    question VARCHAR(500) NOT NULL,
    answer_template TEXT NOT NULL,
    roles_allowed VARCHAR(200) NOT NULL DEFAULT 'all',
    priority INT NOT NULL DEFAULT 0,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_intent (intent_key),
    KEY idx_category (category, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. Structured report insights
CREATE TABLE IF NOT EXISTS report_insights (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_key VARCHAR(80) NOT NULL,
    scope_type VARCHAR(50) NOT NULL,
    scope_id VARCHAR(50) NULL,
    period_label VARCHAR(100) NULL,
    summary_json JSON NOT NULL,
    warnings_json JSON NULL,
    trends_json JSON NULL,
    actions_json JSON NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by VARCHAR(50) NULL,
    KEY idx_report_scope (report_key, scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. Search analytics
CREATE TABLE IF NOT EXISTS search_recent (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    user_role VARCHAR(50) NOT NULL,
    query_text VARCHAR(500) NOT NULL,
    result_count INT NOT NULL DEFAULT 0,
    searched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user_searched (user_id, searched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. Lecturer task alerts
CREATE TABLE IF NOT EXISTS lecturer_task_alerts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    staff_id VARCHAR(20) NOT NULL,
    task_type VARCHAR(80) NOT NULL,
    course_code VARCHAR(30) NULL,
    due_hint VARCHAR(100) NULL,
    message TEXT NOT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    status ENUM('open','done','dismissed') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_staff_status (staff_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. Automated decision audit
CREATE TABLE IF NOT EXISTS ai_decision_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    feature VARCHAR(80) NOT NULL,
    decision_type VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id VARCHAR(50) NULL,
    input_summary VARCHAR(500) NULL,
    outcome VARCHAR(200) NOT NULL,
    reasons_json JSON NULL,
    user_id VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_feature_created (feature, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Rules:**

- Do not break existing tables
- Do not rename existing columns unless necessary
- Use indexes on frequently searched fields
- Keep all logic explainable and auditable

---

## Phase 5 — Security and Privacy

| Requirement | Implementation |
|---|---|
| RBAC | Scope all queries by session role + section/dept assignment (reuse HOS helpers) |
| Explainability | Store `reasons_json` on every risk score and recommendation |
| No auto-decisions | AI never approves admission, graduation, or fee clearance |
| Input validation | Prepared statements; CSRF on all POST AI endpoints |
| Prompt injection | Sanitize chat input; FAQ matcher ignores instruction-like patterns |
| XSS | `wuc_ai_output_block()` / `htmlspecialchars()` on all dynamic output |
| Privacy | Aggregates only in LLM context; no NRC, full payment refs, or sponsor data |
| Audit | Log to `ai_decision_logs` + existing `ai_portal_logs` |
| Zero-cost mode | `WUC_AI_BACKEND=local`; cloud chain disabled in production config |

**Principle:** AI assists users; it does not replace approval workflows.

---

## Phase 6 — Implementation Roadmap (10 Sprints)

### Sprint 0 — Baseline (1 week)

- [ ] Run AI readiness checklist per module (`scratch/ai_readiness_audit.php`)
- [ ] Document live schema vs code
- [ ] Set production config: `WUC_AI_BACKEND=local`, cloud disabled
- [ ] Verify Ollama optional: pages load with fallbacks when Ollama offline

### Sprint 1 — Database foundations (1 week)

- [ ] Migration: `portal_alerts`, `ai_recommendations`, `faq_knowledge_base`, `report_insights`, `ai_decision_logs`
- [ ] Helper: `includes/portal_alerts.php`
- [ ] Wire alert stack into `includes/nav_unified.php` + student navbar

### Sprint 2 — Student risk engine v2 (1–2 weeks)

- [ ] Extend `academic_risk_engine.php` with fees, registration, failed-course signals
- [ ] CLI batch job: `scripts/batch_risk_rescore.php`
- [ ] Admin/registrar risk list pages (read-only, explainable)

### Sprint 3 — Lecturer deterministic insights (1 week)

- [ ] New `includes/lecturer_insights_engine.php`
- [ ] Integrate into `lecturers/index.php`
- [ ] Generate `lecturer_task_alerts` for missing uploads

### Sprint 4 — HOD/HOS section intelligence (1–2 weeks)

- [ ] New `includes/hos_report_insights.php`
- [ ] Section filters via `hos_section_helpers.php`
- [ ] Insight block on `hod/academic_reports.php`

### Sprint 5 — Admissions rule assistant (1 week)

- [ ] New `includes/admissions_rules_engine.php`
- [ ] Refactor `admissions/ai_applicant_assistant.php`: rules first, LLM optional
- [ ] Seed `faq_knowledge_base` with admissions FAQs

### Sprint 6 — Course recommendation engine (1 week)

- [ ] New `includes/course_recommendation_engine.php`
- [ ] Surface on `students/registration.php` and student dashboard

### Sprint 7 — Smart report generator (1–2 weeks)

- [ ] New `includes/report_insights_engine.php`
- [ ] Integrate into `includes/training_reports_engine.php`
- [ ] Roll out to dean, VC, admin, transport, HOD reports

### Sprint 8 — Smart search (1–2 weeks)

- [ ] New `includes/portal_search.php` + `api/search_suggest.php`
- [ ] Optional: embedding search for programs/courses

### Sprint 9 — FAQ chatbot layer (1 week)

- [ ] New `includes/faq_chat_engine.php`
- [ ] Refactor chat endpoints: FAQ first → Ollama → static fallback
- [ ] Seed 50–100 FAQs

### Sprint 10 — eLearning intelligence (1 week)

- [ ] New `includes/elearning_insights_engine.php`
- [ ] Student dashboard widget + lecturer engagement panel

---

## Phase 7 — Testing Requirements

### Role-based test matrix

| Role | Test flows |
|---|---|
| Admin | AI reports, risk batch, alert hub, search |
| Registrar | Registration alerts, risk list, search |
| Student | Risk card, course recommendations, FAQ chat, eLearning checklist |
| Lecturer | Missing CA insights, task alerts, progression panel |
| HOD/HOS | Section-filtered reports, department alerts |
| Admissions | Rule assistant, incomplete flags, letter drafter fallback |
| Accounts | Fee-based risk signal, unpaid alerts |
| Library | Search, overdue alerts (if scope included) |

### Verification checklist

- [ ] Ollama **off**: all pages load; fallbacks return useful text
- [ ] Ollama **on**: narratives enhance but don't change rule outcomes
- [ ] RBAC: student A cannot see student B's risk/recommendations
- [ ] HOS Transport cannot see Engineering/ICT data
- [ ] No SQL errors on schema-mismatch installs (SHOW TABLES guards)
- [ ] No raw DB errors exposed to browser
- [ ] CSRF enforced on all AI POST endpoints
- [ ] `ai_decision_logs` records batch risk runs and alert creation

---

## Already Implemented (Do Not Rebuild)

| Feature | Location |
|---|---|
| Shared AI service | `includes/ai_portal.php` |
| Academic risk (rules) | `includes/academic_risk_engine.php` |
| Student AI pages | `students/ai_*` |
| Lecturer AI pages | `lecturers/ai_*` |
| Admin AI reports | `admin/ai_reports.php` |
| Admissions AI | `admissions/ai_*` |
| Chatbot + memory | `includes/chatbot_db.php`, widgets |
| Report AI summaries | `trx_ai_summary()` in `includes/training_reports_engine.php` |
| Risk tables | `migrations/2026_06_25_ai_academic_risk.sql` |
| AI logging | `migrations/2026_06_18_ai_portal_logs.sql` |
| Skill matching | `ai/match.php`, `ai_skill_taxonomy` |
| Transport intelligence | `transport/services/TransportIntelligence.php` (rule-based) |

### Related documentation

- `docs/AI_IMPLEMENTATION_AUDIT_2026-06-19.md`
- `docs/AI_ROADMAP_IMPLEMENTATION_REPORT.md`
- `docs/AI_INTEGRATED_LMS_ACADEMIC_RISK.md`
- `.claude/skills/wuc-ai/SKILL.md`

---

## Remaining Risks

1. **Schema drift** — risk/search queries must use dynamic column detection (see `wuc-schema-debug` skill).
2. **Cloud fallback vs zero-cost policy** — Pollinations/Groq exist in code; disable for strict offline policy.
3. **Mock Ollama in dev** — semantic embeddings unreliable; use lexical search until real Ollama is installed.
4. **Fragmented alerts** — `academic_alerts`, eLearning notifications, flash messages need unified hub.
5. **LLM over-use** — several features call LLM where rules would suffice; refactor per Sprint 5–6.

---

## Success Criteria

WUCPortal feels "highly AI-capable at zero cost" when:

- Dashboards show **actionable alerts** (missing CA, unpaid fees, incomplete apps) without paid services
- Reports include **structured insights** even when Ollama is offline
- Chatbot answers common questions from **FAQ/intent matching** without an API
- Risk scores and recommendations are **explainable** with stored reasons
- Optional Ollama adds narrative polish only — never required for core workflows

---

## Recommended First Implementation Slice

**Sprint 1 (DB + alert hub) + Sprint 2 (risk engine v2)**

Highest impact, lowest cost, builds directly on existing code.

---

## Important Principle

Make WUCPortal feel intelligent through useful automation, insights, recommendations, alerts, and decision support.

**Do not add AI for decoration.** Every AI-capable feature must solve a real academic, administrative, reporting, admissions, lecturer, or student support problem.
