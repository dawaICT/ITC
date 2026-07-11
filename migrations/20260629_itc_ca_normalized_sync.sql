-- ============================================================================
-- ITC Academic Data Structure - CA Normalized Sync
--
-- Adds zero-weight legacy CA component aliases used by the existing uploader
-- and backfills normalized student_assessment_marks/student_course_results from
-- semester_assessment where the offering-based registration bridge exists.
-- ============================================================================

INSERT INTO assessment_components
        (assessment_scheme_id, component_name, component_type, weight, max_mark, display_order)
SELECT s.id, 'Assignment 3', 'CA', 0, 100, 25
  FROM assessment_schemes s
  JOIN programs p ON p.program_code = s.program_code
 WHERE p.structure_type IN ('TERM_BASED','SEMESTER_BASED')
   AND NOT EXISTS (
       SELECT 1 FROM assessment_components ac
        WHERE ac.assessment_scheme_id = s.id AND ac.component_name = 'Assignment 3'
   );

INSERT INTO assessment_components
        (assessment_scheme_id, component_name, component_type, weight, max_mark, display_order)
SELECT s.id, 'Test 1', 'CA', 0, 100, 31
  FROM assessment_schemes s
  JOIN programs p ON p.program_code = s.program_code
 WHERE p.structure_type IN ('TERM_BASED','SEMESTER_BASED')
   AND NOT EXISTS (
       SELECT 1 FROM assessment_components ac
        WHERE ac.assessment_scheme_id = s.id AND ac.component_name = 'Test 1'
   );

INSERT INTO assessment_components
        (assessment_scheme_id, component_name, component_type, weight, max_mark, display_order)
SELECT s.id, 'Test 2', 'CA', 0, 100, 32
  FROM assessment_schemes s
  JOIN programs p ON p.program_code = s.program_code
 WHERE p.structure_type IN ('TERM_BASED','SEMESTER_BASED')
   AND NOT EXISTS (
       SELECT 1 FROM assessment_components ac
        WHERE ac.assessment_scheme_id = s.id AND ac.component_name = 'Test 2'
   );

INSERT INTO student_assessment_marks
        (student_course_registration_id, assessment_component_id, mark_obtained, max_mark, uploaded_by, status, uploaded_at)
SELECT scr.id, ac.id, x.mark_obtained, ac.max_mark, sa.posted_by, 'DRAFT', COALESCE(sa.updated_at, sa.created_at, CURRENT_TIMESTAMP)
  FROM (
        SELECT id, Sid, Course_Code, semester, Year, posted_by, updated_at, created_at, 'Assignment 1' AS component_name, A1 AS mark_obtained
          FROM semester_assessment WHERE A1 IS NOT NULL
        UNION ALL
        SELECT id, Sid, Course_Code, semester, Year, posted_by, updated_at, created_at, 'Assignment 2', A2
          FROM semester_assessment WHERE A2 IS NOT NULL
        UNION ALL
        SELECT id, Sid, Course_Code, semester, Year, posted_by, updated_at, created_at, 'Assignment 3', A3
          FROM semester_assessment WHERE A3 IS NOT NULL
        UNION ALL
        SELECT id, Sid, Course_Code, semester, Year, posted_by, updated_at, created_at, 'Test 1', T1
          FROM semester_assessment WHERE T1 IS NOT NULL
        UNION ALL
        SELECT id, Sid, Course_Code, semester, Year, posted_by, updated_at, created_at, 'Test 2', T2
          FROM semester_assessment WHERE T2 IS NOT NULL
        UNION ALL
        SELECT id, Sid, Course_Code, semester, Year, posted_by, updated_at, created_at, 'Final Exam', Exam
          FROM semester_assessment WHERE Exam IS NOT NULL
  ) x
  JOIN semester_assessment sa ON sa.id = x.id
  JOIN student_program sp ON sp.Sid = x.Sid
  JOIN programs p ON p.program_code = sp.program_code
  JOIN student_course_registrations scr ON scr.student_programme_id = sp.id
  JOIN course_offerings co ON co.id = scr.course_offering_id
  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id AND cc.course_code = x.Course_Code
  JOIN assessment_schemes sch ON sch.program_code = p.program_code AND sch.course_code = x.Course_Code AND sch.status = 'active'
  JOIN assessment_components ac ON ac.assessment_scheme_id = sch.id AND ac.component_name = x.component_name
 WHERE (
       (p.structure_type = 'TERM_BASED' AND cc.year_number = CAST(x.Year AS UNSIGNED) AND cc.term_number = CAST(x.semester AS UNSIGNED))
    OR (p.structure_type = 'SEMESTER_BASED' AND cc.year_number = CAST(x.Year AS UNSIGNED) AND cc.semester_number = CAST(x.semester AS UNSIGNED))
    OR (p.structure_type = 'TRADE_TEST_LEVEL' AND cc.level_number IS NOT NULL)
 )
ON DUPLICATE KEY UPDATE
       mark_obtained = VALUES(mark_obtained),
       max_mark = VALUES(max_mark),
       uploaded_by = VALUES(uploaded_by),
       uploaded_at = VALUES(uploaded_at),
       updated_at = CURRENT_TIMESTAMP;

INSERT INTO student_course_results
        (student_course_registration_id, ca_total, exam_mark, final_mark, grade, result_status)
SELECT scr.id,
       sa.Total_CA,
       sa.Exam,
       CASE
           WHEN sa.Exam IS NULL THEN sa.Total_CA
           WHEN sa.Total_CA IS NULL THEN sa.Exam
           ELSE ROUND((sa.Total_CA * 0.4) + (sa.Exam * 0.6), 2)
       END AS final_mark,
       CASE
           WHEN (
                CASE
                    WHEN sa.Exam IS NULL THEN sa.Total_CA
                    WHEN sa.Total_CA IS NULL THEN sa.Exam
                    ELSE ROUND((sa.Total_CA * 0.4) + (sa.Exam * 0.6), 2)
                END
           ) >= 80 THEN 'A'
           WHEN (
                CASE
                    WHEN sa.Exam IS NULL THEN sa.Total_CA
                    WHEN sa.Total_CA IS NULL THEN sa.Exam
                    ELSE ROUND((sa.Total_CA * 0.4) + (sa.Exam * 0.6), 2)
                END
           ) >= 70 THEN 'B'
           WHEN (
                CASE
                    WHEN sa.Exam IS NULL THEN sa.Total_CA
                    WHEN sa.Total_CA IS NULL THEN sa.Exam
                    ELSE ROUND((sa.Total_CA * 0.4) + (sa.Exam * 0.6), 2)
                END
           ) >= 60 THEN 'C'
           WHEN (
                CASE
                    WHEN sa.Exam IS NULL THEN sa.Total_CA
                    WHEN sa.Total_CA IS NULL THEN sa.Exam
                    ELSE ROUND((sa.Total_CA * 0.4) + (sa.Exam * 0.6), 2)
                END
           ) >= 50 THEN 'D'
           ELSE 'F'
       END,
       CASE
           WHEN sa.Total_CA IS NULL AND sa.Exam IS NULL THEN 'INCOMPLETE'
           WHEN (
                CASE
                    WHEN sa.Exam IS NULL THEN sa.Total_CA
                    WHEN sa.Total_CA IS NULL THEN sa.Exam
                    ELSE ROUND((sa.Total_CA * 0.4) + (sa.Exam * 0.6), 2)
                END
           ) >= 50 THEN 'PASS'
           ELSE 'FAIL'
       END
  FROM semester_assessment sa
  JOIN student_program sp ON sp.Sid = sa.Sid
  JOIN programs p ON p.program_code = sp.program_code
  JOIN student_course_registrations scr ON scr.student_programme_id = sp.id
  JOIN course_offerings co ON co.id = scr.course_offering_id
  JOIN curriculum_courses cc ON cc.id = co.curriculum_course_id AND cc.course_code = sa.Course_Code
 WHERE (
       (p.structure_type = 'TERM_BASED' AND cc.year_number = CAST(sa.Year AS UNSIGNED) AND cc.term_number = CAST(sa.semester AS UNSIGNED))
    OR (p.structure_type = 'SEMESTER_BASED' AND cc.year_number = CAST(sa.Year AS UNSIGNED) AND cc.semester_number = CAST(sa.semester AS UNSIGNED))
    OR (p.structure_type = 'TRADE_TEST_LEVEL' AND cc.level_number IS NOT NULL)
 )
ON DUPLICATE KEY UPDATE
       ca_total = VALUES(ca_total),
       exam_mark = VALUES(exam_mark),
       final_mark = VALUES(final_mark),
       grade = VALUES(grade),
       result_status = VALUES(result_status),
       updated_at = CURRENT_TIMESTAMP;
