<?php
/**
 * Compatibility shim. The real, guarded "Registration Report" lives at
 * admin/semesterReg_stud.php. This root file used to be an incomplete stub
 * (it referenced $db without a connection, built a query it never ran, and
 * had no auth guard). Redirect any stray bookmark / legacy link there so it
 * lands on the working, access-controlled report instead of a blank page.
 */
header('Location: /wucportal/admin/semesterReg_stud.php', true, 301);
exit;
