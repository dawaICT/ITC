<?php
// Define IS_SCRIPT to indicate this is a standalone script
define('IS_SCRIPT', true);

// Include the database connection
require_once 'db/connect.php';

// Check connection
if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "Database connection successful<br>";

// Check if the staff table exists
$result = $db->query("SHOW TABLES LIKE 'staff'");
if ($result->num_rows > 0) {
    echo "Staff table exists<br>";
    
    // Check the structure of the staff table
    $result = $db->query("DESCRIBE staff");
    echo "<h3>Staff Table Structure:</h3>";
    echo "<table border='1'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    
    $profile_image_exists = false;
    
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['Field'] . "</td>";
        echo "<td>" . $row['Type'] . "</td>";
        echo "<td>" . $row['Null'] . "</td>";
        echo "<td>" . $row['Key'] . "</td>";
        echo "<td>" . ($row['Default'] === NULL ? 'NULL' : $row['Default']) . "</td>";
        echo "<td>" . $row['Extra'] . "</td>";
        echo "</tr>";
        
        if ($row['Field'] == 'profile_image') {
            $profile_image_exists = true;
        }
    }
    
    echo "</table><br>";
    
    if (!$profile_image_exists) {
        echo "<strong>The profile_image column does not exist in the staff table.</strong><br>";
        echo "SQL to add the column: <pre>ALTER TABLE staff ADD COLUMN profile_image VARCHAR(255) NULL;</pre>";
    } else {
        echo "<strong>The profile_image column exists in the staff table.</strong><br>";
    }
    
    // Display the first few records to examine the structure
    $result = $db->query("SELECT * FROM staff LIMIT 3");
    if ($result->num_rows > 0) {
        echo "<h3>Sample Staff Records:</h3>";
        echo "<table border='1'>";
        
        // Get field names
        $fields = $result->fetch_fields();
        echo "<tr>";
        foreach ($fields as $field) {
            echo "<th>" . $field->name . "</th>";
        }
        echo "</tr>";
        
        // Reset result pointer
        $result->data_seek(0);
        
        // Display data
        while ($row = $result->fetch_assoc()) {
            echo "<tr>";
            foreach ($row as $key => $value) {
                echo "<td>" . (is_null($value) ? 'NULL' : htmlspecialchars($value)) . "</td>";
            }
            echo "</tr>";
        }
        
        echo "</table>";
    } else {
        echo "No staff records found.";
    }
} else {
    echo "Staff table does not exist!";
}

// Close connection
$db->close();
?> 