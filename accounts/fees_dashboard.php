<?php
// The Fees Management Dashboard has been merged into the single Accounts
// Dashboard (index.php) so the accounts module has one landing page instead of
// two. This stub preserves old links/bookmarks by redirecting there.
header('Location: index.php', true, 301);
exit;
