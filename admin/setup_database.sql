-- RETIRED: do not use this file to initialize WUC Portal.
--
-- The former script dropped/recreated finance tables as MyISAM and declared
-- student identifiers as BIGINT, which corrupts current alphanumeric IDs.
-- Schema changes are now versioned under /migrations and must be run explicitly.
-- This harmless statement is retained so old deployment instructions fail
-- closed without deleting or rewriting portal data.

SELECT 'RETIRED: use the versioned migrations directory; no schema changes were made.' AS migration_status;

