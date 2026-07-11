<?php
require "includes/nav.php";

// Escape + default helper for displayed values.
if (!function_exists('disp')) {
    function disp($value, $default = 'N/A') {
        $value = $value ?? '';
        return $value === '' ? $default : htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}
?>
<div class="container-fluid px-4 py-4 portal-dashboard">
    <div class="dashboard-header admin-section mb-3">
        <h3 class="dashboard-title mb-0"><i class="fas fa-id-card me-2"></i>Student Details</h3>
    </div>
    <div class="data-table-card">
        <div class="card-header">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-id-card me-2"></i>Student Details</h5>
            </div>
        </div>
        <div class="card-body">
            <?php
            $records_1 = [];

            // Accept both link styles in use across the portal:
            //   ?id=<opaque token|raw SID>  (students.php, search_student.php, regOldStud.php …)
            //   ?view=<raw SID>             (recent_students.php, editStudent.php …)
            $rawId = '';
            if (isset($_GET['id']) && trim($_GET['id']) !== '') {
                $rawId = trim($_GET['id']);
            } elseif (isset($_GET['view']) && trim($_GET['view']) !== '') {
                $rawId = trim($_GET['view']);
            }

            if ($rawId !== '') {
                // Prefer the opaque, tamper-proof token (wuc_encode_id). Fall back
                // to a raw SID so older inbound links keep working during migration.
                $decoded = function_exists('wuc_decode_id') ? wuc_decode_id($rawId, 'student') : null;
                $view = $decoded !== null ? $decoded : $rawId;

                // Use prepared statement to prevent SQL injection
                $query = "SELECT s.*, sp.*, p.program_name 
                          FROM students s 
                          INNER JOIN student_program sp ON s.SID = sp.Sid 
                          INNER JOIN programs p ON sp.program_code = p.program_code 
                          WHERE s.SID = ?";
                
                $stmt = $db->prepare($query);
                if ($stmt) {
                    $stmt->bind_param('s', $view);
                    if ($stmt->execute()) {
                        $results = $stmt->get_result();
                        while ($row = $results->fetch_object()) {
                            $records_1[] = $row;
                        }
                    } else {
                        error_log('view_student: query failed: ' . $stmt->error);
                    }
                    $stmt->close();
                } else {
                    error_log('view_student: prepare failed: ' . $db->error);
                }
            }

                    ?>

                    <?php
						if (empty($records_1)) {
							echo '<div class="alert alert-warning" role="alert"><i class="fas fa-exclamation-triangle me-2"></i>No student record found. Please check the Student ID and try again.</div>';
						} else {
							foreach($records_1 as $r) {
								$sexLabel = ['M'=>'Male','m'=>'Male','F'=>'Female','f'=>'Female'][$r->sex ?? ''] ?? ($r->sex ?: 'N/A');
						?>
					<div class="d-flex align-items-center gap-4 mb-4">
						<img src="<?php echo !empty($r->profile_image) ? '../uploads/profile/' . disp($r->profile_image, '') : '/wucportal/images/avatar.png'; ?>" alt="Profile photo" width="110" height="120" class="rounded border" onerror="this.onerror=null;this.src='/wucportal/images/avatar.png'">
						<div>
							<h5 class="mb-1"><?php echo disp($r->Fname, ''); ?> <?php echo disp($r->Lname, ''); ?></h5>
							<div class="text-muted">SID: <?php echo disp($r->SID); ?></div>
						</div>
					</div>
					<table class="table table-hover align-middle">
						<tbody>
							<tr><th style="width:30%">Gender</th><td><?php echo disp($sexLabel); ?></td></tr>
							<tr><th>Country</th><td><?php echo disp($r->country); ?></td></tr>
							<tr><th>NRC/Passport</th><td><?php echo disp($r->nrc_pass); ?></td></tr>
							<tr><th>Date of Birth</th><td><?php echo disp($r->dob); ?></td></tr>
							<tr><th>Mobile</th><td><?php echo disp($r->mobile); ?></td></tr>
							<tr><th>Status</th><td><?php echo disp($r->status); ?></td></tr>
							<tr><th>Email</th><td><?php echo disp($r->email); ?></td></tr>
							<tr><th>Home address</th><td><?php echo disp($r->h_addre); ?></td></tr>
							<tr><th>Next of Kin</th><td><?php echo disp($r->next_kin); ?></td></tr>
							<tr><th>Next of Kin Contact</th><td><?php echo disp($r->next_kin_mobile); ?></td></tr>
							<tr><th>Relationship</th><td><?php echo disp($r->relat); ?></td></tr>
							<tr><th>Program of study</th><td><?php echo disp($r->program_name); ?></td></tr>
							<tr><th>Intake</th><td><?php echo disp($r->intake); ?></td></tr>
							<tr><th>Year of study commencement</th><td><?php echo disp($r->startYear); ?></td></tr>
							<tr><th>Year of study completion</th><td><?php echo disp($r->endYear); ?></td></tr>
							<tr><th>School attended</th><td><?php echo disp($r->school); ?></td></tr>
						<tr>
							<th>NRC/Passport Document</th>
							<td>
								<?php if (!empty($r->nrc_file)): ?>
									<a href="view_document.php?type=nrc&file=<?php echo urlencode($r->nrc_file); ?>" target="_blank" class="btn btn-sm btn-warning">
										<i class="fas fa-eye me-1"></i> View
									</a>
								<?php else: ?>
									<span class="text-muted">Not uploaded</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th>Results</th>
							<td>
								<?php if (!empty($r->results)): ?>
									<a href="view_document.php?type=results&file=<?php echo urlencode($r->results); ?>" target="_blank" class="btn btn-sm btn-success">
										<i class="fas fa-eye me-1"></i> View
									</a>
								<?php else: ?>
									<span class="text-muted">Not uploaded</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th>Profile Photo</th>
							<td>
								<?php if (!empty($r->profile_image) && $r->profile_image !== 'default.jpg'): ?>
									<a href="view_document.php?type=profile&file=<?php echo urlencode($r->profile_image); ?>" target="_blank" class="btn btn-sm btn-info">
										<i class="fas fa-eye me-1"></i> View Full Size
									</a>
								<?php else: ?>
									<span class="text-muted">Using default</span>
								<?php endif; ?>
							</td>
						</tr>
						</tbody>
                      </table>
					<div class="d-flex justify-content-end">
						<button onclick="history.back()" class="btn btn-primary">Back</button>
					</div>
					<?php	
						}  
					}
					?>
				</div>
		</div>
		</div>
<?php require "includes/footer.php"; ?>
