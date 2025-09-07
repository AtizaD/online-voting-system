<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Check authentication
try {
    requireAuth(['election_officer', 'admin', 'staff']);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Allow both POST and GET for debugging
if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'GET'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Handle both JSON and form data
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $json_input = json_decode(file_get_contents('php://input'), true);
        if ($json_input) {
            $input = $json_input;
        } else {
            $input = $_POST;
        }
    } else {
        $input = $_GET;
    }
    
    if (!$input || !isset($input['student_id']) || !isset($input['status'])) {
        throw new Exception('Missing required parameters: student_id and status required');
    }
    
    $student_id = intval($input['student_id']);
    $status = $input['status'];
    
    if (!$student_id) {
        throw new Exception('Invalid student ID');
    }
    
    if (!in_array($status, ['verified', 'pending'])) {
        throw new Exception('Invalid status');
    }
    
    $db = Database::getInstance()->getConnection();
    $current_user = getCurrentUser();
    
    // Begin transaction
    $db->beginTransaction();
    
    // Get student info first
    $stmt = $db->prepare("SELECT first_name, last_name, student_number FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch();
    
    if (!$student) {
        throw new Exception('Student not found');
    }
    
    // Update student verification status
    if ($status === 'verified') {
        $stmt = $db->prepare("
            UPDATE students 
            SET is_verified = 1, verified_by = ?, verified_at = NOW(), updated_at = NOW()
            WHERE student_id = ?
        ");
        $stmt->execute([$current_user['id'], $student_id]);
        
        $message = 'Student verified successfully';
        $log_action = 'student_verify';
        $log_message = "Verified student: {$student['first_name']} {$student['last_name']} ({$student['student_number']})";
        
    } else { // pending
        $stmt = $db->prepare("
            UPDATE students 
            SET is_verified = 0, verified_by = NULL, verified_at = NULL, updated_at = NOW()
            WHERE student_id = ?
        ");
        $stmt->execute([$student_id]);
        
        $message = 'Student set to pending verification';
        $log_action = 'student_unverify';
        $log_message = "Set student to pending: {$student['first_name']} {$student['last_name']} ({$student['student_number']})";
    }
    
    // Check if update was successful
    if ($stmt->rowCount() === 0) {
        throw new Exception('No changes made - student may already have this status');
    }
    
    // Commit transaction
    $db->commit();
    
    // Log the activity
    logActivity($log_action, $log_message);
    
    // Set session success message for display after redirect
    $_SESSION['success'] = $message;
    
    echo json_encode([
        'success' => true, 
        'message' => $message
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    
    error_log("Student verification API error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}
?>