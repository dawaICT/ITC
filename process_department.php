<?php
include "includes/lecturer.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $deptId = trim((string)($_POST['deptId'] ?? ''));
    $deptName = trim((string)($_POST['deptName'] ?? ''));
    $faculty = trim((string)($_POST['faculty'] ?? ''));

    if ($deptId === '' || $deptName === '') {
        echo "<script>
                alert('Department ID and name are required.');
                window.location.href = 'manage_departments.php';
              </script>";
        exit;
    }
    
    // Check if this is an edit operation
    if (isset($_POST['edit']) && $_POST['edit'] == 'true') {
        // Update existing department
        $stmt = $db->prepare("UPDATE departments SET department_name = ?, faculty = ? WHERE department_id = ?");
        $stmt->bind_param('sss', $deptName, $faculty, $deptId);
        $result = $stmt->execute();
        
        if ($result) {
            echo "<script>
                    alert('Department updated successfully!');
                    window.location.href = 'manage_departments.php';
                  </script>";
        } else {
            echo "<script>
                    alert('Error updating department.');
                    window.location.href = 'manage_departments.php';
                  </script>";
        }
        $stmt->close();
    } else {
        // Check if department ID already exists
        $check_stmt = $db->prepare("SELECT department_id FROM departments WHERE department_id = ? LIMIT 1");
        $check_stmt->bind_param('s', $deptId);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if (mysqli_num_rows($check_result) > 0) {
            echo "<script>
                    alert('Department ID already exists!');
                    window.location.href = 'manage_departments.php';
                  </script>";
        } else {
            // Insert new department
            $stmt = $db->prepare("INSERT INTO departments (department_id, department_name, faculty, status) VALUES (?, ?, ?, 'active')");
            $stmt->bind_param('sss', $deptId, $deptName, $faculty);
            $result = $stmt->execute();
            
            if ($result) {
                echo "<script>
                        alert('Department added successfully!');
                        window.location.href = 'manage_departments.php';
                      </script>";
            } else {
                echo "<script>
                        alert('Error adding department.');
                        window.location.href = 'manage_departments.php';
                      </script>";
            }
            $stmt->close();
        }
        $check_stmt->close();
    }
} else {
    header("Location: manage_departments.php");
}
?> 
