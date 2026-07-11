<?php
require_once "connect.php";

// Function to execute SQL and handle errors
function executeSQLFile($db, $sql) {
    $errors = [];
    $messages = [];

    // Split SQL by delimiter
    $queries = array_filter(
        array_map('trim', 
            explode(';', str_replace('DELIMITER //', '', str_replace('DELIMITER ;', '', $sql)))
        )
    );

    // Start transaction
    $db->begin_transaction();

    try {
        foreach ($queries as $query) {
            if (empty(trim($query))) continue;

            // Handle procedure and trigger creation
            if (stripos($query, 'CREATE TRIGGER') !== false || 
                stripos($query, 'CREATE PROCEDURE') !== false) {
                if (!$db->query($query . ';')) {
                    throw new Exception("Error executing query: " . $db->error);
                }
                $messages[] = "Successfully executed: " . substr($query, 0, 50) . "...";
            } 
            // Handle regular queries
            else {
                if (!$db->query($query)) {
                    throw new Exception("Error executing query: " . $db->error);
                }
                $messages[] = "Successfully executed: " . substr($query, 0, 50) . "...";
            }
        }

        // If we got here, commit the transaction
        $db->commit();
        $messages[] = "All updates completed successfully!";

    } catch (Exception $e) {
        // Something went wrong, rollback
        $db->rollback();
        $errors[] = $e->getMessage();
        $messages[] = "Updates rolled back due to error.";
    }

    return ['errors' => $errors, 'messages' => $messages];
}

// Read and execute the SQL file
$sql = file_get_contents(__DIR__ . '/update_academic_structure.sql');
$result = executeSQLFile($db, $sql);

// Output results
echo "<h2>Update Results:</h2>";

if (!empty($result['errors'])) {
    echo "<h3>Errors:</h3>";
    echo "<ul style='color: red'>";
    foreach ($result['errors'] as $error) {
        echo "<li>" . htmlspecialchars($error) . "</li>";
    }
    echo "</ul>";
}

echo "<h3>Messages:</h3>";
echo "<ul style='color: green'>";
foreach ($result['messages'] as $message) {
    echo "<li>" . htmlspecialchars($message) . "</li>";
}
echo "</ul>";

$db->close(); 