<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Check authentication - only admin can delete students
try {
    requireAuth(['admin']);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Only allow DELETE requests
if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $current_user = getCurrentUser();
    
    // Get student ID from URL parameter or JSON body
    $student_id = intval($_GET['student_id'] ?? 0);
    if (!$student_id) {
        $json_input = json_decode(file_get_contents('php://input'), true);
        $student_id = intval($json_input['student_id'] ?? 0);
    }
    
    if (!$student_id) {
        throw new Exception('Student ID is required');
    }
    
    // Check if student exists
    $stmt = $db->prepare("SELECT * FROM students WHERE student_id = ? AND is_active = 1");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found');
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    // Check if student has voted or is a candidate
    $stmt = $db->prepare("
        SELECT 
            (SELECT COUNT(*) FROM voting_sessions WHERE student_id = ?) as vote_sessions,
            (SELECT COUNT(*) FROM candidates WHERE student_id = ?) as candidate_count
    ");
    $stmt->execute([$student_id, $student_id]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($activity['vote_sessions'] > 0 || $activity['candidate_count'] > 0) {
        // Soft delete - don't actually delete if they have voting activity
        $stmt = $db->prepare("UPDATE students SET is_active = 0, updated_at = NOW() WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $message = 'Student deactivated (has voting history)';
        $log_message = "Deactivated student with voting history: {$student['first_name']} {$student['last_name']} ({$student['student_number']})";
    } else {
        // Hard delete if no voting activity
        $stmt = $db->prepare("DELETE FROM students WHERE student_id = ?");
        $stmt->execute([$student_id]);
        $message = 'Student deleted successfully';
        $log_message = "Deleted student: {$student['first_name']} {$student['last_name']} ({$student['student_number']})";
    }
    
    $db->commit();
    
    // Log the activity
    logActivity('student_delete', $log_message);
    
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    
    error_log("Student delete API error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>