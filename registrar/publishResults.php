<?php
require_once "includes/admin.php";
error_reporting(0);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!function_exists('registrar_publish_alert_redirect')) {
    function registrar_publish_alert_redirect(string $message): void {
        echo "<script>alert(" . json_encode($message) . ");window.open('exams.php','_self');</script>";
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST["status"], $_POST["semester"], $_POST["year"], $_POST["dte_publish"])) {
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        registrar_publish_alert_redirect('Security validation failed. Please refresh the page and try again.');
    }

    $status = trim((string)$_POST["status"]);
    $semester = trim((string)$_POST["semester"]);
    $year = trim((string)$_POST["year"]);
    $dte_publish = trim((string)$_POST["dte_publish"]);

    if (!in_array($status, ['0', '1'], true) || !in_array($semester, ['1', '2'], true) || !ctype_digit($year) || $dte_publish === '') {
        registrar_publish_alert_redirect('Please provide valid publishing details.');
    }

    $sql = "INSERT INTO publish_results (status, semester, year, dte_publish, dte)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), dte_publish = VALUES(dte_publish), dte = NOW()";
    $insert = $db->prepare($sql);
    if ($insert) {
        $statusInt = (int)$status;
        $semesterInt = (int)$semester;
        $insert->bind_param("iiss", $statusInt, $semesterInt, $year, $dte_publish);
        if ($insert->execute()) {
            registrar_publish_alert_redirect('Final exam results publishing date set successfully.');
        }
    }

    registrar_publish_alert_redirect('Something went wrong while setting the final results publishing date.');
}
?>
<div id="PublishExams" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
        <header class="w3-container w3-purple">
            <span onclick="document.getElementById('PublishExams').style.display='none'" class="w3-closebtn">x</span>
            <h3 class="w3-center">Publish Final Exam Results</h3>
        </header>
        <div class="w3-container">
            <form action="publishResults.php" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="status">Status:</label><br>
                    <select class="form-control" name="status" id="status" required>
                        <option value="" disabled selected>Select</option>
                        <option value="1">Yes</option>
                        <option value="0">No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="semester">Exam Semester:</label><br>
                    <select class="form-control" name="semester" id="semester" required>
                        <option value="" disabled selected>Select</option>
                        <option value="1">1</option>
                        <option value="2">2</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="Year">Session Year:</label>
                    <select class="w3-input w3-border col-xs-3 form-control" id="Year" name="year" required>
                        <option value="" disabled selected>Select year</option>
                        <?php for ($yearOption = (int)date('Y'); $yearOption >= 2000; $yearOption--): ?>
                            <option value="<?php echo $yearOption; ?>"><?php echo $yearOption; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="dte_publish">Set Date:</label><br>
                    <input type="date" class="form-control" name="dte_publish" autofocus id="dte_publish"
                           placeholder="Enter date for the results to be published" autocomplete="off" required>
                </div>
                <br>
                <div class="form-group">
                    <button class="btn btn-block w3-orange" type="submit" name="submit">SUBMIT</button>
                </div>
            </form>
        </div>
    </div>
</div>
