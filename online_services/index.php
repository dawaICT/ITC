<?php
require "includes/nav.php";
error_reporting(0);

if(isset($_POST['submit']))
{
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
        $sponsor = trim($_POST["sponsor"]);
        $next_kin = trim($_POST["next_kin"]);
        $next_kin_mobile = trim($_POST["next_kin_mobile"]);
        $relat = trim($_POST["relat"]);
        $program = trim($_POST["program"]);
        $intake = trim($_POST["intake"]);
        $mode = trim($_POST["mode"]);
        $year = trim($_POST["year"]);

        // ---- Robust, safe file uploads --------------------------------------
        // Save each upload under a generated name (results_<time>_<rand>.<ext>)
        // so original filenames cannot collide, overwrite, or break the link.
        $upload_dir = __DIR__ . "/uploads/";
        if (!is_dir($upload_dir)) { @mkdir($upload_dir, 0775, true); }
        $allowed_ext = ['jpg', 'jpeg', 'png', 'pdf'];

        /**
         * Move one optional upload, validating its extension, and return the
         * stored (generated) filename or '' when nothing was uploaded.
         * Throws on a real failure so the whole submission can be aborted.
         */
        $store_upload = function ($field, $prefix) use ($upload_dir, $allowed_ext) {
            if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                return '';
            }
            if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("Upload failed for {$field}.");
            }
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext, true)) {
                throw new Exception("Invalid file format. Upload files in pdf, png or jpg.");
            }
            $name = $prefix . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
            if (!move_uploaded_file($_FILES[$field]['tmp_name'], $upload_dir . $name)) {
                throw new Exception("Could not save the uploaded {$field}.");
            }
            return $name;
        };

        try {
            $tem  = $store_upload('results', 'results');       // results transcript
            $tem2 = $store_upload('nrc_file', 'nrc');          // NRC / passport
            $tem3 = $store_upload('deposit_slip', 'deposit');  // deposit slip

            $insert = "INSERT INTO online_applicants (title, Fname, Lname, sex, nrc_pass, country, dob,
              mobile, email, status, h_addre, p_addre, sponsor, next_kin, next_kin_mobile, relat, program,
              intake, mode, year, results, nrc_file, deposit_slip, dte_adm)
              VALUES (?,?,?,?,?,?,?,?,?,'pending',?,?,?,?,?,?,?,?,?,?,?,?,?, NOW())";

            $stmt = mysqli_prepare($db, $insert);
            if (!$stmt) {
                throw new Exception("Could not prepare application: " . mysqli_error($db));
            }
            mysqli_stmt_bind_param(
                $stmt,
                str_repeat('s', 23),
                $title, $Fname, $Lname, $sex, $nrc_pass, $country, $dob,
                $mobile, $email, $h_addre, $p_addre, $sponsor, $next_kin,
                $next_kin_mobile, $relat, $program, $intake, $mode, $year,
                $tem, $tem2, $tem3
            );
            if (!mysqli_stmt_execute($stmt)) {
                throw new Exception("Could not save application: " . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);

            echo "<script>alert('Your application was successfully submitted. Log in to the Applicant Portal to track your status.')</script>";
            echo "<script>window.open('../','_self')</script>";
        } catch (Exception $e) {
            error_log("Online application submission failed: " . $e->getMessage());
            $msg = htmlspecialchars($e->getMessage(), ENT_QUOTES);
            echo "<script>alert(" . json_encode($msg) . ")</script>";
            echo "<script>window.open('index.php','_self')</script>";
        }

    }

?>
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="assets/vendor/sweetalert2/dist/sweetalert2.min.css">
</head>   
<body>
      <div class="w3-container">
        <div class="row">
            <div class="col-sm-1"></div>
                <div class="col-sm-8 card-4">
                    <div class="jumbotron">
                        <div class="container">
                            <h4 class="w3-center"><strong>Apply now by filling in the form below.</strong></h4>
                        <form action="index.php" method="post" role="form" enctype="multipart/form-data">
                                <div class="form-group">
                                  <label for="title">Title:</label><br>
                                      <select class="form-control" name="title" id="title">
                                        <option selected disabled>--select title--</option>
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
                                  <label for="Fname">First Name:</label><br>
                                  <input type="text" class="form-control" name="Fname" autofocus id="Fname" 
                                   placeholder="Enter first names" autocomplete="off" required>

                                </div>
                                <div class="form-group">
                                  <label for="Lname">Last Name:</label><br>
                                  <input type="text" class="form-control" name="Lname" autofocus id="Lname" 
                                  placeholder="Enter last names" autocomplete="off" required>
                                </div>
                                <div class="form-group">
                                  <label for="sex">Gender:</label><br>
                                      <select class="form-control" name="sex" id="sex">
                                        <option selected disabled>--select gender--</option>
                                        <option>M</option>
                                        <option>F</option>
                                      </select> 

                                </div>
                                <div class="form-group">
                                  <label for="country">Country:</label><br>
                                    <select class="form-control" id="country" name="country">
                                      <option selected disabled>Select country</option>
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
                                     <option value="Congo">Congo DR</option>
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
                                     <option value="Zambia">Zambia</option>
                                     <option value="Zimbabwe">Zimbabwe</option>
                                  </select>
                                </div>
                                <div class="form-group">
                                  <label for="nrc_pass">NRC/Passport No:</label><br>
                                      <input type="text" class="form-control" name="nrc_pass" id="nrc_pass" 
                                      placeholder="nrc_pass/Passport number" autocomplete="off" required>
                                </div>
                                  <div class="form-group">
                                  <label for="dob">Date of Birth:</label><br>
                                      <input type="date" class="form-control" name="dob" id="dob" 
                                      placeholder="Date of birth" autocomplete="off" required>
                                </div>

                                <div class="form-group">
                                  <label for="mobile">Mobile:</label><br>
                                  <input type="text" class="form-control" name="mobile" autofocus id="mobile" 
                                  placeholder="contact" autocomplete="off" required>
                                </div>
                                <div class="form-group">
                                  <label for="email">Email:</label><br>
                                  <input type="text" class="form-control" name="email" autofocus id="email" 
                                  value="optional" autocomplete="off">
                                </div>
                                  <div class="form-group">
                                  <label for="status">Marital Status:</label><br>
                                      <select class="form-control" name="status" id="status"><!--options to select the room mobile-->
                                        <option selected disabled>Select</option>
                                        <option>Married</option>
                                        <option>Single</option>
                                        <option>Other</option>
                                      </select> 
                                </div>
                                <div class="form-group">
                                  <label for="h_addre">Home address:</label><br>
                                  <input type="text" class="form-control" name="h_addre" autofocus id="h_addre" 
                                  placeholder="home address" autocomplete="off" required>
                                </div>
                                <div class="form-group">
                                  <label for="p_addre">Postal address:</label><br>
                                  <input type="text" class="form-control" name="p_addre" autofocus id="p_addre" 
                                  value="optional" autocomplete="off">
                                </div>
                                <div class="form-group">
                                   <label for="sponsor">Sponsors:</label><br>
                                   <select class="form-control" name="sponsor" id="sponsor">
                                        <option selected disabled>--select--</option>
                                        <option>Family</option>
                                        <option>Self</option>
                                        <option>Bursary</option>
                                        <option>Scholarship</option>
                                      </select>
                                     </div>
                                <div class="form-group">
                                   <label for="next_kin">Next Of Kin:</label><br>
                                   <input type="text" class="form-control" name="next_kin" autofocus id="next_kin"placeholder="next of kin" autocomplete="off" required>
                                </div>
                                <div class="form-group">
                                  <label for="next_kin_mobile">Next Of Kin Mobile:</label><br>
                                  <input type="text" class="form-control" name="next_kin_mobile" autofocus id="next_kin_mobile"placeholder="next of kin mobile" autocomplete="off" required>
                                </div>
                                <div class="form-group">
                                   <label for="relat">Relationship:</label><br>
                                   <select class="form-control" name="relat" id="relat">
                                        <option selected disabled>--select--</option>
                                        <option>Mother</option>
                                        <option>Father</option>
                                        <option>Sibling</option>
                                        <option>Aunt</option>
                                        <option>Uncle</option>
                                        <option>Wife</option>
                                        <option>Husband</option>
                                        <option>Grand father</option>
                                        <option>Grand mother</option>
                                      </select>
                                     </div>
                                <div class="form-group">
                                <label for="program">Program of study:</label><br>
                                <select class="form-control" name="program" id="program" required>
                                <option disabled selected value="">--Select program--</option>
                                  <?php
                                    $records = [];
                                    if ($results = $db->query("SELECT program_code, program_name FROM programs WHERE COALESCE(is_active, 1) = 1 ORDER BY program_name")) {
                                        if ($count = $results->num_rows) {
                                            while ($row = $results->fetch_object()) {
                                                $records[] = $row;
                                            }
                                            $results->free();
                                        }
                                    }
                                    foreach ($records as $r) {
                                    ?>
                                    <option value="<?php echo htmlspecialchars((string)$r->program_code, ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string)$r->program_name, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>

                                    <?php
                                    }
                                    ?>
                                </select>
                                </div>
                                <div class="form-group">
                                   <label for="intake">Intake:</label><br>
                                   <select class="form-control" name="intake" id="intake">
                                        <option selected disabled>select</option>
                                        <option>January</option>
                                        <option>June</option>
                                      </select>
                                     </div>
                                     <div class="form-group">
                                       <label for="mode">Mode of Study:</label><br>
                                       <select class="form-control" name="mode" id="mode">
                                        <option selected disabled>select mode</option>
                                        <option>Full-Time</option>
                                        <option>Distance</option>
                                        <option>Part-Time(Evening)</option>
                                        <option>Short course</option>
                                      </select>
                                     </div>
                                <div class="form-group">
                                <label for="year">Year:</label><br>
                                <select class="form-control"  id="year" name="year">
                                    <option disabled selected>Year</option>
                                  </select>
                                    <script type="text/javascript"
                                        src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.4.1/jquery.min.js"> 
                                    </script>
                                    <script type="text/javascript">
                                    let startYear = 2000;
                                    let endYear = new Date().getFullYear();
                                    for (i = endYear; i > startYear; i--)
                                    {
                                      $('#year').append($('<option/>').val(i).html(i));
                                    }
                                    </script>
                                </div>
                                <div class="form-group">
                                  <label for="results">Results:<small class="w3-text-red">Upload results in one file</small></label><br>
                                    <input type="file" class="form-control" name="results" id="results" 
                                    placeholder="#" required>
                                </div>
                                <div class="form-group">
                                  <label for="nrc_file">NRC Copy:</label><br>
                                    <input type="file" class="form-control" name="nrc_file" id="nrc_file" 
                                    placeholder="#" required>
                                </div>
                                <div class="form-group">
                                  <label for="deposit_slip">Bank Deposit slip:</label><br>
                                    <input type="file" class="form-control" name="deposit_slip" id="deposit_slip" 
                                    placeholder="#" required>
                                </div><br><br>
                            <div class="form-group">
                              <!--input type="submit" value="Submit"-->
                              <button class="btn btn-success btn-block w3-large" type="submit" name="submit">Submit Application</button>
                            </div>
                            </form><!--registration form ends--> 
                        </div>
                    </div>
                </div>
        <div class="col-sm-1"></div>
        </div>      
      </div>
</body>
<script src="assets/vendor/sweetalert2/dist/sweetalert2.min.js"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>