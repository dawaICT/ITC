ALTER TABLE semester_registration
  MODIFY period_type ENUM('semester','term','short_course_cycle','trade_test_level') NOT NULL DEFAULT 'semester';
