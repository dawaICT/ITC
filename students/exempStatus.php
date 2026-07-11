<?php

error_reporting(0);

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Exemptions Status</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php require_once __DIR__ . '/../includes/page_meta.php'; wuc_portal_favicon_links(); ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">

<link rel="stylesheet" href="/wucportal/css/admin-style.css">
<link rel="stylesheet" href="/wucportal/css/portal-dashboard.css">
</head>
<body class="bg-light">
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>
    <div class="content-wrapper">
    <div class="container">
		<div class="row">
		<h5>Academics | Exemptions</h5>
			<hr>
			<div class="w3-container"><br>
				<?php
				$number = 1;
				$records = [];
				$sessionSid = (string)($_SESSION['Sid'] ?? '');
				if ($sessionSid !== '' && ($stmt = $db->prepare("SELECT * FROM exemption WHERE Sid = ?"))) {
					$stmt->bind_param('s', $sessionSid);
					$stmt->execute();
					$results = $stmt->get_result();
					while ($row = $results->fetch_object()) {
						$records[] = $row;
					}
					$stmt->close();
				}
				if (empty($records)) {
					echo '<h4 class="alert alert-danger">No records found.</h4><br>';
				}
				?>

					<?php
					if (isset($_SESSION['successWithdraw'])){

						echo '<h4 class="alert alert-success">'. $_SESSION['successWithdraw'].'</h4>';
						// unset the session variable
						unset($_SESSION['successWithdraw']); 
					}
					?>
					<?php
					if (isset($_SESSION['errorWithdraw'])){

						echo '<h4 class="alert alert-danger">'. $_SESSION['errorWithdraw'].'</h4>';
						// unset the session variable
						unset($_SESSION['errorWithdraw']); 
					}
					?>

					<table class="table table-hover align-middle">
						<tr>
							<th>#</th>
                            <th>Course code</th>
                            <th>Supporting document</th>
                            <th>Status</th>
                            <th class="w3-center">Application</th>
						</tr>
						<?php
						foreach($records as $r) {
						?>
						<tr>
                            <td><?php echo $number++; ?>.</td>
                            <td><?php echo ($r->course_code); ?></td>
                            <td>
                            <a href="uploads/<?php echo $r->support_doc?>" 
                            target="_blank" class="w3-btn w3-orange w3-round"> view
                            </a>
                            </td>
                            <td>
                                <?php
                                if ($r->status == 1) {
                                    echo '<button class="w3-btn w3-round w3-green">'."Approved".'</button>';
                                    } 
                                    elseif ($r->status == 2){
                                        echo '<button class="w3-btn w3-round w3-red">'."Declined".'</button>';

                                    } else {

                                    echo '<button class="w3-btn w3-round w3-orange">'."Pending approval".'</button>';
                                    } 
                                ?>
                            </td>
                            <td class="w3-center">
                                <a class='btn w3-red' href="withdrawExemption.php?del=<?php echo $r->CoRegID?>">
                                <span class="glyphicon glyphicon-remove"></span> Withdraw
                                </a>
                            </td>
						</tr>
					</div>
					<?php 
					}  
					?>

					</table>
			</div>
		</div>
    </div>
    </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

