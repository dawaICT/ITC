<?php
require_once 'includes/Database.php';

header('Content-Type: application/json');

try {
    // Get search term
    $searchTerm = isset($_GET['term']) ? trim($_GET['term']) : '';

    if (empty($searchTerm)) {
        throw new Exception('Search term is required');
    }

    // Connect to database
    $db = new Database();
    $conn = $db->getConnection();

    // Prepare the search query
    // Search by student ID or name
    $query = "SELECT student_id, first_name, last_name 
              FROM students 
              WHERE student_id LIKE :term 
              OR CONCAT(first_name, ' ', last_name) LIKE :term 
              OR first_name LIKE :term 
              OR last_name LIKE :term 
              LIMIT 10";

    $stmt = $conn->prepare($query);

    // Add wildcards to search term
    $searchTerm = "%{$searchTerm}%";
    $stmt->bindParam(':term', $searchTerm, PDO::PARAM_STR);

    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return the results
    echo json_encode([
        'success' => true,
        'students' => $students
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?> 