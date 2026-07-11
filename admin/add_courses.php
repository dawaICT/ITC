<?php
// Enable error reporting during development (disable in production)
ini_set('display_errors', '0');
error_reporting(E_ALL);

require "../db/connect.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST["course_code"], $_POST["course_name"])) {
        $course_code = trim($_POST["course_code"]);
        $course_name = trim($_POST["course_name"]);

        // Use a prepared statement to check for duplicates
        $stmt = $db->prepare("SELECT course_code FROM courses WHERE course_code = ? AND course_name = ?");
        if (!$stmt) {
            die("Database error: " . $db->error);
        }
        $stmt->bind_param("ss", $course_code, $course_name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $result->num_rows > 0) {
            // Course already exists
            echo "<script>alert('Failed! This course has already been added');</script>";
            echo "<script>window.location.href='courses.php';</script>";
            exit;
        }
        $stmt->close();

        // Ensure fields are not empty before inserting
        if (!empty($course_code) && !empty($course_name)) {
            $insert = $db->prepare("INSERT INTO courses (course_code, course_name) VALUES (?, ?)");
            if (!$insert) {
                die("Database error: " . $db->error);
            }
            $insert->bind_param("ss", $course_code, $course_name);
            if ($insert->execute()) {
                echo "<script>alert('New course added successfully');</script>";
                echo "<script>window.location.href='courses.php';</script>";
                exit;
            } else {
                echo "<script>alert('Process failed!');</script>";
                echo "<script>window.location.href='courses.php';</script>";
                exit;
            }
        } else {
            echo "<script>alert('Please fill in all required fields!');</script>";
            echo "<script>window.location.href='courses.php';</script>";
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en-us">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Add Course</title>
  <link rel="stylesheet" type="text/css" href="w3/w3.css">
  <link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
  <link rel="stylesheet" type="text/css" href="dist/css/bootstrap-theme.min.css">
  <link rel="stylesheet" type="text/css" href="assets/css/font-awesome.css">
</head>
<body>
  <div id="Course" class="w3-modal" style="display:block">
    <div class="w3-modal-content w3-animate-zoom w3-card-8" style="max-width:500px;margin:60px auto">
      <header class="w3-container w3-purple">
        <a href="courses.php" class="w3-closebtn w3-button w3-hover-red w3-display-topright">&times;</a>
        <h3 class="w3-center">Add new course</h3>
      </header>
      <div class="w3-container w3-padding">
        <form action="add_courses.php" method="post" role="form">
          <div class="form-group">
            <label for="course_code">Course code:</label>
            <input type="text" class="form-control" name="course_code" id="course_code"
                   placeholder="e.g. CS101" autocomplete="off" required autofocus>
          </div>
          <div class="form-group">
            <label for="course_name">Course name:</label>
            <input type="text" class="form-control" name="course_name" id="course_name"
                   placeholder="e.g. Introduction to Computing" autocomplete="off" required>
          </div>
          <div class="form-group">
            <button class="btn btn-block" style="background-color:#9b59b6;color:#fff" type="submit">
              <span class="glyphicon glyphicon-plus"></span> Add Course
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
