<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Check authentication
try {
    requireAuth(['admin', 'staff']);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Only allow PUT/PATCH requests
if (!in_array($_SERVER['REQUEST_METHOD'], ['PUT', 'PATCH', 'POST'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $current_user = getCurrentUser();
    
    // Handle both JSON and form data
    $json_input = json_decode(file_get_contents('php://input'), true);
    $input = $json_input ?: $_POST;
    
    // Validate student ID
    if (!isset($input['student_id']) || !intval($input['student_id'])) {
        throw new Exception('Student ID is required');
    }
    
    $student_id = intval($input['student_id']);
    
    // Check if student exists
    $stmt = $db->prepare("SELECT * FROM students WHERE student_id = ? AND is_active = 1");
    $stmt->execute([$student_id]);
    $existing_student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing_student) {
        throw new Exception('Student not found');
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    // Build update query dynamically based on provided fields
    $update_fields = [];
    $params = [];
    
    $allowed_fields = [
        'student_number', 'first_name', 'last_name', 'phone',
        'program_id', 'class_id', 'gender', 'photo_url'
    ];
    
    foreach ($allowed_fields as $field) {
        if (isset($input[$field])) {
            $update_fields[] = "$field = ?";
            $params[] = trim($input[$field]);
        }
    }
    
    // Validate specific fields if provided
    if (isset($input['gender']) && !in_array($input['gender'], ['Male', 'Female'])) {
        throw new Exception('Gender must be Male or Female');
    }
    
    if (isset($input['program_id'])) {
        $stmt = $db->prepare("SELECT program_id FROM programs WHERE program_id = ? AND is_active = 1");
        $stmt->execute([$input['program_id']]);
        if (!$stmt->fetch()) {
            throw new Exception('Invalid program selected');
        }
    }
    
    if (isset($input['class_id'])) {
        $stmt = $db->prepare("SELECT class_id FROM classes WHERE class_id = ? AND is_active = 1");
        $stmt->execute([$input['class_id']]);
        if (!$stmt->fetch()) {
            throw new Exception('Invalid class selected');
        }
    }
    
    // Check if student number is unique (if being updated)
    if (isset($input['student_number']) && $input['student_number'] !== $existing_student['student_number']) {
        $stmt = $db->prepare("SELECT student_id FROM students WHERE student_number = ? AND student_id != ?");
        $stmt->execute([$input['student_number'], $student_id]);
        if ($stmt->fetch()) {
            throw new Exception('Student number already exists');
        }
    }
    
    if (empty($update_fields)) {
        throw new Exception('No valid fields provided for update');
    }
    
    // Add updated_at
    $update_fields[] = "updated_at = NOW()";
    $params[] = $student_id;
    
    $sql = "UPDATE students SET " . implode(', ', $update_fields) . " WHERE student_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('No changes made');
    }
    
    // Get the updated student with related data
    $stmt = $db->prepare("
        SELECT s.*, prog.program_name, cl.class_name,
               CASE 
                   WHEN s.is_verified = 1 THEN 'verified'
                   ELSE 'pending'
               END as verification_status,
               u.first_name as verifier_first_name,
               u.last_name as verifier_last_name
        FROM students s
        LEFT JOIN programs prog ON s.program_id = prog.program_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        LEFT JOIN users u ON s.verified_by = u.user_id
        WHERE s.student_id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $db->commit();
    
    // Log the activity
    logActivity('student_update', "Updated student: {$student['first_name']} {$student['last_name']} ({$student['student_number']})");
    
    echo json_encode([
        'success' => true,
        'message' => 'Student updated successfully',
        'data' => $student
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    
    error_log("Student update API error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>