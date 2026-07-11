<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Handle form submissions
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $program_code = $_POST['program_code'] ?? '';
        
        switch ($action) {
            case 'view':
                $message = "VIEW ACTION: Viewing program $program_code";
                break;
            case 'edit':
                $message = "EDIT ACTION: Editing program $program_code";
                break;
            case 'delete':
                $message = "DELETE ACTION: Deleting program $program_code";
                break;
            default:
                $message = "Unknown action: $action";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Button Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>Button Functionality Test</h1>
        
        <?php if ($message): ?>
        <div class="alert alert-info">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h5>Test Programs</h5>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>TEST-001</td>
                            <td>Test Program 1</td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="program_code" value="TEST-001">
                                        <button type="submit" class="btn btn-outline-primary" title="Edit Program">
                                            <i class="fas fa-edit"></i>
                                            <span class="d-none d-sm-inline ms-1">Edit</span>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="view">
                                        <input type="hidden" name="program_code" value="TEST-001">
                                        <button type="submit" class="btn btn-outline-info" title="View Details">
                                            <i class="fas fa-eye"></i>
                                            <span class="d-none d-sm-inline ms-1">View</span>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="program_code" value="TEST-001">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete Program">
                                            <i class="fas fa-trash-alt"></i>
                                            <span class="d-none d-sm-inline ms-1">Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <td>TEST-002</td>
                            <td>Test Program 2</td>
                            <td>
                                <div class="btn-group btn-group-sm" role="group">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="edit">
                                        <input type="hidden" name="program_code" value="TEST-002">
                                        <button type="submit" class="btn btn-outline-primary" title="Edit Program">
                                            <i class="fas fa-edit"></i>
                                            <span class="d-none d-sm-inline ms-1">Edit</span>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="view">
                                        <input type="hidden" name="program_code" value="TEST-002">
                                        <button type="submit" class="btn btn-outline-info" title="View Details">
                                            <i class="fas fa-eye"></i>
                                            <span class="d-none d-sm-inline ms-1">View</span>
                                        </button>
                                    </form>
                                    
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="program_code" value="TEST-002">
                                        <button type="submit" class="btn btn-outline-danger" title="Delete Program">
                                            <i class="fas fa-trash-alt"></i>
                                            <span class="d-none d-sm-inline ms-1">Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="mt-4">
            <h5>Instructions:</h5>
            <ol>
                <li>Click any button to test functionality</li>
                <li>You should see a message appear above the table</li>
                <li>For delete buttons, you should see a confirmation dialog</li>
                <li>If buttons work here, they should work in the main programs.php</li>
            </ol>
        </div>
        
        <div class="mt-4">
            <a href="programs.php" class="btn btn-primary">Back to Programs</a>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 