<?php

include 'db/connect.php';
error_reporting(0);

if(!empty($_POST)){
      if(isset($_POST["SID"], $_POST["title"], $_POST["Fname"], $_POST["Lname"], $_POST["sex"], $_POST["nrc_pass"], $_POST["country"], $_POST["dob"], $_POST["mobile"], $_POST["email"], $_POST["status"], $_POST["h_addre"], $_POST["p_addre"], $_POST["program"], $_POST["intake"], $_POST["study_mode"], $_POST["level"], $_POST["sponsor"], $_POST["next_kin"], $_POST["next_kin_mobile"], $_POST["relat"])) {

        $SID = trim($_POST["SID"]);
        $title = trim($_POST["title"]);
        $Fname = trim($_POST["Fname"]);
        $Lname = trim($_POST["Lname"]);
        $sex = trim($_POST["sex"]);
        $nrc_pass = trim($_POST["nrc_pass"]);
        $country = trim($_POST["country"]);
        $dob = trim($_POST["dob"]);
        $mobile = trim($_POST["mobile"]);
        $email = trim($_POST["email"]);
        $status = trim($_POST["status"]);
        $h_addre = trim($_POST["h_addre"]);
        $p_addre = trim($_POST["p_addre"]);
        $program = trim($_POST["program"]);
        $intake = trim($_POST["intake"]);
        $study_mode = trim($_POST["study_mode"]);
        $level = trim($_POST["level"]);
        $sponsor = trim($_POST["sponsor"]);
        $next_kin = trim($_POST["next_kin"]);
        $next_kin_mobile = trim($_POST["next_kin_mobile"]);
        $relat = trim($_POST["relat"]);

        $checkStmt = $db->prepare("SELECT SID FROM students WHERE SID = ? AND nrc_pass = ? LIMIT 1");
        $index = null;
        if ($checkStmt) {
            $checkStmt->bind_param("ss", $SID, $nrc_pass);
            $checkStmt->execute();
            $checkStmt->bind_result($index);
            $checkStmt->fetch();
            $checkStmt->close();
        }

        if (isset($index)) {
            echo"<script>alert('Failed! Student number already exist')</script>";
            echo"<script>window.open('students_by_admin.php','_self')</script>";

              }

        else  if (!empty($SID) && !empty($title) && !empty($Fname) && !empty($Lname) && !empty($sex) && 
        !empty($nrc_pass) && !empty($country) && !empty($dob) && !empty($mobile) && !empty($email) && 
        !empty($status) && !empty($h_addre) && !empty($p_addre) && !empty($sponsor) && !empty($next_kin) && 
        !empty($next_kin_mobile) && !empty($relat)) {
            $insert = $db->prepare("INSERT INTO students (SID, title, Fname, Lname, sex, nrc_pass, country, dob, 
              mobile, email, status, h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat,dte_adm) 
              VALUE(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
            $insert ->bind_param("sssssssssssssssss", $SID, $title, $Fname, $Lname, $sex, $nrc_pass, $country, $dob, 
            $mobile, $email, $status, $h_addre, $p_addre, $sponsor, $next_kin, $next_kin_mobile, $relat);

            if ($insert->execute()) {
              echo "<script>alert('New student added successfully')</script>";
              echo"<script>window.open('students_by_admin.php','_self')</script>";
              }
            }
            else {
              echo "<script>alert('Process failed!')</script>";
              echo"<script>window.open('students_by_admin.php','_self')</script>";

            }

        }
          }

?>
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
</head>   
<body>
  <div id="Form" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-blue"> 
        <span onclick="document.getElementById('Form').style.display='none'" 
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Add new student</h3>
      </header>
      <div class="w3-container">
        <form action="add_student.php" method="post" class="form-inline" role="form">
              <div class="form-group">
                  <lable for="SID">Student #:</lable><br>
                  <input type="text" class="form-control" name="SID" autofocus id="SID" 
                  placeholder="Enter admission mobile" autocomplete="off" required>

                </div>
                <div class="form-group">
                  <lable for="title">Title:</lable><br>
                      <select class="form-control" name="title" id="title">
                        <option>Mr.</option>
                        <option>Mrs.</option>
                        <option>Ms.</option>
                        <option>Mss.</option>
                        <option>Dr.</option>
                        <option>Prof.</option>
                        <option>Sir.</option>
                        <option>Eng.</option>
                      </select> 

                </div>
                <div class="form-group">
                  <lable for="Fname">First Name:</lable><br>
                  <input type="text" class="form-control" name="Fname" autofocus id="Fname" 
                   placeholder="Enter first names" autocomplete="off" required>

                </div>
                <div class="form-group">
                  <lable for="Lname">Last Name:</lable><br>
                  <input type="text" class="form-control" name="Lname" autofocus id="Lname" 
                  placeholder="Enter last names" autocomplete="off" required>
                </div>
                <div class="form-group">
                  <lable for="sex">Gender:</lable><br>
                      <select class="form-control" name="sex" id="sex">
                        <option>M</option>
                        <option>F</option>
                      </select> 

                </div>
                <div class="form-group">
                  <lable for="country">Country:</lable><br>
                    <select class="form-control" id="country" name="country">
                      <option>Select</option>
                     <option value="Afganistan">Afghanistan</option>
                     <option value="Albania">Albania</option>
                     <option value="Algeria">Algeria</option>
                     <option value="American Samoa">American Samoa</option>
                     <option value="Andorra">Andorra</option>
                     <option value="Angola">Angola</option>
                     <option value="Anguilla">Anguilla</option>
                     <option value="Antigua & Barbuda">Antigua & Barbuda</option>
                     <option value="Argentina">Argentina</option>
                     <option value="Armenia">Armenia</option>
                     <option value="Aruba">Aruba</option>
                     <option value="Australia">Australia</option>
                     <option value="Austria">Austria</option>
                     <option value="Azerbaijan">Azerbaijan</option>
                     <option value="Bahamas">Bahamas</option>
                     <option value="Bahrain">Bahrain</option>
                     <option value="Bangladesh">Bangladesh</option>
                     <option value="Barbados">Barbados</option>
                     <option value="Belarus">Belarus</option>
                     <option value="Belgium">Belgium</option>
                     <option value="Belize">Belize</option>
                     <option value="Benin">Benin</option>
                     <option value="Bermuda">Bermuda</option>
                     <option value="Bhutan">Bhutan</option>
                     <option value="Bolivia">Bolivia</option>
                     <option value="Bonaire">Bonaire</option>
                     <option value="Bosnia & Herzegovina">Bosnia & Herzegovina</option>
                     <option value="Botswana">Botswana</option>
                     <option value="Brazil">Brazil</option>
                     <option value="British Indian Ocean Ter">British Indian Ocean Ter</option>
                     <option value="Brunei">Brunei</option>
                     <option value="Bulgaria">Bulgaria</option>
                     <option value="Burkina Faso">Burkina Faso</option>
                     <option value="Burundi">Burundi</option>
                     <option value="Cambodia">Cambodia</option>
                     <option value="Cameroon">Cameroon</option>
                     <option value="Canada">Canada</option>
                     <option value="Canary Islands">Canary Islands</option>
                     <option value="Cape Verde">Cape Verde</option>
                     <option value="Cayman Islands">Cayman Islands</option>
                     <option value="Central African Republic">Central African Republic</option>
                     <option value="Chad">Chad</option>
                     <option value="Channel Islands">Channel Islands</option>
                     <option value="Chile">Chile</option>
                     <option value="China">China</option>
                     <option value="Christmas Island">Christmas Island</option>
                     <option value="Cocos Island">Cocos Island</option>
                     <option value="Colombia">Colombia</option>
                     <option value="Comoros">Comoros</option>
                     <option value="Congo">Congo</option>
                     <option value="Cook Islands">Cook Islands</option>
                     <option value="Costa Rica">Costa Rica</option>
                     <option value="Cote DIvoire">Cote DIvoire</option>
                     <option value="Croatia">Croatia</option>
                     <option value="Cuba">Cuba</option>
                     <option value="Curaco">Curacao</option>
                     <option value="Cyprus">Cyprus</option>
                     <option value="Czech Republic">Czech Republic</option>
                     <option value="Denmark">Denmark</option>
                     <option value="Djibouti">Djibouti</option>
                     <option value="Dominica">Dominica</option>
                     <option value="Dominican Republic">Dominican Republic</option>
                     <option value="East Timor">East Timor</option>
                     <option value="Ecuador">Ecuador</option>
                     <option value="Egypt">Egypt</option>
                     <option value="El Salvador">El Salvador</option>
                     <option value="Equatorial Guinea">Equatorial Guinea</option>
                     <option value="Eritrea">Eritrea</option>
                     <option value="Estonia">Estonia</option>
                     <option value="Ethiopia">Ethiopia</option>
                     <option value="Falkland Islands">Falkland Islands</option>
                     <option value="Faroe Islands">Faroe Islands</option>
                     <option value="Fiji">Fiji</option>
                     <option value="Finland">Finland</option>
                     <option value="France">France</option>
                     <option value="French Guiana">French Guiana</option>
                     <option value="French Polynesia">French Polynesia</option>
                     <option value="French Southern Ter">French Southern Ter</option>
                     <option value="Gabon">Gabon</option>
                     <option value="Gambia">Gambia</option>
                     <option value="Georgia">Georgia</option>
                     <option value="Germany">Germany</option>
                     <option value="Ghana">Ghana</option>
                     <option value="Gibraltar">Gibraltar</option>
                     <option value="Great Britain">Great Britain</option>
                     <option value="Greece">Greece</option>
                     <option value="Greenland">Greenland</option>
                     <option value="Grenada">Grenada</option>
                     <option value="Guadeloupe">Guadeloupe</option>
                     <option value="Guam">Guam</option>
                     <option value="Guatemala">Guatemala</option>
                     <option value="Guinea">Guinea</option>
                     <option value="Guyana">Guyana</option>
                     <option value="Haiti">Haiti</option>
                     <option value="Hawaii">Hawaii</option>
                     <option value="Honduras">Honduras</option>
                     <option value="Hong Kong">Hong Kong</option>
                     <option value="Hungary">Hungary</option>
                     <option value="Iceland">Iceland</option>
                     <option value="Indonesia">Indonesia</option>
                     <option value="India">India</option>
                     <option value="Iran">Iran</option>
                     <option value="Iraq">Iraq</option>
                     <option value="Ireland">Ireland</option>
                     <option value="Isle of Man">Isle of Man</option>
                     <option value="Israel">Israel</option>
                     <option value="Italy">Italy</option>
                     <option value="Jamaica">Jamaica</option>
                     <option value="Japan">Japan</option>
                     <option value="Jordan">Jordan</option>
                     <option value="Kazakhstan">Kazakhstan</option>
                     <option value="Kenya">Kenya</option>
                     <option value="Kiribati">Kiribati</option>
                     <option value="Korea North">Korea North</option>
                     <option value="Korea Sout">Korea South</option>
                     <option value="Kuwait">Kuwait</option>
                     <option value="Kyrgyzstan">Kyrgyzstan</option>
                     <option value="Laos">Laos</option>
                     <option value="Latvia">Latvia</option>
                     <option value="Lebanon">Lebanon</option>
                     <option value="Lesotho">Lesotho</option>
                     <option value="Liberia">Liberia</option>
                     <option value="Libya">Libya</option>
                     <option value="Liechtenstein">Liechtenstein</option>
                     <option value="Lithuania">Lithuania</option>
                     <option value="Luxembourg">Luxembourg</option>
                     <option value="Macau">Macau</option>
                     <option value="Macedonia">Macedonia</option>
                     <option value="Madagascar">Madagascar</option>
                     <option value="Malaysia">Malaysia</option>
                     <option value="Malawi">Malawi</option>
                     <option value="Maldives">Maldives</option>
                     <option value="Mali">Mali</option>
                     <option value="Malta">Malta</option>
                     <option value="Marshall Islands">Marshall Islands</option>
                     <option value="Martinique">Martinique</option>
                     <option value="Mauritania">Mauritania</option>
                     <option value="Mauritius">Mauritius</option>
                     <option value="Mayotte">Mayotte</option>
                     <option value="Mexico">Mexico</option>
                     <option value="Midway Islands">Midway Islands</option>
                     <option value="Moldova">Moldova</option>
                     <option value="Monaco">Monaco</option>
                     <option value="Mongolia">Mongolia</option>
                     <option value="Montserrat">Montserrat</option>
                     <option value="Morocco">Morocco</option>
                     <option value="Mozambique">Mozambique</option>
                     <option value="Myanmar">Myanmar</option>
                     <option value="Nambia">Nambia</option>
                     <option value="Nauru">Nauru</option>
                     <option value="Nepal">Nepal</option>
                     <option value="Netherland Antilles">Netherland Antilles</option>
                     <option value="Netherlands">Netherlands (Holland, Europe)</option>
                     <option value="Nevis">Nevis</option>
                     <option value="New Caledonia">New Caledonia</option>
                     <option value="New Zealand">New Zealand</option>
                     <option value="Nicaragua">Nicaragua</option>
                     <option value="Niger">Niger</option>
                     <option value="Nigeria">Nigeria</option>
                     <option value="Niue">Niue</option>
                     <option value="Norfolk Island">Norfolk Island</option>
                     <option value="Norway">Norway</option>
                     <option value="Oman">Oman</option>
                     <option value="Pakistan">Pakistan</option>
                     <option value="Palau Island">Palau Island</option>
                     <option value="Palestine">Palestine</option>
                     <option value="Panama">Panama</option>
                     <option value="Papua New Guinea">Papua New Guinea</option>
                     <option value="Paraguay">Paraguay</option>
                     <option value="Peru">Peru</option>
                     <option value="Phillipines">Philippines</option>
                     <option value="Pitcairn Island">Pitcairn Island</option>
                     <option value="Poland">Poland</option>
                     <option value="Portugal">Portugal</option>
                     <option value="Puerto Rico">Puerto Rico</option>
                     <option value="Qatar">Qatar</option>
                     <option value="Republic of Montenegro">Republic of Montenegro</option>
                     <option value="Republic of Serbia">Republic of Serbia</option>
                     <option value="Reunion">Reunion</option>
                     <option value="Romania">Romania</option>
                     <option value="Russia">Russia</option>
                     <option value="Rwanda">Rwanda</option>
                     <option value="St Barthelemy">St Barthelemy</option>
                     <option value="St Eustatius">St Eustatius</option>
                     <option value="St Helena">St Helena</option>
                     <option value="St Kitts-Nevis">St Kitts-Nevis</option>
                     <option value="St Lucia">St Lucia</option>
                     <option value="St Maarten">St Maarten</option>
                     <option value="St Pierre & Miquelon">St Pierre & Miquelon</option>
                     <option value="St Vincent & Grenadines">St Vincent & Grenadines</option>
                     <option value="Saipan">Saipan</option>
                     <option value="Samoa">Samoa</option>
                     <option value="Samoa American">Samoa American</option>
                     <option value="San Marino">San Marino</option>
                     <option value="Sao Tome & Principe">Sao Tome & Principe</option>
                     <option value="Saudi Arabia">Saudi Arabia</option>
                     <option value="Senegal">Senegal</option>
                     <option value="Seychelles">Seychelles</option>
                     <option value="Sierra Leone">Sierra Leone</option>
                     <option value="Singapore">Singapore</option>
                     <option value="Slovakia">Slovakia</option>
                     <option value="Slovenia">Slovenia</option>
                     <option value="Solomon Islands">Solomon Islands</option>
                     <option value="Somalia">Somalia</option>
                     <option value="South Africa">South Africa</option>
                     <option value="Spain">Spain</option>
                     <option value="Sri Lanka">Sri Lanka</option>
                     <option value="Sudan">Sudan</option>
                     <option value="Suriname">Suriname</option>
                     <option value="Swaziland">Swaziland</option>
                     <option value="Sweden">Sweden</option>
                     <option value="Switzerland">Switzerland</option>
                     <option value="Syria">Syria</option>
                     <option value="Tahiti">Tahiti</option>
                     <option value="Taiwan">Taiwan</option>
                     <option value="Tajikistan">Tajikistan</option>
                     <option value="Tanzania">Tanzania</option>
                     <option value="Thailand">Thailand</option>
                     <option value="Togo">Togo</option>
                     <option value="Tokelau">Tokelau</option>
                     <option value="Tonga">Tonga</option>
                     <option value="Trinidad & Tobago">Trinidad & Tobago</option>
                     <option value="Tunisia">Tunisia</option>
                     <option value="Turkey">Turkey</option>
                     <option value="Turkmenistan">Turkmenistan</option>
                     <option value="Turks & Caicos Is">Turks & Caicos Is</option>
                     <option value="Tuvalu">Tuvalu</option>
                     <option value="Uganda">Uganda</option>
                     <option value="United Kingdom">United Kingdom</option>
                     <option value="Ukraine">Ukraine</option>
                     <option value="United Arab Erimates">United Arab Emirates</option>
                     <option value="United States of America">United States of America</option>
                     <option value="Uraguay">Uruguay</option>
                     <option value="Uzbekistan">Uzbekistan</option>
                     <option value="Vanuatu">Vanuatu</option>
                     <option value="Vatican City State">Vatican City State</option>
                     <option value="Venezuela">Venezuela</option>
                     <option value="Vietnam">Vietnam</option>
                     <option value="Virgin Islands (Brit)">Virgin Islands (Brit)</option>
                     <option value="Virgin Islands (USA)">Virgin Islands (USA)</option>
                     <option value="Wake Island">Wake Island</option>
                     <option value="Wallis & Futana Is">Wallis & Futana Is</option>
                     <option value="Yemen">Yemen</option>
                     <option value="Zaire">Zaire</option>
                     <option value="Zambia">Zambia</option>
                     <option value="Zimbabwe">Zimbabwe</option>
                  </select>
                </div>
                <div class="form-group">
                  <lable for="nrc_pass">NRC/Passport #:</lable><br>
                      <input type="text" class="form-control" name="nrc_pass" id="nrc_pass" 
                      placeholder="nrc_pass/Passport number" autocomplete="off" required>
                </div>
                  <div class="form-group">
                  <lable for="dob">Date of Birth:</lable><br>
                      <input type="date" class="form-control" name="dob" id="dob" 
                      placeholder="Date of birth" autocomplete="off" required>
                </div>

                <div class="form-group">
                  <lable for="mobile">Mobile:</lable><br>
                  <input type="text" class="form-control" name="mobile" autofocus id="mobile" 
                  placeholder="contact" autocomplete="off" required>
                </div>
                <div class="form-group">
                  <lable for="email">Email:</lable><br>
                  <input type="email" class="form-control" name="email" autofocus id="email" 
                  value="optional" autocomplete="off">
                </div>
                  <div class="form-group">
                  <lable for="status">Status:</lable><br>
                      <select class="form-control" name="status" id="status"><!--options to select the room mobile-->
                        <option>Select</option>
                        <option>Single orphan</option>
                        <option>Double orphan</option>
                        <option>Aged parents/gurdians</option>
                        <option>N/A</option>
                      </select> 
                </div>
                <div class="form-group">
                  <lable for="h_addre">Home address:</lable><br>
                  <input type="text" class="form-control" name="h_addre" autofocus id="h_addre" 
                  placeholder="home address" autocomplete="off" required>
                </div>
                <div class="form-group">
                  <lable for="p_addre">Postal address:</lable><br>
                  <input type="text" class="form-control" name="p_addre" autofocus id="p_addre" 
                  value="optional" autocomplete="off">
                </div>
                <div class="form-group">
                   <lable for="sponsor">Sponsors:</lable><br>
                   <select class="form-control" name="sponsor" id="sponsor">
                        <option>Family</option>
                        <option>Self</option>
                        <option>Bursary</option>
                        <option>Scholarship</option>
                      </select>
                     </div>
                <div class="form-group">
                   <lable for="next_kin">Next Of Kin:</lable><br>
                   <input type="text" class="form-control" name="next_kin" autofocus id="next_kin"placeholder="next of kin" autocomplete="off" required>
                </div>
                <div class="form-group">
                  <lable for="next_kin_mobile">Next Of Kin Mobile:</lable><br>
                  <input type="text" class="form-control" name="next_kin_mobile" autofocus id="next_kin_mobile"placeholder="next of kin mobile" autocomplete="off" required>
                </div>
                <div class="form-group">
                  <lable for="relat">Relationship:</lable><br>
                    <input type="text" class="form-control" name="relat" id="relat" 
                    placeholder="relationship" autocomplete="off" required>
                </div><br><br>

      </div>
      <footer class="w3-container">
        <div class="form-group">
          <!--input type="submit" value="Submit"-->
          <button class="btn btn-sm btn-success btn-block" type="submit">Submit</button>
        </div>
        </form><!--registration form ends-->
      </footer>
    </div>
  </div>
</body>
<script src="assets/vendor/sweetalert2/dist/sweetalert2.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>