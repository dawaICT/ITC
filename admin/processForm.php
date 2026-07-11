<?php
/**
 * LEGACY HANDLER — RETIRED.
 *
 * This file was an abandoned, truncated copy of the new-student registration
 * handler: it concatenated raw POST values into SQL (injection risk), targeted
 * columns that no longer exist in the students table (grade, dte1, dte2), and
 * the file ended mid-statement so the INSERT could never even run. No form in
 * the admin module posts here anymore — admin/regNewStud.php contains the
 * current, complete registration pipeline (student + program + login + invoice).
 *
 * Kept only so old links/bookmarks land somewhere sensible.
 */
require "includes/admin.php";

header('Location: regNewStud.php');
exit();
