-- Add 'internal' to el_live_sessions.platform for portal-hosted live rooms.
--
-- Phase 1 of system-generated live links (see INTERNAL_LIVE_LINKS_SCOPE.md): live
-- sessions can now use a portal-generated room embedded via Jitsi instead of an external
-- Google Meet/Zoom/Teams URL. 'internal' marks those rooms; the generated room name is
-- stored in the existing external_meeting_id column and the canonical room URL in join_url.
-- External providers remain available as options.
--
-- Run as wucportal_migrator (or another DDL-capable user).

ALTER TABLE el_live_sessions
  MODIFY COLUMN platform ENUM('internal','google_meet','zoom','teams') NOT NULL DEFAULT 'internal';
