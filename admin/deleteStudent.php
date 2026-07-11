
<?php
/**
 * Legacy wrapper for delete_student.php
 */
if (isset($_GET['del'])) {
    header('Location: delete_student.php?sid=' . urlencode($_GET['del']));
    exit();
}
header('Location: students_by_admin.php');
exit();
