<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once "../db/connect.php";

// Check for student login
// Using 'student_id' or logging it for debugging if missing
$student_id = $_SESSION['student_id'] ?? $_SESSION['username'] ?? null;

// Allow a default for testing if in development and no session
if (!$student_id && $_SERVER['SERVER_NAME'] === 'localhost') {
    // Attempt to find a student if none logged in for demo purposes
    // $student_id = '212003'; 
}

$page_title = 'Final Results';

// Fetch distinct academic periods where the student has results
$history_rows = [];
if ($student_id) {
    // Only show published results? Assuming all in 'exams' are valid for now or check 'publish_results' table
    // For now, listing what is in exams table.
    $history_query = "SELECT DISTINCT Year, semester FROM exams WHERE Sid = ? ORDER BY Year DESC, semester DESC";
    if ($stmt = $db->prepare($history_query)) {
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) {
            $history_rows[] = $row;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Results - ITC Portal</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="css/admin-dashboard.css" rel="stylesheet">
    <link href="css/student.css" rel="stylesheet">

    <style>
        body { background-color: #f8f9fa; }
        .result-card {
            background: #fff;
            border-radius: 0.75rem;
            box-shadow: 0 2px 12px rgba(0,0,0,0.05);
            border: 1px solid #e2e8f0;
            margin-bottom: 2rem;
            overflow: hidden;
        }
        .result-card-header {
            background: #fff;
            padding: 1.5rem;
            border-bottom: 1px solid #f1f5f9;
        }
        .result-item {
            padding: 1.5rem;
            border-bottom: 1px solid #f8fafc;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: background-color 0.2s;
        }
        .result-item:hover {
            background-color: #f8f9fa;
        }
        .result-item:last-child {
            border-bottom: none;
        }
        .result-year {
            font-size: 1.25rem;
            font-weight: 700;
            color: #475569;
            margin-right: 1.5rem;
        }
        .result-info h6 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #1e293b;
        }
        .result-info p {
            margin: 0;
            color: #64748b;
            font-size: 0.9rem;
        }
        .btn-view {
            background-color: #e0e7ff;
            color: #4f46e5;
            font-weight: 600;
            border: none;
            padding: 0.5rem 1.25rem;
            border-radius: 0.5rem;
            transition: all 0.2s;
        }
        .btn-view:hover {
            background-color: #4f46e5;
            color: #fff;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>

    <!-- Include proper navigation based on your architecture -->
    <?php include "student_nav.php"; ?>

    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div>
                        <h2 class="mb-1 fw-bold text-dark">Academic Results</h2>
                        <p class="text-muted">View and print your statements of results</p>
                    </div>
                </div>
                
                <?php if (!$student_id): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i> You are not logged in. Please details.
                    </div>
                <?php endif; ?>

                <div class="result-card">
                    <div class="result-card-header">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-primary"></i>Results History</h5>
                    </div>
                    
                    <?php if (count($history_rows) > 0): ?>
                        <?php foreach($history_rows as $row): ?>
                        <!-- Result Item -->
                        <div class="result-item">
                            <div class="d-flex align-items-center">
                                <div class="result-year"><?php echo htmlspecialchars($row['Year']); ?></div>
                                <div class="result-info">
                                    <h6><?php echo ($row['semester'] == '2' ? 'Final' : 'Semester'); ?> Examinations</h6>
                                    <p>Semester <?php echo htmlspecialchars($row['semester']); ?></p>
                                </div>
                            </div>
                            <a href="print_result_statement.php?year=<?php echo urlencode((string)$row['Year']); ?>&sem=<?php echo urlencode((string)$row['semester']); ?>" class="btn btn-view">
                                <i class="fas fa-file-alt me-2"></i>View Statement
                            </a>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="p-5 text-center text-muted">
                            <i class="fas fa-inbox fa-3x mb-3 text-light-gray" style="color:#cbd5e1;"></i>
                            <p>No results found for your account.</p>
                            <?php if(!$student_id): ?><p class="small">Ensure you are logged in.</p><?php endif; ?>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
