<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Check authentication
try {
    requireAuth(['admin', 'election_officer', 'staff']);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Handle pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(10, min(15000, intval($_GET['per_page'] ?? 25))); // Increased limit for reports
    $offset = ($page - 1) * $per_page;
    
    // Handle filters
    $status_filter = $_GET['status'] ?? '';
    $program_filter = $_GET['program'] ?? '';
    $class_filter = $_GET['class'] ?? '';
    $search = $_GET['search'] ?? '';
    $student_number = $_GET['student_number'] ?? '';
    
    // Build WHERE clause
    $where_conditions = ['s.is_active = 1'];
    $params = [];
    
    if ($status_filter) {
        if ($status_filter === 'verified') {
            $where_conditions[] = 's.is_verified = 1';
        } elseif ($status_filter === 'pending') {
            $where_conditions[] = 's.is_verified = 0';
        }
    }
    
    if ($program_filter) {
        $where_conditions[] = 'prog.program_name = ?';
        $params[] = $program_filter;
    }
    
    if ($class_filter) {
        $where_conditions[] = 'cl.class_name = ?';
        $params[] = $class_filter;
    }
    
    // Handle student number search (exact match)
    if ($student_number) {
        $where_conditions[] = 's.student_number = ?';
        $params[] = $student_number;
    }
    
    // Handle general search (name and student number with LIKE)
    if ($search) {
        $where_conditions[] = '(CONCAT(s.first_name, " ", s.last_name) LIKE ? OR s.student_number LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Get total count
    $count_sql = "
        SELECT COUNT(*) 
        FROM students s
        LEFT JOIN programs prog ON s.program_id = prog.program_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);
    
    // Get students
    $sql = "
        SELECT s.*, prog.program_name, cl.class_name,
               CASE 
                   WHEN s.is_verified = 1 THEN s.verified_at
                   ELSE s.created_at 
               END as status_date,
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
        $where_clause
        ORDER BY 
            CASE s.is_verified 
                WHEN 0 THEN 1 
                WHEN 1 THEN 2 
            END,
            s.created_at DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $per_page;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats_sql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_verified = 0 THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified
        FROM students WHERE is_active = 1
    ";
    $stmt = $db->prepare($stats_sql);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $students,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total_records' => $total_records,
            'total_pages' => $total_pages,
            'showing_from' => min($offset + 1, $total_records),
            'showing_to' => min($offset + count($students), $total_records)
        ],
        'statistics' => $stats,
        'filters' => [
            'status' => $status_filter,
            'program' => $program_filter,
            'class' => $class_filter,
            'search' => $search
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Students list API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Internal server error'
    ]);
}
?>