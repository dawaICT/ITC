# eLearning Module (WUC Portal)

This module provides course content management, live sessions, assessments, forums, and analytics.

Setup:
- Run the installer to create tables and seed permissions:

```
php db/setup_elearning.php
```

Lecturer URLs:
- `elearning/index.php` → course picker
- `elearning/manage.php?course_code=...` → manage modules/content
- `elearning/sessions.php?course_code=...` → live sessions
- `elearning/assessments.php?course_code=...` → quizzes/assignments
- `elearning/analytics.php?course_code=...` → analytics

Student URLs:
- `students/elearning/index.php` → list of registered courses
- `students/elearning/course.php?course_code=...` → released modules/content
- `students/elearning/quiz.php?quiz_id=...` → take quiz

APIs:
- `elearning/api/events_log.php` → analytics event logger (session required)
- `elearning/api/attendance_webhook.php` → Zoom/Teams webhook placeholder (HMAC)
- `elearning/api/turnitin_proxy.php` → Turnitin integration stub

Notes:
- File uploads saved under `uploads/elearning/` relative to project root.
- Uses existing `course_lecturer` and `students`/registration tables.
- Permissions are seeded for `LEC001` and `ADM009`. Adjust as needed.

