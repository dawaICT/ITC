# AI Roadmap Implementation Report

Date: 2026-06-18
Project: WUC Portal legacy PHP/MySQL application

## Summary

Implemented the recommended AI roadmap as a first safe release:

- Shared role-aware AI service layer.
- Student AI Course Advisor.
- Student AI Study Assistant.
- Lecturer AI Question Bank Generator.
- Lecturer AI Progression Insights.
- Admin AI Reports using approved report functions.
- AI request logging schema.
- Navigation wiring for student, lecturer, and admin modules.
- Debug and syntax checks for all changed PHP files.

The implementation is read-only by design. AI can explain, summarize, draft, and advise, but it cannot register courses, clear finance, post assignments, alter marks, or update student records.

## Files Added

- `includes/ai_portal.php`
  Shared AI helper layer. Wraps the existing local Ollama client, checks local AI availability, applies per-session rate limits, strips model reasoning blocks, logs to `ai_portal_logs`, and provides fallback handling.

- `students/ai_course_advisor.php`
  Student-facing course registration advisor. Uses `RegistrationDataService`, `FeeGuard`, and `EligibilityService` to build student-scoped context.

- `students/ai_study_assistant.php`
  Student-facing study assistant. Uses enrolled-course checks and eLearning material records to summarize, explain, quiz, or build revision plans.

- `lecturers/ai_question_bank.php`
  Lecturer-facing draft question-bank generator. Validates that the lecturer is assigned to the selected course before generating questions.

- `lecturers/ai_progression_insights.php`
  Lecturer-facing at-risk progression insight generator. Uses `student_progression_report()` in lecturer scope.

- `admin/ai_reports.php`
  Admin-facing natural-language report page. Maps questions to approved report handlers and summarizes only returned report data.

- `migrations/2026_06_18_ai_portal_logs.sql`
  Idempotent SQL migration for AI activity logs.

- `docs/AI_ROADMAP_IMPLEMENTATION_REPORT.md`
  This implementation and operations report.

## Files Updated

- `students/includes/navbar.php`
  Added `AI Course Advisor` beside Course Registration and `AI Study Assistant` in the eLearning section.

- `lecturers/includes/nav.php`
  Added `AI Question Bank` to eLearning and `AI Progression Insights` to Reports.

- `admin/includes/nav.php`
  Added `AI Reports` to the admin Reports section.

- `lecturers/assessments.php`
  Added `AI Question Bank` to the assessment command bar.

- `lecturers/post_assign.php`
  Added `AI Question Bank` to the assignment command bar.

## How It Works

### Shared AI Service

`includes/ai_portal.php` reuses the existing local AI code in:

- `ai/config.php`
- `ai/ollama.php`

It checks Ollama availability, resolves the active chat model, sends messages to the local model when ready, and logs every attempt to `ai_portal_logs`.

The log stores role, user ID, feature, request summary, context hash, model, status, response excerpt, error message, duration, and hashed IP. It intentionally avoids storing full student or report context.

### Student AI Course Advisor

URL: `/wucportal/students/ai_course_advisor.php`

Reads the logged-in student, latest registration term, available courses, registered courses, credits, payment signal, and failed/carryover courses. It explains what the student should check before course registration. It cannot submit, drop, approve, or modify courses.

### Student AI Study Assistant

URL: `/wucportal/students/ai_study_assistant.php`

Reads only courses the student is enrolled in, then uses readable `lesson_notes` excerpts and eLearning content titles. It supports summaries, explanations, quiz questions, and revision plans. It does not claim official exam answers.

### Lecturer AI Question Bank

URL: `/wucportal/lecturers/ai_question_bank.php`

Reads only courses assigned to the logged-in lecturer. The lecturer selects course, topic, question type, difficulty, and count. Output is a draft only and is not saved to assignments, CA, quizzes, or exams.

### Lecturer AI Progression Insights

URL: `/wucportal/lecturers/ai_progression_insights.php`

Uses `student_progression_report($db, $filters, 'lecturer', $staffId)` so rows are scoped to the lecturer’s assigned courses. It summarizes risks and follow-up actions. It cannot change marks, registrations, finance status, or student status.

### Admin AI Reports

URL: `/wucportal/admin/ai_reports.php`

Accepts natural-language questions but never generates SQL. Questions map to approved report handlers:

- students by program
- finance collection summary
- semester registrations without course registrations
- progression alerts

AI only summarizes the approved result set and row preview.

## Security Controls

- Existing student, lecturer, and admin guards are reused.
- POST generation requires CSRF validation.
- Lecturer course generation requires active course assignment.
- Student study generation requires enrolled-course access.
- Admin AI Reports use a fixed report registry, not model-generated SQL.
- New SQL queries use prepared statements where user input is involved.
- AI is read-only and cannot execute database writes except request logging.
- Output is escaped with `htmlspecialchars()` before rendering.
- Per-session rate limits reduce accidental overuse.
- AI logs hash context instead of storing full context payloads.
- Missing model or offline AI paths return deterministic fallback responses.

## Setup

1. Ensure local Ollama is running:

   `ollama serve`

2. Ensure a local chat model is installed. Current config defaults to:

   `deepseek-r1:1.5b`

   Alternative fallbacks configured in `ai/config.php` include:

   `llama3.2:3b`

3. Run the logging migration, or allow the service to create the table on first use:

   `migrations/2026_06_18_ai_portal_logs.sql`

4. Open:

   `/wucportal/students/ai_course_advisor.php`

   `/wucportal/students/ai_study_assistant.php`

   `/wucportal/lecturers/ai_question_bank.php`

   `/wucportal/lecturers/ai_progression_insights.php`

   `/wucportal/admin/ai_reports.php`

## Verification Performed

PHP syntax checks were run with XAMPP PHP because `php` is not on PATH:

- `E:\xampp\php\php.exe -l includes\ai_portal.php`
- `E:\xampp\php\php.exe -l students\ai_course_advisor.php`
- `E:\xampp\php\php.exe -l students\ai_study_assistant.php`
- `E:\xampp\php\php.exe -l lecturers\ai_question_bank.php`
- `E:\xampp\php\php.exe -l lecturers\ai_progression_insights.php`
- `E:\xampp\php\php.exe -l admin\ai_reports.php`
- `E:\xampp\php\php.exe -l students\includes\navbar.php`
- `E:\xampp\php\php.exe -l lecturers\includes\nav.php`
- `E:\xampp\php\php.exe -l admin\includes\nav.php`
- `E:\xampp\php\php.exe -l lecturers\assessments.php`
- `E:\xampp\php\php.exe -l lecturers\post_assign.php`

Result: all checked PHP files reported no syntax errors.

Runtime helper check:

- `E:\xampp\php\php.exe -r "require 'includes/ai_portal.php'; echo json_encode(wuc_ai_local_status(), JSON_PRETTY_PRINT);"`

Result:

- Ollama is reachable.
- Configured chat model is `deepseek-r1:1.5b`.
- The configured chat model is not currently installed, so pages use fallback mode until the model is pulled or `WUC_AI_CHAT_MODEL` / `AI_CHAT_MODEL` points to an installed chat model.

Database schema check:

- `E:\xampp\php\php.exe -r "require 'db/connect.php'; require 'includes/ai_portal.php'; wuc_ai_ensure_schema($db); echo wuc_ai_table_exists($db, 'ai_portal_logs') ? 'ai_portal_logs ready' : 'ai_portal_logs missing';"`

Result:

- `ai_portal_logs ready`

## Debug/Fix Notes

- Initial `php -l` failed because `php` is not available on PATH.
- Re-ran syntax checks with `E:\xampp\php\php.exe`.
- Student navigation initially risked duplicate advisor links, so the advisor link was kept beside Course Registration.
- AI fallback behavior was added so pages work when Ollama is offline, a model is missing, or a model returns an empty response.
- Missing chat-model handling was tightened so the service falls back immediately when Ollama is reachable but the selected chat model is not installed.
- Follow-up debug found the local Ollama install only has `nomic-embed-text:latest`, which is embedding-only. The configured chat model `deepseek-r1:1.5b` is missing, so the portal correctly uses `model_missing` fallback until `ollama pull deepseek-r1:1.5b` completes or another chat model is configured.
- Page fallback notices now show the concrete reason from `wuc_ai_generate()` instead of the old generic "Local AI was unavailable or returned no answer" message.
- The schema verification command initially needed PowerShell escaping for `$db`; after escaping, the log table setup completed successfully.
- Admin natural-language reports use a fixed report registry to avoid arbitrary SQL generation.
- Student study support reads enrolled-course material records instead of parsing uploaded files in the first release.

## Remaining Manual Checks

These require live browser sessions and real portal logins:

- Log in as a regular student and confirm `AI Course Advisor` appears beside Course Registration.
- Generate advice for a student with semester registration and registered courses.
- Generate advice for a student without semester registration.
- Log in as a student and generate a study summary for an enrolled course with materials.
- Log in as a lecturer and confirm only assigned courses appear in `AI Question Bank`.
- Generate MCQ, short-answer, essay, and mixed question drafts.
- Log in as a lecturer and generate progression insights for assigned courses.
- Log in as an admin and ask each approved AI Reports question type.
- Confirm `ai_portal_logs` receives entries from browser-generated student, lecturer, and admin requests.
- Confirm unauthorized lecturer course selection is rejected.

## Recommended Next Phase

- Install or configure the local chat model so pages leave fallback mode.
- Add optional file text extraction for PDFs/DOCX/PPTX in course materials.
- Add admin-configurable AI enable/disable flags by role.
- Add export from generated question-bank drafts into reviewed quiz/assignment workflows.
- Expand the approved-report registry for finance, admissions, library, and eLearning.
