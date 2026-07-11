<?php

require "../db/connect.php";
error_reporting(0);

if(!empty($_POST)){
    if(isset($_POST["program_code"], $_POST["program_name"])) {
        $program_code = trim($_POST["program_code"]);
        $program_name = trim($_POST["program_name"]);

        $checkStmt = $db->prepare("SELECT program_code FROM programs WHERE program_code = ? AND program_name = ? LIMIT 1");
        $index = null;
        if ($checkStmt) {
            $checkStmt->bind_param("ss", $program_code, $program_name);
            $checkStmt->execute();
            $checkStmt->bind_result($index);
            $checkStmt->fetch();
            $checkStmt->close();
        }

        if (isset($index)) {
            echo "<script>alert('Failed! This program has already been added')</script>";
            echo "<script>window.open('programs.php','_self')</script>";
        } else if (!empty($program_code) && !empty($program_name)) {
            $insert = $db->prepare("INSERT INTO programs (program_code, program_name) VALUE(?,?)");
            $insert->bind_param("ss", $program_code, $program_name);

            if ($insert->execute()) {
                echo "<script>alert('New program added successfully')</script>";
                echo "<script>window.open('programs.php','_self')</script>";
            }
        } else {
            echo "<script>alert('Process failed!')</script>";
            echo "<script>window.open('programs.php','_self')</script>";
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
</head>   
<body>
    <div id="Class" class="w3-modal">
        <div class="w3-modal-content w3-animate-zoom w3-card-8">
            <header class="w3-container w3-purple"> 
                <span onclick="document.getElementById('Class').style.display='none'" 
                    class="w3-closebtn">&times;</span>
                <h3 class="w3-center">Add new program</h3>
            </header>
            <div class="w3-container">
                <form action="add_class.php" method="post" role="form">
                    <div class="form-group">
                        <label for="program_code">Program code:</label><br>
                        <input type="text" class="form-control" name="program_code" autofocus id="program_code" 
                            placeholder="Enter program code" autocomplete="off" required>
                    </div>
                    <div class="form-group">
                        <label for="program_name">Program name:</label><br>
                        <input type="text" class="form-control" name="program_name" autofocus id="program_name" 
                            placeholder="Enter program name" autocomplete="off" required>
                    </div>
                    <br><br>
                    <div class="form-group">
                        <button class="btn w3-orange btn-block" type="submit">Add</button>
                    </div>  
                </form>
            </div>
        </div>
    </div>
    <script src="assets/vendor/sweetalert2/dist/sweetalert2.min.js"></script>
</body>
</html>