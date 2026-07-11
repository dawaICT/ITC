-- Add 'google_meet' to el_live_sessions.platform.
--
-- admin/elearning/sessions.php creates live sessions with platform = 'google_meet',
-- but the column was ENUM('zoom','teams'). The server's sql_mode is not strict, so
-- the invalid value was silently coerced to '' on insert; the student join flow then
-- failed elearningAllowedMeetingHost('', ...) with "meeting URL is not valid", making
-- every live session unjoinable. Add google_meet (the portal's primary platform) and
-- default to it.
--
-- Run as wucportal_migrator (or another DDL-capable user).

ALTER TABLE el_live_sessions
  MODIFY COLUMN platform ENUM('google_meet','zoom','teams') NOT NULL DEFAULT 'google_meet';
