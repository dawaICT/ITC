
<!DOCTYPE html>
<html>
<meta name="viewport" content="width=device-width, initial-scale=1">		<meta charset="UTF-8">
		<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<link rel="stylesheet" type="text/css" href="css_main/admin.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
		<link rel="stylesheet" href="dist/css/bootstrap-theme.min.css">

<body>
	<div class="w3-container">
		<div class="row">
            <br>
            <div id="ca" class="tab-pane w3-animate-right">
            	<h4>Continous assessments series</h4>
					<?php
						if (isset($_GET['view'])) {
							$view = $_GET['view'];
							$number = 1;
								if($results = $db->query("SELECT * FROM exams WHERE SID = '$view'")) {
										if($count = $results->num_rows) {
										while($row = $results->fetch_object()){
										$records[] = $row;
										}
										$results->free();
									}
								}
							}

                    ?>
					<table class="table table-hover align-middle">
                        <thead class="table-light">
                          <tr>
                            <th>No.</th>
                              <th>Exam type</th>
                              <th>Year</th>
                              <th class="w3-center">Action</th>
                          </tr>
                        </thead>
                        <tbody>
                    <?php
						foreach($records as $r) {
					?>
					  <tr>
                                <td><?php echo $number++; ?>.</td>
                                <td><?php echo ($r->exam_type); ?></td>
                                <td><?php echo ($r->year); ?></td>
                                <td class="w3-center">
                                  <a class='btn w3-green' href="view_student_admin.php?view=<?php echo $r->SID?>"><span class="glyphicon glyphicon-download"> Transcript</span>
                                    </a>
                                </td> 
                              </tr>

					<?php	
						}  
					?>
				</tbody>
             </table>
             </div>
		</div>
	</div>
</body>

<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
