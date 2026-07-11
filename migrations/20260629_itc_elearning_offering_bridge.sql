-- ============================================================================
-- ITC Academic Data Structure - eLearning Offering Bridge
--
-- Adds course_offering_id to the active eLearning material structures while
-- preserving legacy course_code lookups. Existing rows are backfilled only when
-- a course code maps to one unambiguous offering.
-- ============================================================================

ALTER TABLE el_course_modules
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_el_modules_offering (course_offering_id);

ALTER TABLE lesson_notes
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_lesson_notes_offering (course_offering_id);

ALTER TABLE elearning_modules
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_elearning_modules_offering (course_offering_id);

UPDATE el_course_modules m
  JOIN (
        SELECT cc.course_code, MIN(co.id) AS course_offering_id
          FROM course_offerings co
          JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
         WHERE co.status IN ('planned','active','completed')
         GROUP BY cc.course_code
        HAVING COUNT(*) = 1
       ) x ON UPPER(TRIM(x.course_code)) = UPPER(TRIM(m.course_code))
   SET m.course_offering_id = x.course_offering_id
 WHERE m.course_offering_id IS NULL;

UPDATE lesson_notes ln
  JOIN el_contents c ON c.id = ln.el_content_id
  JOIN el_course_modules m ON m.id = c.module_id
   SET ln.course_offering_id = m.course_offering_id
 WHERE ln.course_offering_id IS NULL
   AND m.course_offering_id IS NOT NULL;

UPDATE lesson_notes ln
  JOIN (
        SELECT cc.course_code, MIN(co.id) AS course_offering_id
          FROM course_offerings co
          JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
         WHERE co.status IN ('planned','active','completed')
         GROUP BY cc.course_code
        HAVING COUNT(*) = 1
       ) x ON UPPER(TRIM(x.course_code)) = UPPER(TRIM(ln.course_code))
   SET ln.course_offering_id = x.course_offering_id
 WHERE ln.course_offering_id IS NULL;

UPDATE elearning_modules em
  JOIN courses c ON c.id = em.course_id
  JOIN (
        SELECT cc.course_code, MIN(co.id) AS course_offering_id
          FROM course_offerings co
          JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
         WHERE co.status IN ('planned','active','completed')
         GROUP BY cc.course_code
        HAVING COUNT(*) = 1
       ) x ON UPPER(TRIM(x.course_code)) = UPPER(TRIM(c.course_code))
   SET em.course_offering_id = x.course_offering_id
 WHERE em.course_offering_id IS NULL;
