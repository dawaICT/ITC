<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modal Test</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container mt-5">
        <h1>Modal Test Page</h1>
        
        <div class="row">
            <div class="col-md-12">
                <h3>Test Buttons</h3>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#testModal">
                    <i class="fas fa-plus me-2"></i>Test Modal
                </button>
                
                <button class="btn btn-success" onclick="testBootstrap()">
                    Test Bootstrap
                </button>
            </div>
        </div>
        
        <div class="row mt-4">
            <div class="col-md-12">
                <h3>Test Table</h3>
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
                            <td>Test Program</td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary edit-test-btn" 
                                        data-program='{"code":"TEST-001","name":"Test Program"}'
                                        data-bs-toggle="modal" data-bs-target="#editModal">
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-sm btn-outline-info view-test-btn"
                                        data-program='{"code":"TEST-001","name":"Test Program"}'
                                        data-bs-toggle="modal" data-bs-target="#viewModal">
                                    <i class="fas fa-eye"></i> View
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Test Modal -->
    <div class="modal fade" id="testModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Test Modal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>This is a test modal to verify Bootstrap is working.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Edit modal content will go here.</p>
                    <p id="edit-data"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary">Save</button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Modal -->
    <div class="modal fade" id="viewModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">View Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>View modal content will go here.</p>
                    <p id="view-data"></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('Page loaded');
            
            // Test Bootstrap
            function testBootstrap() {
                if (typeof bootstrap !== 'undefined') {
                    console.log('✓ Bootstrap is loaded');
                    alert('Bootstrap is working!');
                } else {
                    console.error('✗ Bootstrap is not loaded');
                    alert('Bootstrap is not loaded!');
                }
            }
            
            // Make testBootstrap global
            window.testBootstrap = testBootstrap;
            
            // Test edit buttons
            const editButtons = document.querySelectorAll('.edit-test-btn');
            editButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const data = this.getAttribute('data-program');
                    console.log('Edit button clicked, data:', data);
                    
                    try {
                        const program = JSON.parse(data);
                        document.getElementById('edit-data').textContent = 
                            `Editing: ${program.code} - ${program.name}`;
                    } catch (error) {
                        console.error('Error parsing data:', error);
                    }
                });
            });
            
            // Test view buttons
            const viewButtons = document.querySelectorAll('.view-test-btn');
            viewButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const data = this.getAttribute('data-program');
                    console.log('View button clicked, data:', data);
                    
                    try {
                        const program = JSON.parse(data);
                        document.getElementById('view-data').textContent = 
                            `Viewing: ${program.code} - ${program.name}`;
                    } catch (error) {
                        console.error('Error parsing data:', error);
                    }
                });
            });
            
            console.log('✓ Event listeners attached');
        });
    </script>
</body>
</html> 