<?php
/**
 * DEPRECATED — do not run.
 *
 * This script migrated staff IDs to the old WUC### format. The portal now uses
 * ITC### via generateNextStaffId() in includes/id_helpers.php.
 *
 * To remove leftover WUC accounts, use:
 *   php scripts/remove_wuc_staff_ids.php --commit
 */

fwrite(STDERR, "This script is deprecated. Staff IDs use the ITC prefix.\n");
fwrite(STDERR, "Run scripts/remove_wuc_staff_ids.php instead.\n");
exit(1);
