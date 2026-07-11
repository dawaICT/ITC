<?php
require "includes/nav.php";

?>

<div class="container-fluid px-4 py-4 portal-dashboard">
    <div class="row justify-content-center">
        <div class="col-12">
            <div class="card admin-card p-3">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h3 class="card-title mb-0">Admit Student</h3>
                    <div class="d-flex gap-2">
                        <a href="../index.php" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-house-door"></i> Home
                        </a>
                    </div>
                </div>

                <hr class="mb-4">

                <form role="form" method="POST" action="processAdmit_student.php" class="needs-validation" novalidate>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <div class="form-floating mb-3">
                                <input type="text" 
                                       class="form-control" 
                                       name="SID" 
                                       id="SID" 
                                       placeholder="Enter student ID"
                                       pattern="[0-9]+" 
                                       required>
                                <label for="SID">Student ID</label>
                                <div class="invalid-feedback">
                                    Please enter a valid student ID (numbers only).
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="bi bi-arrow-counterclockwise"></i> Clear
                                </button>
                                <button type="submit" class="btn btn-primary" name="search">
                                    <i class="bi bi-search"></i> Search Student
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require "includes/footer.php"; ?>

