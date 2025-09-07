<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method. Only POST requests are allowed.');
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON data provided.');
    }
    
    $student_number = sanitize($input['student_number'] ?? '');
    $student_id = intval($input['student_id'] ?? 0);
    
    if (!$student_number || !$student_id) {
        throw new Exception('Student number and ID are required.');
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Verify student exists and is verified
    $stmt = $db->prepare("
        SELECT student_id, student_number, first_name, last_name, 
               program_id, class_id, is_active, is_verified
        FROM students 
        WHERE student_id = ? AND student_number = ? AND is_active = 1 AND is_verified = 1
    ");
    $stmt->execute([$student_id, $student_number]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found or not verified.');
    }
    
    // Create student session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['student_id'] = $student['student_id'];
    $_SESSION['student_number'] = $student['student_number'];
    $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
    $_SESSION['user_type'] = 'student';
    $_SESSION['login_time'] = time();
    
    // Log the session creation
    error_log("Student session created for: {$student['student_number']} - {$student['first_name']} {$student['last_name']}");
    
    echo json_encode([
        'success' => true,
        'message' => 'Session created successfully',
        'redirect' => SITE_URL . '/student/dashboard'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>