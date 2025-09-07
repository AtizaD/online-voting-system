<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Check authentication
try {
    requireAuth(['admin', 'election_officer', 'staff', 'student']);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $current_user = getCurrentUser();
    
    // Handle pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(10, min(100, intval($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $per_page;
    
    // Handle filters
    $status_filter = $_GET['status'] ?? '';
    $type_filter = $_GET['type'] ?? '';
    $search = $_GET['search'] ?? '';
    
    // Build WHERE clause
    $where_conditions = ['1 = 1']; // Always true condition
    $params = [];
    
    if ($status_filter) {
        $where_conditions[] = 'e.status = ?';
        $params[] = $status_filter;
    }
    
    if ($type_filter) {
        $where_conditions[] = 'et.election_type_id = ?';
        $params[] = $type_filter;
    }
    
    if ($search) {
        $where_conditions[] = '(e.name LIKE ? OR e.description LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    // Students can only see active and completed elections
    if ($current_user['role'] === 'student') {
        $where_conditions[] = "e.status IN ('active', 'completed')";
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Get total count
    $count_sql = "
        SELECT COUNT(*) 
        FROM elections e
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);
    
    // Get elections
    $sql = "
        SELECT e.*, 
               et.name as election_type_name,
               CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
               (SELECT COUNT(*) FROM positions WHERE election_id = e.election_id AND is_active = 1) as total_positions,
               (SELECT COUNT(*) FROM candidates WHERE election_id = e.election_id) as total_candidates,
               (SELECT COUNT(DISTINCT session_id) FROM votes WHERE election_id = e.election_id) as total_voters,
               (SELECT COUNT(*) FROM votes WHERE election_id = e.election_id) as total_votes
        FROM elections e
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        LEFT JOIN users u ON e.created_by = u.user_id
        $where_clause
        ORDER BY 
            CASE e.status 
                WHEN 'active' THEN 1
                WHEN 'draft' THEN 2
                WHEN 'completed' THEN 3
                WHEN 'cancelled' THEN 4
            END,
            e.start_date DESC
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $per_page;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats_sql = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM elections
    ";
    
    // Apply same restrictions for students
    if ($current_user['role'] === 'student') {
        $stats_sql .= " WHERE status IN ('active', 'completed')";
    }
    
    $stmt = $db->prepare($stats_sql);
    $stmt->execute();
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get election types for filter options
    $stmt = $db->prepare("
        SELECT election_type_id, name 
        FROM election_types 
        WHERE is_active = 1 
        ORDER BY name
    ");
    $stmt->execute();
    $election_types = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $elections,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total_records' => $total_records,
            'total_pages' => $total_pages,
            'showing_from' => min($offset + 1, $total_records),
            'showing_to' => min($offset + count($elections), $total_records)
        ],
        'statistics' => $stats,
        'election_types' => $election_types,
        'filters' => [
            'status' => $status_filter,
            'type' => $type_filter,
            'search' => $search
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Elections list API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Internal server error'
    ]);
}
?>