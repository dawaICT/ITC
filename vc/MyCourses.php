<?php

$page_title = 'My Courses';
include "student_nav.php";
session_start();

?>

<!DOCTYPE html>
<html>
	<head>
		<title>My Courses - ITC</title>
		<link rel="stylesheet" type="text/css" href="w3/w3.css">
		<link rel="stylesheet" type="text/css" href="css_main/student.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap.min.css">
		<link rel="stylesheet" type="text/css" href="dist/css/bootstrap-theme.min.css">

	<body>
		<div class="container">
			<div class="row">
				<h4>My Courses: BCOM211</h4>
				<br>
			        <ul id="tab" class="nav nav-tabs">
			        	<li class="active"><a data-toggle="tab" href="#activities"><strong>Activities</strong></a></li>
	                    <li><a data-toggle="tab" href="#content"><strong>Course content</strong></a></li>
	                    <li><a data-toggle="tab" href="#notes"><strong>Lesson notes</strong></a></li>
	                    <li><a data-toggle="tab" href="#books"><strong>Books</strong></a></li>
	                    <li><a data-toggle="tab" href="#videos"><strong>Videos</strong></a></li>
	                </ul><br>
	                <div class="tab-content">
		                <div id="activities" class="tab-pane active">
							<nav class="w3-sidenav w3-collapse w3-white w3-card-2 w3-animate-left" style="width:200px;">
							  <a href="javascript:void(0)" onclick="w3_close()" 
							  class="w3-closenav w3-large w3-hide-large">Close ×</a>
							  <ul id="tab" class="w3-ul">
								  <li><a data-toggle="tab" href="#assignments">Assignments</a></li>		
								  <li><a data-toggle="tab" href="#grades">Grades</a></li>		
								  <li><a data-toggle="tab" href="#quiz">Quiz test</a></li>		
								  <li><a data-toggle="tab" href="#exams">Exams</a></li>		
								  <li><a data-toggle="tab" href="#chat">Chat</a></li>
							  </ul>		
							</nav>
							<div class="w3-main" style="margin-left:200px">
								<div class="tab-content">
									<div id="assignments" class="tab-pane active">
										<header class="w3-container">
										  <span class="w3-opennav w3-large w3-hide-large" onclick="w3_open()">☰</span>
										  <h4>Assignments</h4>
										</header>

										<div class="w3-container">
											no assignment is active
										</div>
									</div>
									<div id="grades" class="tab-pane">
										<header class="w3-container">
										  <span class="w3-opennav w3-large w3-hide-large" onclick="w3_open()">☰</span>
										  <h4>Grades</h4>
										</header>

										<div class="w3-container">
											no grades
										</div>
									</div>
									<div id="quiz" class="tab-pane">
										<header class="w3-container">
										  <span class="w3-opennav w3-large w3-hide-large" onclick="w3_open()">☰</span>
										  <h4>Quiz</h4>
										</header>

										<div class="w3-container">
											no active quiz
										</div>
									</div>
									<div id="exams" class="tab-pane ">
										<header class="w3-container">
										  <span class="w3-opennav w3-large w3-hide-large" onclick="w3_open()">☰</span>
										  <h4>Exams</h4>
										</header>

										<div class="w3-container">
											no active exams
										</div>
									</div>
									<div id="chat" class="tab-pane">
										<header class="w3-container">
										  <span class="w3-opennav w3-large w3-hide-large" onclick="w3_open()">☰</span>
										  <h4>Chat</h4>
										</header>

										<div class="w3-container">
											no chats
										</div>
									</div>
								</div>
							</div>
						</div>
						<div id="content" class="tab-pane">
							<h4>Outline</h4>
							no course outline posted by the course lecturer
						</div>
						<div id="notes" class="tab-pane">
							<h4>Lesson notes</h4>
							no lesson notes posted for this course
						</div>
						<div id="books" class="tab-pane">
							<h4>Books</h4>
							no reference books for this course
						</div>
						<div id="videos" class="tab-pane">
							<h4>Videos</h4>
							no reference videos for this course
						</div>
					</div>
				<!-- placed at the end of the document so that the pages can load faster 
						============================================================================-->
					<script>
					function w3_open() {
					    document.getElementsByClassName("w3-sidenav")[0].style.display = "block";
					}
					function w3_close() {
					    document.getElementsByClassName("w3-sidenav")[0].style.display = "none";
					}
					</script>
					<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
					</script>
					<script src="dist/js/bootstrap.min.js"></script>
			</div>
		</div>
	</body>
</html>