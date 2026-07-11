-- ============================================================================
-- ITC Academic Data Structure - eLearning Activity Offering Bridge
--
-- Extends course_offering_id coverage beyond course materials to quizzes,
-- assignments, forums, live sessions, recordings, notifications, analytics,
-- and progress records. Existing legacy course_code behavior is preserved.
-- ============================================================================

ALTER TABLE el_quizzes
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_el_quizzes_offering (course_offering_id);

ALTER TABLE el_assignments
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_el_assignments_offering (course_offering_id);

ALTER TABLE el_forum_threads
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_el_forum_threads_offering (course_offering_id);

ALTER TABLE el_live_sessions
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_el_live_sessions_offering (course_offering_id);

ALTER TABLE el_recorded_videos
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_el_recorded_videos_offering (course_offering_id);

ALTER TABLE el_student_notifications
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_el_student_notifications_offering (course_offering_id);

ALTER TABLE el_analytics_events
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_el_analytics_events_offering (course_offering_id);

ALTER TABLE el_course_progress
    ADD COLUMN IF NOT EXISTS course_offering_id INT NULL AFTER id,
    ADD INDEX IF NOT EXISTS idx_el_course_progress_offering (course_offering_id);

UPDATE el_quizzes q
  JOIN el_course_modules m ON m.id = q.module_id
   SET q.course_offering_id = m.course_offering_id
 WHERE q.course_offering_id IS NULL
   AND m.course_offering_id IS NOT NULL;

UPDATE el_assignments a
  JOIN el_course_modules m ON m.id = a.module_id
   SET a.course_offering_id = m.course_offering_id
 WHERE a.course_offering_id IS NULL
   AND m.course_offering_id IS NOT NULL;

UPDATE el_forum_threads t
  JOIN el_course_modules m ON m.id = t.module_id
   SET t.course_offering_id = m.course_offering_id
 WHERE t.course_offering_id IS NULL
   AND m.course_offering_id IS NOT NULL;

UPDATE el_analytics_events e
  JOIN el_course_modules m ON m.id = e.module_id
   SET e.course_offering_id = m.course_offering_id
 WHERE e.course_offering_id IS NULL
   AND m.course_offering_id IS NOT NULL;

UPDATE el_analytics_events e
  JOIN el_contents c ON c.id = e.content_id
  JOIN el_course_modules m ON m.id = c.module_id
   SET e.course_offering_id = m.course_offering_id
 WHERE e.course_offering_id IS NULL
   AND m.course_offering_id IS NOT NULL;

UPDATE el_student_notifications n
  JOIN el_live_sessions s ON s.id = n.session_id
   SET n.course_offering_id = s.course_offering_id
 WHERE n.course_offering_id IS NULL
   AND s.course_offering_id IS NOT NULL;

UPDATE el_live_sessions s
  JOIN (
        SELECT cc.course_code, MIN(co.id) AS course_offering_id
          FROM course_offerings co
          JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
         WHERE co.status IN ('planned','active','completed')
         GROUP BY cc.course_code
        HAVING COUNT(*) = 1
       ) x ON UPPER(TRIM(x.course_code)) = UPPER(TRIM(s.course_code))
   SET s.course_offering_id = x.course_offering_id
 WHERE s.course_offering_id IS NULL;

UPDATE el_student_notifications n
  JOIN el_live_sessions s ON s.id = n.session_id
   SET n.course_offering_id = s.course_offering_id
 WHERE n.course_offering_id IS NULL
   AND s.course_offering_id IS NOT NULL;

UPDATE el_recorded_videos r
  JOIN (
        SELECT cc.course_code, MIN(co.id) AS course_offering_id
          FROM course_offerings co
          JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
         WHERE co.status IN ('planned','active','completed')
         GROUP BY cc.course_code
        HAVING COUNT(*) = 1
       ) x ON UPPER(TRIM(x.course_code)) = UPPER(TRIM(r.course_code))
   SET r.course_offering_id = x.course_offering_id
 WHERE r.course_offering_id IS NULL;

UPDATE el_student_notifications n
  JOIN (
        SELECT cc.course_code, MIN(co.id) AS course_offering_id
          FROM course_offerings co
          JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
         WHERE co.status IN ('planned','active','completed')
         GROUP BY cc.course_code
        HAVING COUNT(*) = 1
       ) x ON UPPER(TRIM(x.course_code)) = UPPER(TRIM(n.course_code))
   SET n.course_offering_id = x.course_offering_id
 WHERE n.course_offering_id IS NULL;

UPDATE el_analytics_events e
  JOIN (
        SELECT cc.course_code, MIN(co.id) AS course_offering_id
          FROM course_offerings co
          JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
         WHERE co.status IN ('planned','active','completed')
         GROUP BY cc.course_code
        HAVING COUNT(*) = 1
       ) x ON UPPER(TRIM(x.course_code)) = UPPER(TRIM(e.course_code))
   SET e.course_offering_id = x.course_offering_id
 WHERE e.course_offering_id IS NULL;

UPDATE el_course_progress p
  JOIN student_program sp ON sp.Sid = p.Sid
  JOIN student_course_registrations scr ON scr.student_programme_id = sp.id
  JOIN course_offerings co ON co.id = scr.course_offering_id
  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id
   SET p.course_offering_id = co.id
 WHERE p.course_offering_id IS NULL
   AND UPPER(TRIM(cc.course_code)) = UPPER(TRIM(p.course_code))
   AND scr.registration_status IN ('REGISTERED','COMPLETED','REPEATING')
   AND co.status IN ('planned','active','completed');
