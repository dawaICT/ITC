<?php

require "../db/connect.php";
error_reporting(0);
session_start();

    if (isset($_POST['submit'])) {
        $course_code = $_GET['course_code'];
        $Sid = $_GET['Sid'];
        $sql="DELETE FROM course_registration WHERE Sid='$Sid' AND course_code = '$course_code'";
	if ($results=$db->query($sql) === TRUE) {

        header("Location:exemptions.php");
	} 
}

?>