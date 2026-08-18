-- Allow the same lecturer/course/period to be assigned under different programs.
-- Previous unique key (staff_id, course_code, academic_year, semester) blocked
-- multi-program curriculum courses (e.g. CCAM-101 across AUTO-001..005).

ALTER TABLE course_lecturer
  DROP INDEX uq_lecturer_course_period;

ALTER TABLE course_lecturer
  ADD UNIQUE KEY uq_lecturer_course_ctx (staff_id, course_code, program_code, academic_year, semester);
