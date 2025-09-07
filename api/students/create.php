<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Check authentication - only admin and staff can create students
try {
    requireAuth(['admin', 'staff']);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
    
    // Validate required fields
    $required_fields = ['student_number', 'first_name', 'last_name', 'program_id', 'class_id', 'gender'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || trim($input[$field]) === '') {
            throw new Exception("Missing required field: $field");
        }
    }
    
    // Validate gender
    if (!in_array($input['gender'], ['Male', 'Female'])) {
        throw new Exception('Gender must be Male or Female');
    }
    
    // Validate program and class exist and are active
    $stmt = $db->prepare("SELECT program_id FROM programs WHERE program_id = ? AND is_active = 1");
    $stmt->execute([$input['program_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Invalid program selected');
    }
    
    $stmt = $db->prepare("SELECT class_id FROM classes WHERE class_id = ? AND is_active = 1");
    $stmt->execute([$input['class_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Invalid class selected');
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    // Check if student number already exists
    $stmt = $db->prepare("SELECT student_id FROM students WHERE student_number = ?");
    $stmt->execute([$input['student_number']]);
    if ($stmt->fetch()) {
        throw new Exception('Student number already exists');
    }
    
    // Insert student
    $stmt = $db->prepare("
        INSERT INTO students (
            student_number, first_name, last_name, phone, 
            program_id, class_id, gender, photo_url, 
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        trim($input['student_number']),
        trim($input['first_name']),
        trim($input['last_name']),
        trim($input['phone'] ?? ''),
        $input['program_id'],
        $input['class_id'],
        $input['gender'],
        trim($input['photo_url'] ?? ''),
        $current_user['id']
    ]);
    
    $student_id = $db->lastInsertId();
    
    // Get the created student with related data
    $stmt = $db->prepare("
        SELECT s.*, prog.program_name, cl.class_name,
               'pending' as verification_status
        FROM students s
        LEFT JOIN programs prog ON s.program_id = prog.program_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        WHERE s.student_id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $db->commit();
    
    // Log the activity
    logActivity('student_create', "Created student: {$student['first_name']} {$student['last_name']} ({$student['student_number']})");
    
    echo json_encode([
        'success' => true,
        'message' => 'Student created successfully',
        'data' => $student
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    
    error_log("Student create API error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>