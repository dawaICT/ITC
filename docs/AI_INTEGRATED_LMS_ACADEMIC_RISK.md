# AI-Integrated LMS Academic Risk Feature

## 1. Business Logic In Simple Words

This feature watches normal LMS activity and gives each learner an academic risk score.
It does not replace lecturers, HODs, or administrators. It only explains what the data suggests and recommends support.

The feature runs inside existing WUC Portal workflows:

- Student dashboard/profile: the student sees their own academic insight.
- Lecturer dashboard: the lecturer sees risk counts and learners who need follow-up.
- Reports and HOD dashboards can reuse the same engine later.

The main idea is:

1. Read existing LMS data.
2. Add risk points when a learner has warning signs.
3. Convert the total score into Low, Medium, or High risk.
4. Explain the reasons.
5. Recommend a staff action.
6. Store an audit record when the AI support tables exist.
7. Create an alert and intervention record for High risk.

## 2. Algorithm

```text
START

1. Receive a trigger:
   - student dashboard/profile opened
   - lecturer dashboard opened
   - later: attendance saved, marks entered, assignment submitted, report generated

2. Identify the learner by students.SID.

3. Find the learner's active registered courses from course_registration.

4. Read existing LMS signals:
   - live-session attendance from el_live_sessions + el_attendance
   - marks from exams or semester_assessment
   - assignment submissions from el_assignments + el_submissions
   - eLearning activity from el_analytics_events
   - course progress from el_course_progress

5. Start risk_score at 0.

6. Add risk points:
   - attendance below 60%: +30
   - three or more consecutive absences: +20
   - average mark below 50%: +30
   - latest two assessment records below pass threshold: +20
   - assignment submission below 50%: +25
   - no eLearning activity for more than 14 days: +15
   - course progress below 50%: +20

7. Classify the score:
   - 0 to 39: Low
   - 40 to 69: Medium
   - 70 and above: High

8. Generate a recommendation from the reasons.

9. Save the result into student_risk_summary if the table exists.

10. If risk is High:
    - create academic_alerts row if no open alert exists
    - create student_interventions row if no open intervention exists

END
```

## 3. Compatibility With Existing LMS Modules

The implementation is in:

- `includes/academic_risk_engine.php`
- `migrations/2026_06_25_ai_academic_risk.sql`
- `students/index.php`
- `lecturers/index.php`
- `hod/index.php`
- `hod/reports.php`
- `elearning/api/attendance_webhook.php`
- `includes/elearning_live_sessions.php`
- `admin/student_mgmt/attendance.php`
- `includes/ca_helpers.php`
- `includes/grading_helpers.php`
- `includes/result_entry_helpers.php`
- `admin/finalExams.php`
- `admin/upload_finalExams.php`
- `admin/uploaded_exam.php`
- `students/elearning/assignment.php`

The engine uses real WUC Portal tables and column names:

| Business concept | WUC Portal source |
|---|---|
| Student ID | `students.SID` |
| Student program | `student_program.program_code`, `programs.department_id` |
| Registered courses | `course_registration.Sid`, `course_registration.course_code` |
| Attendance | `el_live_sessions` joined to `el_attendance` |
| Assessment marks | `exams`, fallback to `semester_assessment` |
| Assignments | `el_assignments`, `el_submissions` |
| LMS activity | `el_analytics_events` |
| Course progress | `el_course_progress` |
| Lecturer courses | `course_lecturer.staff_id`, `course_lecturer.course_code` |
| HOS course fallback | `course_lecturer`, `course_registration` |

Important compatibility fix:

The attached draft used `INT student_id` and `INT course_id`. WUC Portal uses string student IDs such as `students.SID` and course codes such as `course_registration.course_code`, so the migration uses `VARCHAR` keys.

## 4. Required Database Tables

The normal portal database user is intentionally restricted and may not have permission to create tables.
Use a database administrator/root account for the schema import:

```powershell
Get-Content migrations\2026_06_25_ai_academic_risk.sql | C:\xampp\mysql\bin\mysql.exe -uroot wucportal
```

You can also import `migrations/2026_06_25_ai_academic_risk.sql` through phpMyAdmin.
If your PHP database credentials have DDL permission, this helper also works:

```powershell
C:\xampp\php\php.exe db\create_ai_academic_risk_tables.php
```

The feature adds:

- `student_risk_summary`: stores every calculated risk result for audit/history.
- `academic_alerts`: stores Medium/High follow-up alerts, currently created for High risk.
- `student_interventions`: tracks what staff should do and follow-up status.
- `ai_report_summaries`: ready for later report-summary integration.
- `ai_threshold_settings`: stores configurable thresholds such as 60% attendance and 50% marks.

## 5. Where It Fits In The UI

Student UI:

- Location: `students/index.php`
- The card is displayed inside the existing dashboard/profile column.
- It shows Risk Score, Risk Level, reasons, recommended action, and data notes.
- If the learner is Medium or High risk, the dashboard bell also shows an attention item.

Lecturer UI:

- Location: `lecturers/index.php`
- A new stat card shows High-Risk Learners.
- A dashboard panel named AI Teaching Insight lists Medium/High risk learners and recommended follow-up actions.
- Open AI learner alerts appear in the lecturer dashboard alert panel, scoped to learners in that lecturer's courses.

HOS UI:

- Location: `hod/index.php`
- Academic sections show AI Department Insight with High/Medium/Low learner counts.
- If department-to-program mapping is missing, the dashboard falls back to assigned course scope.
- Open AI department alerts appear in the dashboard alert panel.

Reports UI:

- Location: `hod/reports.php`
- Department reports show an AI Report Summary above the report filters.
- CSV exports include the same AI summary.
- Summaries are stored in `ai_report_summaries` for audit.

Notifications:

- Staff-facing alerts are stored in `academic_alerts`.
- High-risk learners also get an intervention row in `student_interventions`.
- Students receive an `el_student_notifications` record for Medium/High risk, visible through the existing eLearning notification flow.

Future UI extension points:

- Admin settings screen for editing `ai_threshold_settings`.
- Staff workflow screen for changing alert/intervention status to Read, In Progress, Resolved, or Escalated.

## 6. Security Notes For PHP/MySQL

The implementation follows portal conventions:

- Uses prepared statements for user-controlled values.
- Escapes all dynamic HTML with `htmlspecialchars()`.
- Does not let AI change marks, registration, fees, or student status.
- Uses role scope:
  - student dashboard analyzes only the logged-in student's `SID`
  - lecturer dashboard analyzes only students in the lecturer's assigned courses
  - HOS dashboard analyzes the linked department or assigned-course fallback
- Handles missing optional tables without fatal errors.
- Stores explainable reasons instead of black-box decisions.
- Attendance ingest requires a configured `ATTEND_TOKEN`; the old hardcoded example token is not accepted.
- External attendance webhooks require `ELEARN_WEBHOOK_SECRET`; the endpoint stays closed when no secret is configured.
- Legacy exam upload pages now call the same risk recalculation hook after successful writes.

## 7. Debugged Logic Problems From The Draft

Problem: The draft used numeric IDs.
Fix: WUC Portal uses `VARCHAR` IDs and `course_code`, so the schema and queries were adapted.

Problem: Some data may not exist yet.
Fix: Missing data is written as a data note instead of counting as healthy or failing the page.

Problem: CA marks may be out of 40, not 100.
Fix: `semester_assessment.Total_CA` is normalized when it appears to be a 40-point CA score.

Problem: Repeated dashboard visits could create duplicate alerts.
Fix: High-risk alert creation checks for an open alert created within the last 7 days.

Problem: AI should not punish learners.
Fix: The engine only recommends follow-up. It does not block exams, edit results, or change registration.

## 8. Suggested Test Cases

1. Low-risk student
   - Has good attendance, good marks, submitted assignments, and progress above 50%.
   - Expected: Low risk, no alert.

2. Low attendance
   - Attendance percentage below 60%.
   - Expected: +30 points and reason says attendance is low.

3. Consecutive absence
   - Learner missed the latest 3 live sessions.
   - Expected: +20 points and urgent absence reason.

4. Poor marks
   - Average mark below 50%.
   - Expected: +30 points and remedial support recommendation.

5. Missing assignments
   - Submitted fewer than half of due assignments.
   - Expected: +25 points and assignment follow-up recommendation.

6. Inactive learner
   - No `el_analytics_events` activity for more than 14 days.
   - Expected: +15 points and LMS access/use recommendation.

7. High risk
   - Several risk rules trigger and score reaches 70+.
   - Expected: High risk, row in `student_risk_summary`, open `academic_alerts` row, and `student_interventions` row.

8. Missing optional data
   - No attendance sessions or no assignments exist.
   - Expected: page still loads and shows data notes.

9. Lecturer scope
   - Lecturer opens dashboard.
   - Expected: only learners in that lecturer's assigned courses are analyzed.

10. XSS safety
   - Student names or course titles contain special characters.
   - Expected: text renders escaped, not as HTML.

11. Attendance trigger
   - Student joins a live session or attendance is ingested.
   - Expected: `student_risk_summary` receives a fresh calculation without page failure.

12. Assessment trigger
   - CA or exam marks are saved.
   - Expected: learner risk recalculates through `ca_helpers.php` or `grading_helpers.php`.

13. Assignment trigger
   - Student submits an eLearning assignment.
   - Expected: learner risk recalculates and submission-rate risk can improve.

14. HOS dashboard
   - HOS opens dashboard with department mapping or course fallback.
   - Expected: AI Department Insight appears and alerts are scoped to that academic section.

15. HOS report
   - HOS opens or exports department report.
   - Expected: report includes AI Report Summary and `ai_report_summaries` stores an audit row.
