<?php
$page_title = "500 - Server Error";
require_once "../includes/header.php";
?>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            <div class="error-template">
                <h1>Oops!</h1>
                <h2>500 Server Error</h2>
                <div class="error-details mb-4">
                    Sorry, something went wrong on our servers.
                </div>
                <div class="error-actions">
                    <a href="/" class="btn btn-primary">
                        <i class="fas fa-home me-2"></i>Back to Home
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.error-template {
    padding: 40px 15px;
    text-align: center;
}
.error-template h1 {
    font-size: 80px;
    color: #dc3545;
}
.error-template h2 {
    font-size: 30px;
    color: #6c757d;
}
.error-details {
    font-size: 18px;
    color: #6c757d;
}
</style>

<?php require_once "../includes/footer.php"; ?> 