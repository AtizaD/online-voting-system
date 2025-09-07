<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

try {
    // Check authentication
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication required']);
        exit;
    }
    
    $current_user = getCurrentUser();
    $db = Database::getInstance()->getConnection();
    
    // Check permissions based on method
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'GET':
            // List/Read students - accessible by admin, staff, election-officer
            if (!in_array($current_user['role'], ['admin', 'staff', 'election-officer'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
                exit;
            }
            handleList();
            break;
            
        case 'POST':
            // Create student - accessible by admin, staff
            if (!in_array($current_user['role'], ['admin', 'staff'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
                exit;
            }
            handleCreate();
            break;
            
        case 'PUT':
            // Update student - accessible by admin, staff
            if (!in_array($current_user['role'], ['admin', 'staff'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
                exit;
            }
            handleUpdate();
            break;
            
        case 'DELETE':
            // Delete student - accessible by admin only
            if ($current_user['role'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Admin access required']);
                exit;
            }
            handleDelete();
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

function handleList() {
    global $db;
    
    try {
        // Handle search and filtering
        $search = $_GET['search'] ?? '';
        $program_filter = $_GET['program'] ?? '';
        $class_filter = $_GET['class'] ?? '';
        $status_filter = $_GET['status'] ?? '';
        $student_number = $_GET['student_number'] ?? '';
        
        // Handle pagination
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = max(10, min(100, intval($_GET['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;
        
        // Build WHERE clause
        $where_conditions = ['s.is_active = 1'];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_number LIKE ?)";
            $search_term = "%$search%";
            $params = array_merge($params, [$search_term, $search_term, $search_term]);
        }
        
        if (!empty($student_number)) {
            $where_conditions[] = "s.student_number = ?";
            $params[] = $student_number;
        }
        
        if (!empty($program_filter)) {
            $where_conditions[] = "s.program_id = ?";
            $params[] = $program_filter;
        }
        
        if (!empty($class_filter)) {
            $where_conditions[] = "s.class_id = ?";
            $params[] = $class_filter;
        }
        
        if (!empty($status_filter)) {
            $where_conditions[] = "s.verification_status = ?";
            $params[] = $status_filter;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Get total count for pagination
        $count_sql = "
            SELECT COUNT(*) as total
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.program_id
            LEFT JOIN classes c ON s.class_id = c.class_id
            WHERE $where_clause
        ";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get students with pagination
        $sql = "
            SELECT 
                s.student_id,
                s.student_number,
                s.first_name,
                s.last_name,
                s.gender,
                s.verification_status,
                s.verification_date,
                s.created_at,
                s.updated_at,
                p.program_name,
                c.class_name,
                COALESCE(s.updated_at, s.created_at) as status_date
            FROM students s
            LEFT JOIN programs p ON s.program_id = p.program_id
            LEFT JOIN classes c ON s.class_id = c.class_id
            WHERE $where_clause
            ORDER BY s.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get statistics
        $stats_sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) as verified,
                SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) as pending
            FROM students s
            WHERE s.is_active = 1
        ";
        $stats_stmt = $db->prepare($stats_sql);
        $stats_stmt->execute();
        $statistics = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate pagination info
        $total_pages = ceil($total_records / $per_page);
        
        echo json_encode([
            'success' => true,
            'data' => $students,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $per_page,
                'total_records' => intval($total_records),
                'total_pages' => $total_pages,
                'has_next' => $page < $total_pages,
                'has_prev' => $page > 1
            ],
            'statistics' => [
                'total' => intval($statistics['total']),
                'verified' => intval($statistics['verified']),
                'pending' => intval($statistics['pending'])
            ]
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to retrieve students: ' . $e->getMessage());
    }
}

function handleCreate() {
    global $db, $current_user;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Required fields validation
        $required_fields = ['student_number', 'first_name', 'last_name', 'program_id', 'class_id', 'gender'];
        foreach ($required_fields as $field) {
            if (!isset($input[$field]) || trim($input[$field]) === '') {
                throw new Exception("Missing required field: $field");
            }
        }
        
        // Validate student number uniqueness
        $check_stmt = $db->prepare("SELECT student_id FROM students WHERE student_number = ? AND is_active = 1");
        $check_stmt->execute([$input['student_number']]);
        if ($check_stmt->fetch()) {
            throw new Exception('Student number already exists');
        }
        
        // Validate program exists
        $program_stmt = $db->prepare("SELECT program_id FROM programs WHERE program_id = ? AND is_active = 1");
        $program_stmt->execute([$input['program_id']]);
        if (!$program_stmt->fetch()) {
            throw new Exception('Invalid program selected');
        }
        
        // Validate class exists
        $class_stmt = $db->prepare("SELECT class_id FROM classes WHERE class_id = ? AND is_active = 1");
        $class_stmt->execute([$input['class_id']]);
        if (!$class_stmt->fetch()) {
            throw new Exception('Invalid class selected');
        }
        
        // Validate gender
        if (!in_array($input['gender'], ['Male', 'Female'])) {
            throw new Exception('Invalid gender value');
        }
        
        $db->beginTransaction();
        
        try {
            // Insert student
            $stmt = $db->prepare("
                INSERT INTO students (
                    student_number, first_name, last_name, program_id, class_id, 
                    gender, verification_status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
            ");
            
            $stmt->execute([
                $input['student_number'],
                $input['first_name'],
                $input['last_name'],
                $input['program_id'],
                $input['class_id'],
                $input['gender'],
                $current_user['user_id']
            ]);
            
            $student_id = $db->lastInsertId();
            
            // Log activity
            logActivity($current_user['user_id'], 'create', 'students', $student_id, "Created student: {$input['student_number']}");
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Student created successfully',
                'student_id' => $student_id
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to create student: ' . $e->getMessage());
    }
}

function handleUpdate() {
    global $db, $current_user;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['student_id'])) {
            throw new Exception('Student ID is required');
        }
        
        // Check if student exists
        $check_stmt = $db->prepare("SELECT student_id, student_number FROM students WHERE student_id = ? AND is_active = 1");
        $check_stmt->execute([$input['student_id']]);
        $existing_student = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing_student) {
            throw new Exception('Student not found');
        }
        
        $update_fields = [];
        $params = [];
        
        // Build dynamic update query
        $allowed_fields = ['first_name', 'last_name', 'program_id', 'class_id', 'gender'];
        foreach ($allowed_fields as $field) {
            if (isset($input[$field]) && $input[$field] !== '') {
                $update_fields[] = "$field = ?";
                $params[] = $input[$field];
            }
        }
        
        if (empty($update_fields)) {
            throw new Exception('No fields to update');
        }
        
        // Add updated metadata
        $update_fields[] = "updated_by = ?";
        $update_fields[] = "updated_at = NOW()";
        $params[] = $current_user['user_id'];
        $params[] = $input['student_id'];
        
        $db->beginTransaction();
        
        try {
            $sql = "UPDATE students SET " . implode(', ', $update_fields) . " WHERE student_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            // Log activity
            logActivity($current_user['user_id'], 'update', 'students', $input['student_id'], "Updated student: {$existing_student['student_number']}");
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Student updated successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to update student: ' . $e->getMessage());
    }
}

function handleDelete() {
    global $db, $current_user;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['student_id'])) {
            throw new Exception('Student ID is required');
        }
        
        // Check if student exists
        $check_stmt = $db->prepare("SELECT student_id, student_number FROM students WHERE student_id = ? AND is_active = 1");
        $check_stmt->execute([$input['student_id']]);
        $student = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            throw new Exception('Student not found');
        }
        
        // Check if student has voted (prevent deletion)
        $vote_check = $db->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE student_id = ?");
        $vote_check->execute([$input['student_id']]);
        $vote_count = $vote_check->fetch(PDO::FETCH_ASSOC)['vote_count'];
        
        if ($vote_count > 0) {
            throw new Exception('Cannot delete student who has cast votes. Consider deactivating instead.');
        }
        
        $db->beginTransaction();
        
        try {
            // Soft delete
            $stmt = $db->prepare("UPDATE students SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE student_id = ?");
            $stmt->execute([$current_user['user_id'], $input['student_id']]);
            
            // Log activity
            logActivity($current_user['user_id'], 'delete', 'students', $input['student_id'], "Deleted student: {$student['student_number']}");
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Student deleted successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to delete student: ' . $e->getMessage());
    }
}
?>