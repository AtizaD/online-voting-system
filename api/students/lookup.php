<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

try {
    // Log the request for debugging
    error_log("Student lookup API called - Method: " . $_SERVER['REQUEST_METHOD'] . " - Student: " . ($_GET['student_number'] ?? 'none'));
    
    // Only accept GET requests with student_number parameter
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        error_log("Invalid request method to student lookup: " . $_SERVER['REQUEST_METHOD']);
        throw new Exception('Invalid request method. Only GET requests are allowed.');
    }
    
    $student_number = sanitize($_GET['student_number'] ?? '');
    
    if (!$student_number) {
        throw new Exception('Student number is required');
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Verify student exists and is active/verified
    $stmt = $db->prepare("
        SELECT s.student_id, s.student_number, s.first_name, s.last_name, 
               s.is_active, s.is_verified,
               CASE 
                   WHEN s.is_verified = 1 THEN 'verified'
                   ELSE 'pending'
               END as verification_status
        FROM students s
        WHERE s.student_number = ? AND s.is_active = 1
    ");
    $stmt->execute([$student_number]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        echo json_encode([
            'success' => false, 
            'message' => 'Student number not found'
        ]);
        exit;
    }
    
    // Return student verification status (but not sensitive data)
    echo json_encode([
        'success' => true,
        'data' => [
            'student_id' => $student['student_id'],
            'student_number' => $student['student_number'],
            'first_name' => $student['first_name'],
            'last_name' => $student['last_name'],
            'verification_status' => $student['verification_status']
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>