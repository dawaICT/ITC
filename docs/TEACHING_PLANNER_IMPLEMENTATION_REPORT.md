# Teaching Planner implementation report

## Result

The portal now contains an integrated Teaching Planner with administrator template/syllabus management, lecturer generation/editing/lesson plans, HOS approval and compliance review, registrar monitoring, protected Word export, guarded PDF capability, notifications and audit history.

## Existing architecture reused

- Authentication and roles: existing staff session guard, role helpers and portal access layer.
- Assignment scope: `lecturer_course_assignments` linked to `course_offerings`.
- Academic structure: `programs`, `curriculum_versions`, `curriculum_courses`, `academic_years`, `academic_periods` and `class_groups`.
- Scheduling: `course_schedule` and `academic_calendar_events`.
- Departments/sections: `departments`, `sections` and HOS session scope.
- Notifications: `wuc_notify_portal()` / `portal_alerts`.
- Audit: canonical `audit_log_current_user()` plus planner-specific immutable detail.
- Protected document model: the existing `document_repository` informed the storage/checksum pattern; planner template versions additionally retain placeholder validation and activation state.

The earlier `program_syllabi` upload is retained for source documents. It was not treated as approved normalized content because it stores only file metadata and cannot calculate topic coverage.

## Database changes

Migration `migrations/20260711_teaching_planner.sql` adds:

- `document_templates`, `document_template_versions`
- `syllabus_versions`, `syllabus_outcomes`, `syllabus_topics`
- `teaching_plans`, `teaching_plan_items`
- `lesson_plans`, `lesson_plan_stages`
- `teaching_plan_approvals`, `teaching_plan_exports`, `teaching_plan_audit_logs`
- `teaching_planner_settings`

The migration is additive and was successfully applied to the local `wucportal` schema.

## Security and workflow

- Prepared statements for request-dependent SQL.
- CSRF checks on every mutation and suggestion request.
- Lecturer ownership and active-assignment enforcement.
- HOS section-level checks for syllabus decisions, plan decisions and exports.
- Registrar monitoring is read-only; administrator access does not grant academic approval.
- MIME, extension, ZIP signature, size, placeholder and checksum validation for DOCX uploads.
- Protected storage, path traversal checks and controlled streaming downloads.
- Approved plans are locked; later changes create retained revisions.
- Optimistic `version_lock` prevents concurrent row overwrites.
- External AI is not called. Field suggestions use approved row data and a deterministic fallback.

## Verification performed

- PHP lint: all Teaching Planner PHP entry points and services passed.
- Database migration: applied successfully and required indexes/tables verified.
- Automated tests: **14 passed, 0 failed**.
- Covered scenarios include term, semester and short-course dates; holidays; duplicate sessions; insufficient hours; locked-row regeneration; prerequisite order; assessment/revision reservations; lesson-stage duration; template validation; malicious signatures; authorization; approval/rejection/resubmission; controlled revision; template-version constraints; repeating Word rows; watermarking; and unresolved-placeholder blocking.
- Browser: unauthenticated access correctly redirects to the staff login page. Authenticated visual page verification was not performed because no test credentials were changed or seeded in the production-like database.
- DOCX: the sample passed ZIP/OOXML, repeating-row and unresolved-placeholder structural checks.

## Remaining deployment risks

- LibreOffice and Microsoft Word are not installed on this machine, so DOCX-to-PNG visual rendering and local PDF conversion could not be completed. PDF remains correctly hidden/blocked by capability detection. Open the included sample and each institution template in Word during deployment acceptance.
- The live database currently has no permanent `lecturer_course_assignments` rows; an administrator must configure real offering-based assignments before lecturers can generate production plans.
- No external AI provider was enabled. This is intentional and does not block the module; only the deterministic grounded suggestion fallback is active.
- The current interface edits, locks and revises rows and creates Lesson Plans. Split/merge/reorder operations are represented in the data model but do not yet have dedicated drag-and-drop controls; use controlled row edits/regeneration until those controls are added.
- Automatic syllabus extraction from Word/PDF/Excel is not enabled. Existing uploads remain source documents and must be entered/reviewed as structured drafts before HOS approval.

Because visual DOCX QA and live authenticated browser acceptance remain deployment tasks, this report does not claim unconditional production readiness.

