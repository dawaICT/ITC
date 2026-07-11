<?php
/**
 * Add Staff Processing Script
 * 
 * SECURITY FEATURES:
 * - CSRF token validation
 * - Prepared statements for SQL injection prevention
 * - Input validation and sanitization
 * - Duplicate detection (ID, NRC, Email)
 * - Transaction support for data integrity
 * 
 * @version 2.0
 * @updated 2026-02-03
 */

include '../db/connect.php';
include '../includes/id_helpers.php';
include '../includes/role_helpers.php';
include __DIR__ . '/../includes/schema_helpers.php';
require_once __DIR__ . '/../includes/helpers/staff_provisioning.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Redirect if not admin or registrar. Bounce to the staff hub (absolute), not
// '../index.php' which resolves to the STUDENT portal from admin/. The hub
// guards itself, so a genuinely unauthenticated hit still ends up at staff login.
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], [ROLE_SYSTEMS_ADMIN, ROLE_REGISTRAR])) {
    $_SESSION['errorMssg'] = 'Unauthorized access.';
    header('Location: /wucportal/portal_selection.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
        $_SESSION['errorMssg'] = "Invalid security token. Please try again.";
        header("Location: staff.php");
        exit();
    }
    
    if (isset($_POST['title'], $_POST['Fname'], $_POST['Lname'], $_POST['sex'], $_POST['mobile'], $_POST['email'], $_POST['qualification'])) {
        // Staff numbers are system-generated only: ITC001, ITC002, ITC003...
        // Do not trust or persist a manually typed staff number.
        $staff_id = generateNextStaffId($db);
        $deptId = trim($_POST['deptId'] ?? '');
        $title = trim($_POST['title']);
        $Fname = trim($_POST['Fname']);
        $Lname = trim($_POST['Lname']);
        $sex = trim($_POST['sex']);
        $nrc_pass = trim($_POST['nrc_pass']);
        $mobile = trim($_POST['mobile']);
        $email = trim($_POST['email']);
        $address = trim($_POST['address']);
        $country = trim($_POST['country']);
        $qualification = trim($_POST['qualification']);
        $role = is_array($_POST['role'] ?? null)
            ? 'Staff'
            : trim((string) ($_POST['role'] ?? 'Staff'));
        $roleCanonical = normalizeRole($role);

        if (!isValidStaffRole($role)) {
            $_SESSION['errorMssg'] = 'Invalid staff role selected.';
            header('Location: staff.php');
            exit();
        }
        if ($roleCanonical === ROLE_SYSTEMS_ADMIN && ($_SESSION['role'] ?? '') !== ROLE_SYSTEMS_ADMIN) {
            $_SESSION['errorMssg'] = 'Only a Systems Administrator can create another Systems Administrator account.';
            header('Location: staff.php');
            exit();
        }
        if ($Fname === '' || $Lname === '' || !preg_match('/^[A-Za-z .\'-]{1,100}$/', $Fname . ' ' . $Lname)) {
            $_SESSION['errorMssg'] = 'Staff first and last name are required and may only contain letters, spaces, apostrophes, periods, or hyphens.';
            header('Location: staff.php');
            exit();
        }
        if (!preg_match('/^[0-9+() .-]{6,40}$/', $mobile)) {
            $_SESSION['errorMssg'] = 'Invalid mobile number format.';
            header('Location: staff.php');
            exit();
        }
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['errorMssg'] = 'Invalid email format.';
            header('Location: staff.php');
            exit();
        }

        // Validate sex value (assuming ENUM in database)
        if (!in_array($sex, ['Male', 'Female', 'Other', 'M', 'F'], true)) {
            $_SESSION['errorMssg'] = 'Invalid gender value.';
            header('Location: staff.php');
            exit();
        }
        if ($sex === 'Male') { $sex = 'M'; }
        if ($sex === 'Female') { $sex = 'F'; }

        if (!validateStaffId($staff_id)) {
            $_SESSION['errorMssg'] = 'Could not generate a valid ITC staff number. Please try again.';
            header('Location: staff.php');
            exit();
        }

        try {
            // Start transaction
            $db->begin_transaction();
            $staffColumns = wuc_table_columns($db, 'staff');
            $staffDeptCol = wuc_detect_column($db, 'staff', ['deptId', 'DeptID', 'department_id']);
            $deptIdCol = wuc_detect_column($db, 'departments', ['department_id', 'DeptID', 'id', 'deptId']);

            // Check for duplicates (Staff ID, NRC, and Email)
            $duplicateChecks = ['staff_id = ?', 'email = ?'];
            $duplicateTypes = 'ss';
            $duplicateParams = [$staff_id, $email];
            if (isset($staffColumns['nrc_pass'])) {
                $duplicateChecks[] = "`{$staffColumns['nrc_pass']}` = ?";
                $duplicateTypes .= 's';
                $duplicateParams[] = $nrc_pass;
            }
            $check = $db->prepare("SELECT staff_id FROM staff WHERE " . implode(' OR ', $duplicateChecks) . " LIMIT 1");
            if (!$check) {
                throw new Exception("Failed to prepare duplicate check: " . $db->error);
            }
            
            wuc_bind_param_array($check, $duplicateTypes, $duplicateParams);
            $check->execute();
            $result = $check->get_result();
            
            if ($result->num_rows > 0) {
                $check->close();
                throw new Exception('Staff ID, NRC/Passport, or Email already exists.');
            }
            $check->close();

            if ($staffDeptCol && $deptIdCol) {
                $dept_check = $db->prepare("SELECT `{$deptIdCol}` FROM departments WHERE `{$deptIdCol}` = ? LIMIT 1");
                if (!$dept_check) {
                    throw new Exception("Failed to prepare department check: " . $db->error);
                }
                $dept_check->bind_param("s", $deptId);
                $dept_check->execute();
                $dept_result = $dept_check->get_result();
                if ($dept_result->num_rows === 0) {
                    $dept_check->close();
                    throw new Exception("Selected department does not exist.");
                }
                $dept_check->close();
            }

            // Insert new staff member
            if ($staff_id && $title && $Fname && $Lname && $sex && $mobile && $email && $qualification) {
                $temporaryPassword = password_hash($staff_id, PASSWORD_DEFAULT);
                $fieldValues = [
                    'staff_id' => $staff_id,
                    $staffDeptCol => $deptId,
                    'title' => $title,
                    'Fname' => $Fname,
                    'Lname' => $Lname,
                    'sex' => $sex,
                    'nrc_pass' => $nrc_pass,
                    'mobile' => $mobile,
                    'email' => $email,
                    'address' => $address,
                    'country' => $country,
                    'qualification' => $qualification,
                    'password' => $temporaryPassword,
                    'role' => $roleCanonical,
                    'status' => 'active',
                ];
                $columns = [];
                $placeholders = [];
                $types = '';
                $params = [];
                foreach ($fieldValues as $column => $value) {
                    if ($column !== null && isset($staffColumns[strtolower($column)])) {
                        $columns[] = "`{$staffColumns[strtolower($column)]}`";
                        $placeholders[] = '?';
                        $types .= 's';
                        $params[] = $value;
                    }
                }
                $insert = $db->prepare("INSERT INTO staff (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")");
                if (!$insert) {
                    throw new Exception("Failed to prepare insert: " . $db->error);
                }
                
                wuc_bind_param_array($insert, $types, $params);
                
                if (!$insert->execute()) {
                    throw new Exception("Failed to execute insert: " . $insert->error);
                }
                
                $insert->close();
                
                // Assign role to the new staff member. Store the CANONICAL role
                // (same value written to staff.role above) — access_right.assigned_access
                // is read at higher priority than staff.role by wuc_hydrate_staff_roles(),
                // so the two must agree. Previously this stored the raw submitted value.
                if (!empty($roleCanonical)) {
                    $role_insert = $db->prepare("INSERT INTO access_right (staff_id, assigned_access) VALUES (?, ?)");
                    if ($role_insert) {
                        $role_insert->bind_param('ss', $staff_id, $roleCanonical);
                        if (!$role_insert->execute()) {
                            throw new Exception("Failed to assign role: " . $role_insert->error);
                        }
                        $role_insert->close();
                    }
                }

                $provision = wuc_provision_staff_account(
                    $db,
                    $staff_id,
                    $roleCanonical,
                    $staff_id,
                    (string)($_SESSION['staff_id'] ?? 'admin')
                );
                if (!$provision['ok']) {
                    throw new Exception('Account provisioning incomplete: ' . implode('; ', $provision['messages']));
                }
                
                // Commit transaction
                $db->commit();
                
                // Log the action
                $admin_id = $_SESSION['staff_id'] ?? $_SESSION['username'] ?? 'unknown';
                error_log("Staff member added: $staff_id by admin: $admin_id");
                
                $_SESSION['successMssg'] = 'New staff added successfully. ID: ' . htmlspecialchars($staff_id);
                header('Location: staff.php');
                exit();
            }
            
            throw new Exception('All required fields must be filled.');
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $db->rollback();
            
            $_SESSION['errorMssg'] = 'Error: ' . $e->getMessage();
            error_log("Add staff error: " . $e->getMessage());
            header('Location: staff.php');
            exit();
        }
    }

    $_SESSION['errorMssg'] = 'Process failed! Missing required fields.';
    header('Location: staff.php');
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
</head>   
<body>
  <div id="staff" class="w3-modal">
    <div class="w3-modal-content w3-animate-zoom w3-card-8">
      <header class="w3-container w3-purple"> 
        <span onclick="document.getElementById('staff').style.display='none'" 
        class="w3-closebtn">×</span>
        <h3 class="w3-center">Add new staff</h3>
      </header>
      <div class="w3-container">
        <form action="add_staff.php" method="post" class="#" role="form">
              <input type="hidden" name="csrf_token" value="<?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } echo htmlspecialchars($_SESSION['csrf_token']); ?>">
              <div class="form-group">
                  <label for="staff_id">Staff ID:</label><br>
                  <input type="text" class="form-control" name="staff_id" autofocus id="staff_id" 
                                    placeholder="Enter staff ID" autocomplete="off" required>

                </div>
                <div class="form-group">
                  <lable for="deptId">Department:</lable><br>
                  <select class="form-control" name="deptId" id="deptId">
                    <option disabled selected>Select</option>
                  <?php
                    $Recordz = [];

                    if($results = $db->query("SELECT * FROM departments")) {
                              if($count = $results->num_rows) {

                              while($row = $results->fetch_object()){

                                $Recordz[] = $row;
                            }

                            $results->free();
                          }
                        }
                  ?>
                  <?php
                    foreach($Recordz as $r) {
                    ?>
                    <option value="<?php echo htmlspecialchars($r->department_id ?? $r->deptId ?? ''); ?>"><?php echo htmlspecialchars($r->department_name ?? $r->deptName ?? ''); ?></option>

                    <?php 
                    }  
                    ?>
                </select> 
                </div>
                <div class="form-group">
                  <label for="title">Title:</label><br>
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
                        <option>M</option>
                        <option>F</option>
                      </select> 

                </div>
                <div class="form-group">
                  <label for="nrc_pass">NRC/Passport #:</label><br>
                      <input type="text" class="form-control" name="nrc_pass" id="nrc_pass" 
                      placeholder="nrc/passport number" autocomplete="off" required>
                </div>       
                <div class="form-group">
                  <label for="mobile">Mobile:</label><br>
                  <input type="text" class="form-control" name="mobile" autofocus id="mobile" 
                  placeholder="contact" autocomplete="off" required>
                </div>
                <div class="form-group">
                  <label for="email">Email:</label><br>
                  <input type="email" class="form-control" name="email" autofocus id="email" 
                  placeholder="example@zambia.com" autocomplete="off">
                </div>
                <div class="form-group">
                  <label for="address">Home address:</label><br>
                  <input type="text" class="form-control" name="address" autofocus id="address" 
                  placeholder="home address" autocomplete="off" required>
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
                     <option value="DR Congo">DR Congo</option>
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
                  <label for="qualification">Professional Qualification(s):</label><br>
                  <input type="text" class="form-control" name="qualification" autofocus id="qualification" 
                  value="optional" autocomplete="off">
                </div><br><br>

      </div>
      <footer class="w3-container">
        <div class="form-group">
          <!--input type="submit" value="Submit"-->
          <button class="btn w3-orange btn-block" type="submit">Submit</button>
        </div>
        </form><!--registration form ends-->
      </footer>
    </div>
  </div>
</body>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Mirrored from www.w3schools.com/w3css/tryit.asp?filename=tryw3css_bar_mobile by HTTrack Website Copier/3.x [XR&CO'2014], Mon, 08 Mar 2021 17:15:51 GMT -->
</html>
