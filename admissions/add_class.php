<?php
require "../db/connect.php";

// Environment-controlled error reporting
$WUC_ENV = getenv('WUC_ENV') ?: (defined('WUC_ENV') ? WUC_ENV : 'production');
if ($WUC_ENV === 'development' || isset($_GET['debug'])) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

if (!empty($_POST)) {
    if (isset($_POST["program_code"], $_POST["program_name"])) {
        $program_code = trim($_POST["program_code"]);
        $program_name = trim($_POST["program_name"]);

        // Use prepared statement to prevent SQL injection
        $check_stmt = $db->prepare("SELECT program_code FROM programs WHERE program_code = ? OR program_name = ?");
        $index = null;
        if ($check_stmt) {
            $check_stmt->bind_param('ss', $program_code, $program_name);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            if ($check_result->num_rows > 0) {
                $check_rs = $check_result->fetch_assoc();
                $index = $check_rs['program_code'];
            }
            $check_stmt->close();
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