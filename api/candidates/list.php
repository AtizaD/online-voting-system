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
    
    // Handle pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(10, min(100, intval($_GET['per_page'] ?? 25)));
    $offset = ($page - 1) * $per_page;
    
    // Handle filters
    $election_filter = $_GET['election_id'] ?? '';
    $position_filter = $_GET['position_id'] ?? '';
    $program_filter = $_GET['program'] ?? '';
    $search = $_GET['search'] ?? '';
    
    // Build WHERE clause
    $where_conditions = ['1 = 1'];
    $params = [];
    
    if ($election_filter) {
        $where_conditions[] = 'c.election_id = ?';
        $params[] = $election_filter;
    }
    
    if ($position_filter) {
        $where_conditions[] = 'c.position_id = ?';
        $params[] = $position_filter;
    }
    
    if ($program_filter) {
        $where_conditions[] = 'prog.program_name = ?';
        $params[] = $program_filter;
    }
    
    if ($search) {
        $where_conditions[] = '(CONCAT(s.first_name, " ", s.last_name) LIKE ? OR s.student_number LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Get total count
    $count_sql = "
        SELECT COUNT(*) 
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        JOIN positions p ON c.position_id = p.position_id
        JOIN elections e ON c.election_id = e.election_id
        LEFT JOIN programs prog ON s.program_id = prog.program_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);
    
    // Get candidates
    $sql = "
        SELECT c.*, 
               s.student_number, s.first_name, s.last_name, s.photo_url as student_photo,
               prog.program_name, cl.class_name,
               p.title as position_name, p.display_order as position_order,
               e.name as election_name, e.status as election_status,
               CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
               (SELECT COUNT(*) FROM votes WHERE candidate_id = c.candidate_id) as actual_vote_count
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        JOIN positions p ON c.position_id = p.position_id
        JOIN elections e ON c.election_id = e.election_id
        LEFT JOIN programs prog ON s.program_id = prog.program_id
        LEFT JOIN classes cl ON s.class_id = cl.class_id
        LEFT JOIN users u ON c.created_by = u.user_id
        $where_clause
        ORDER BY e.name, p.display_order, s.last_name, s.first_name
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $per_page;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get elections for filter options
    $stmt = $db->prepare("
        SELECT election_id, name 
        FROM elections 
        ORDER BY start_date DESC
    ");
    $stmt->execute();
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get positions for filter options (if election is selected)
    $positions = [];
    if ($election_filter) {
        $stmt = $db->prepare("
            SELECT position_id, title 
            FROM positions 
            WHERE election_id = ? AND is_active = 1
            ORDER BY display_order
        ");
        $stmt->execute([$election_filter]);
        $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'data' => $candidates,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total_records' => $total_records,
            'total_pages' => $total_pages,
            'showing_from' => min($offset + 1, $total_records),
            'showing_to' => min($offset + count($candidates), $total_records)
        ],
        'elections' => $elections,
        'positions' => $positions,
        'filters' => [
            'election_id' => $election_filter,
            'position_id' => $position_filter,
            'program' => $program_filter,
            'search' => $search
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Candidates list API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Internal server error'
    ]);
}
?>