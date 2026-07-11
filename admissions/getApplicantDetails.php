<?php
require_once "includes/nav.php";

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('<div class="alert alert-danger">Invalid applicant ID</div>');
}

$id = (int)$_GET['id'];
$query = "SELECT * FROM processed_applicants WHERE id = ?";
$stmt = $db->prepare($query);
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_object()):
?>
<div class="row">
    <div class="col-md-6">
        <h6>Personal Information</h6>
        <table class="table table-hover align-middle">
            <tr><th>Name:</th><td><?= htmlspecialchars($row->title . ' ' . $row->Fname . ' ' . $row->Lname) ?></td></tr>
            <tr><th>Gender:</th><td><?= htmlspecialchars($row->sex) ?></td></tr>
            <tr><th>Date of Birth:</th><td><?= htmlspecialchars($row->dob) ?></td></tr>
            <tr><th>NRC/Passport:</th><td><?= htmlspecialchars($row->nrc_pass) ?></td></tr>
            <tr><th>Country:</th><td><?= htmlspecialchars($row->country) ?></td></tr>
            <tr><th>Application Status:</th><td><?= htmlspecialchars($row->status) ?></td></tr>
        </table>
    </div>
    <div class="col-md-6">
        <h6>Contact Information</h6>
        <table class="table table-hover align-middle">
            <tr><th>Email:</th><td><?= htmlspecialchars($row->email) ?></td></tr>
            <tr><th>Mobile:</th><td><?= htmlspecialchars($row->mobile) ?></td></tr>
            <tr><th>Home Address:</th><td><?= htmlspecialchars($row->h_addre) ?></td></tr>
            <tr><th>Postal Address:</th><td><?= htmlspecialchars($row->p_addre) ?></td></tr>
        </table>
    </div>
</div>
<div class="row mt-3">
    <div class="col-md-6">
        <h6>Additional Information</h6>
        <table class="table table-hover align-middle">
            <tr><th>Sponsor:</th><td><?= htmlspecialchars($row->sponsor ?? 'N/A') ?></td></tr>
            <tr><th>Next of Kin:</th><td><?= htmlspecialchars($row->next_kin ?? 'N/A') ?></td></tr>
            <tr><th>Next of Kin Mobile:</th><td><?= htmlspecialchars($row->next_kin_mobile ?? 'N/A') ?></td></tr>
            <tr><th>Relationship:</th><td><?= htmlspecialchars($row->relat ?? 'N/A') ?></td></tr>
        </table>
    </div>
    <div class="col-md-6">
        <h6>Program Details</h6>
        <table class="table table-hover align-middle">
            <tr><th>Program:</th><td><?= htmlspecialchars($row->program) ?></td></tr>
            <tr><th>Study Mode:</th><td><?= htmlspecialchars($row->mode) ?></td></tr>
            <tr><th>Intake:</th><td><?= htmlspecialchars($row->intake) ?></td></tr>
            <tr><th>Year:</th><td><?= htmlspecialchars($row->year) ?></td></tr>
            <tr><th>Application Date:</th><td><?= htmlspecialchars($row->dte_adm) ?></td></tr>
        </table>
    </div>
</div>
<?php else: ?>
<div class="alert alert-warning">Applicant not found.</div>
<?php endif; ?>