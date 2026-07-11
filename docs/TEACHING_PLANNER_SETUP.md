# Teaching Planner setup

## Requirements

- PHP 8.2 with `mysqli`, `fileinfo`, `mbstring`, `json`, `openssl` and `zip`.
- MariaDB 10.4 or newer.
- Writable protected storage. The default is `C:\xampp\wucportal-var\teaching-planner`, outside `htdocs`.
- Optional PDF conversion: LibreOffice (`soffice.exe`). If it is absent, the UI offers Word only and does not produce a broken PDF.

The module does not require an external AI provider or PHPWord. It edits the retained DOCX package directly so its headers, footers, tables, logos, fonts, margins and orientation remain under template control.

## Install

1. Back up the database.
2. Apply the additive migration:

   ```powershell
   Get-Content -Raw migrations\20260711_teaching_planner.sql | C:\xampp\mysql\bin\mysql.exe -u root wucportal
   ```

3. Optionally set `WUC_TEACHING_PLANNER_STORAGE` to an absolute protected directory available to the Apache service account.
4. Verify that the storage directory is not web-executable and grant only the web-service account and administrators read/write access.
5. Upload and validate a DOCX template from **Administration → Teaching Planner**.
6. Create a structured syllabus draft. A Head of Section must approve it.
7. Confirm that the lecturer has an active `lecturer_course_assignments` row linked to a live `course_offerings` record and matching `course_schedule` sessions.
8. Generate, review and save the lecturer's plan; submit it for HOS approval.

## Tests

Run:

```powershell
C:\xampp\php\php.exe tests\teaching_planner\run.php
```

The integration fixture uses a transaction and rolls back its database rows. Its approved export is copied to `artifacts/teaching-planner-sample.docx` as a non-production sample.

## Operational notes

- Never move protected templates or exports into a public upload directory.
- Back up the Teaching Planner storage root together with the database; database rows retain the exact template and export checksums.
- Approved plans are immutable. Use **Create controlled revision** for changes.
- Draft Word exports contain a DRAFT watermark. Approved/in-use exports do not.
- External AI is deliberately optional. The current suggestion buttons use a deterministic, syllabus-grounded fallback and never transmit staff or student data.

