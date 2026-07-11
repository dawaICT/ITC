
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
</head>   
<body>
  <div id="ca" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue"> 
        <span onclick="document.getElementById('ca').style.display='none'" 
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Upload CA results</h3>
      </header>
      <div class="w3-container">
        <form  action="uploaded_ca.php" method="post" enctype="multipart/form-data">
                <div class="form-group">
                  <lable for="file">upload csv file only:</lable><br>
                  <input type="file" class="form-control" name="file" accept=".csv">
                </div>

                    <div class="form-group">
                      <label>Year: </label>
                        <select class="w3-input w3-border col-xs-3 form-control"  id="Year" name="Year">
                          <option disabled selected>Select year</option>
                        </select>
                          <script type="text/javascript"
                              src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"> 
                          </script>
                          <script type="text/javascript">
                          let startYear = 2000;
                          let endYear = new Date().getFullYear();
                          for (x = endYear; x > startYear; x--)
                          {
                            $('#Year').append($('<option />').val(x).html(x));
                          }
                          </script>

                    </div><br>
                <div class="form-group">
                  <!--input type="submit" value="Submit"-->
                  <button class="btn btn-sm btn-success btn-block" type="submit" name="import">SUBMIT</button>
                </div>  
              </div>

        </form><!--registration form ends-->
    </div>
  </div>
</body>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.11.0/jquery.min.js">
      </script>
      <script src="dist/js/bootstrap.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>