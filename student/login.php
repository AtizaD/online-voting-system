<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid request data');
    }

    $student_number = sanitize($input['student_number'] ?? '');
    $student_id = intval($input['student_id'] ?? 0);

    if (!$student_number || !$student_id) {
        throw new Exception('Student number and ID are required');
    }

    $db = Database::getInstance()->getConnection();

    // Verify student exists and is active/verified
    $stmt = $db->prepare("
        SELECT s.*, prog.program_name, cl.class_name 
        FROM students s
        LEFT JOIN programs prog ON s.program_id = prog.program_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        WHERE s.student_id = ? AND s.student_number = ? AND s.is_active = 1 AND s.is_verified = 1
    ");
    $stmt->execute([$student_id, $student_number]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        throw new Exception('Invalid student credentials or student not verified');
    }

    // Create student session - compatible with both formats
    $_SESSION['student_logged_in'] = true;
    $_SESSION['user_type'] = 'student';
    $_SESSION['student_id'] = $student['student_id'];
    $_SESSION['student_number'] = $student['student_number'];
    $_SESSION['student_name'] = $student['first_name'] . ' ' . $student['last_name'];
    $_SESSION['student_program'] = $student['program_name'];
    $_SESSION['student_class'] = $student['class_name'];
    $_SESSION['student_login_time'] = time();
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();

    // Log the student login
    error_log("Student login: {$student['student_number']} - {$student['first_name']} {$student['last_name']}");

    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'student' => [
            'id' => $student['student_id'],
            'number' => $student['student_number'],
            'name' => $student['first_name'] . ' ' . $student['last_name'],
            'program' => $student['program_name'],
            'class' => $student['class_name']
        ]
    ]);

} catch (Exception $e) {
    error_log("Student login error: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>