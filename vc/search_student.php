<?php
include 'db/connect.php';
error_reporting(0);
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
</head>   
<body>
  <div id="assessments" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue"> 
        <span onclick="document.getElementById('assessments').style.display='none'" 
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Upload student assessment results</h3>
      </header>
      <div class="w3-container">
        <form role="form" method="GET" action="upload_assessments.php" class="w3-row-padding">
              <!-- add class="tcal" to your input field -->
                <label>Enter student #: </label><br>
                <input type="text" class="w3-input w3-border col-xs-8" name="SID" value="" id="SID" placeholder="Enter student number"/>
                <input type="submit" class="w3-btn w3-large w3-blue" name= "search" value="Search">
            </form><br>
    </div>
  </div>
</body>
<script src="assets/vendor/sweetalert2/dist/sweetalert2.min.js"></script>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
  </script>
  <script src="dist/js/bootstrap.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>