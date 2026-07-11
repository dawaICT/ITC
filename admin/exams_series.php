<?php
/**
 * Modernized Exams Series Component - Results View
 * Consistent with Admin Dashboard Styling
 */
$records_1 = [];

if (isset($_GET['view'])) {
    $sid = $db->real_escape_string($_GET['view']);
    
    // Updated SQL to join with courses table to get the course name
    // Exam_marks is mapped to Exam_Result for consistency
    $sql_1 = "SELECT e.id, e.Course_Code, c.course_name as Course_Name, e.Exam_marks as Exam_Result, e.semester, e.Year 
              FROM exams e 
              LEFT JOIN courses c ON e.Course_Code = c.course_code 
              WHERE e.Sid = '$sid' 
              ORDER BY e.Year DESC, e.semester DESC";
              
    $result_1 = $db->query($sql_1);
    
    if ($result_1) {
        while ($row = $result_1->fetch_object()) {
            $records_1[] = $row;
        }
    }
}
?>

<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr>
                <th class="ps-4">Course Code</th>
                <th>Course Name</th>
                <th class="text-center">Grade</th>
                <th class="text-center">Period</th>
                <th class="pe-4 text-end">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($records_1)): ?>
                <tr>
                    <td colspan="5" class="text-center py-5 text-muted">
                        <div class="mb-2"><i class="fas fa-folder-open fa-2x opacity-25"></i></div>
                        No detailed academic results found for this student.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($records_1 as $res): ?>
                <tr>
                    <td class="ps-4">
                        <span class="fw-bold text-primary"><?php echo htmlspecialchars($res->Course_Code); ?></span>
                    </td>
                    <td>
                        <div class="fw-medium text-dark"><?php echo htmlspecialchars($res->Course_Name ?? 'Unknown Course'); ?></div>
                    </td>
                    <td class="text-center">
                        <?php 
                            $grade_class = 'bg-secondary';
                            $score = (float)$res->Exam_Result;
                            if ($score >= 75) $grade_class = 'bg-success';
                            elseif ($score >= 50) $grade_class = 'bg-primary';
                            elseif ($score >= 40) $grade_class = 'bg-warning text-dark';
                            else $grade_class = 'bg-danger';
                        ?>
                        <span class="badge <?php echo $grade_class; ?> rounded-pill px-3 shadow-sm" style="min-width: 45px;">
                            <?php echo htmlspecialchars($res->Exam_Result); ?>
                        </span>
                    </td>
                    <td class="text-center text-nowrap">
                        <span class="badge bg-light text-muted border fw-normal">Y<?php echo htmlspecialchars($res->Year); ?> S<?php echo htmlspecialchars($res->semester); ?></span>
                    </td>
                    <td class="pe-4 text-end">
                        <div class="btn-group">
                            <a href="edit_grade.php?id=<?php echo $res->id; ?>" class="btn btn-sm btn-outline-primary" title="Edit Result">
                                <i class="fas fa-pencil-alt"></i>
                            </a>
                            <a href="delete_grade.php?id=<?php echo $res->id; ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Permanently delete this academic record?')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

