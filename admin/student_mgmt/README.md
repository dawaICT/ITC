Student Management Module

- Admissions: review `online_applicants`, check O-level credits (>=5), accept to `processed_applicants`.
- Registration: uses canonical `semester_registration.php` with program/period/year validation and active enrolment checks.
- Records: generate transcripts and export QMIS CSV (`records.php?export=qmis`).
- Attendance: ingestion API at `attendance.php?ingest=1&token=ATTEND123` with fields `Sid, course_code, timestamp, source`.

DB helpers: see `db/qmis_and_zaqa.sql`.

