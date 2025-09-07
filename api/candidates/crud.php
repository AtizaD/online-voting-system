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
            // List/Read candidates - accessible by all authenticated users
            handleList();
            break;
            
        case 'POST':
            // Create candidate - accessible by admin, election-officer
            if (!in_array($current_user['role'], ['admin', 'election-officer'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
                exit;
            }
            handleCreate();
            break;
            
        case 'PUT':
            // Update candidate - accessible by admin, election-officer
            if (!in_array($current_user['role'], ['admin', 'election-officer'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
                exit;
            }
            handleUpdate();
            break;
            
        case 'DELETE':
            // Delete candidate - accessible by admin, election-officer
            if (!in_array($current_user['role'], ['admin', 'election-officer'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
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
    global $db, $current_user;
    
    try {
        // Handle filtering
        $election_filter = $_GET['election_id'] ?? '';
        $position_filter = $_GET['position_id'] ?? '';
        $search = $_GET['search'] ?? '';
        
        // Handle pagination
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = max(10, min(100, intval($_GET['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;
        
        // Build WHERE clause  
        $where_conditions = ['1=1'];
        $params = [];
        
        if (!empty($search)) {
            $where_conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR c.slogan LIKE ?)";
            $search_term = "%$search%";
            $params = array_merge($params, [$search_term, $search_term, $search_term]);
        }
        
        if (!empty($election_filter)) {
            $where_conditions[] = "c.election_id = ?";
            $params[] = $election_filter;
        }
        
        if (!empty($position_filter)) {
            $where_conditions[] = "c.position_id = ?";
            $params[] = $position_filter;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Get total count for pagination
        $count_sql = "
            SELECT COUNT(*) as total
            FROM candidates c
            LEFT JOIN students s ON c.student_id = s.student_id
            LEFT JOIN elections e ON c.election_id = e.election_id
            LEFT JOIN positions p ON c.position_id = p.position_id
            WHERE $where_clause
        ";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get candidates with pagination
        $sql = "
            SELECT 
                c.candidate_id,
                c.slogan,
                c.photo_path,
                c.status,
                c.created_at,
                s.student_id,
                s.student_number,
                s.first_name,
                s.last_name,
                s.program_id,
                p.program_name,
                cl.class_name,
                e.election_id,
                e.name as election_name,
                pos.position_id,
                pos.name as position_name,
                COUNT(v.vote_id) as vote_count
            FROM candidates c
            LEFT JOIN students s ON c.student_id = s.student_id
            LEFT JOIN programs p ON s.program_id = p.program_id
            LEFT JOIN classes cl ON s.class_id = cl.class_id
            LEFT JOIN elections e ON c.election_id = e.election_id
            LEFT JOIN positions pos ON c.position_id = pos.position_id
            LEFT JOIN votes v ON c.candidate_id = v.candidate_id
            WHERE $where_clause
            GROUP BY c.candidate_id
            ORDER BY e.name, pos.name, s.last_name
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get statistics
        $stats_sql = "
            SELECT 
                COUNT(*) as total,
                COUNT(DISTINCT c.election_id) as elections,
                COUNT(DISTINCT c.position_id) as positions
            FROM candidates c
        ";
        $stats_stmt = $db->prepare($stats_sql);
        $stats_stmt->execute();
        $statistics = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate pagination info
        $total_pages = ceil($total_records / $per_page);
        
        echo json_encode([
            'success' => true,
            'data' => $candidates,
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
                'elections' => intval($statistics['elections']),
                'positions' => intval($statistics['positions'])
            ]
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to retrieve candidates: ' . $e->getMessage());
    }
}

function handleCreate() {
    global $db, $current_user;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Required fields validation
        $required_fields = ['student_id', 'election_id', 'position_id'];
        foreach ($required_fields as $field) {
            if (!isset($input[$field]) || trim($input[$field]) === '') {
                throw new Exception("Missing required field: $field");
            }
        }
        
        // Validate student exists and is verified
        $student_stmt = $db->prepare("SELECT student_id, first_name, last_name, is_verified FROM students WHERE student_id = ? AND is_active = 1");
        $student_stmt->execute([$input['student_id']]);
        $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            throw new Exception('Student not found');
        }
        
        if (!$student['is_verified']) {
            throw new Exception('Only verified students can be candidates');
        }
        
        // Validate election exists
        $election_stmt = $db->prepare("SELECT election_id, name, status FROM elections WHERE election_id = ? AND is_active = 1");
        $election_stmt->execute([$input['election_id']]);
        $election = $election_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$election) {
            throw new Exception('Election not found');
        }
        
        if ($election['status'] === 'completed') {
            throw new Exception('Cannot add candidates to completed elections');
        }
        
        // Validate position exists
        $position_stmt = $db->prepare("SELECT position_id, name FROM positions WHERE position_id = ? AND is_active = 1");
        $position_stmt->execute([$input['position_id']]);
        $position = $position_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$position) {
            throw new Exception('Position not found');
        }
        
        // Check if student is already a candidate in this election
        $duplicate_check = $db->prepare("
            SELECT candidate_id FROM candidates 
            WHERE student_id = ? AND election_id = ? AND is_active = 1
        ");
        $duplicate_check->execute([$input['student_id'], $input['election_id']]);
        if ($duplicate_check->fetch()) {
            throw new Exception('Student is already a candidate in this election');
        }
        
        $db->beginTransaction();
        
        try {
            // Insert candidate
            $stmt = $db->prepare("
                INSERT INTO candidates (
                    student_id, election_id, position_id, slogan, 
                    status, created_by, created_at
                ) VALUES (?, ?, ?, ?, 'pending', ?, NOW())
            ");
            
            $stmt->execute([
                $input['student_id'],
                $input['election_id'],
                $input['position_id'],
                $input['slogan'] ?? '',
                $current_user['user_id']
            ]);
            
            $candidate_id = $db->lastInsertId();
            
            // Log activity
            logActivity($current_user['user_id'], 'create', 'candidates', $candidate_id, "Created candidate: {$student['first_name']} {$student['last_name']} for {$election['name']}");
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Candidate created successfully',
                'candidate_id' => $candidate_id
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to create candidate: ' . $e->getMessage());
    }
}

function handleUpdate() {
    global $db, $current_user;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['candidate_id'])) {
            throw new Exception('Candidate ID is required');
        }
        
        // Check if candidate exists
        $check_stmt = $db->prepare("
            SELECT c.candidate_id, c.status, s.first_name, s.last_name, e.name as election_name
            FROM candidates c
            LEFT JOIN students s ON c.student_id = s.student_id
            LEFT JOIN elections e ON c.election_id = e.election_id
            WHERE c.candidate_id = ?
        ");
        $check_stmt->execute([$input['candidate_id']]);
        $existing_candidate = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing_candidate) {
            throw new Exception('Candidate not found');
        }
        
        $update_fields = [];
        $params = [];
        
        // Build dynamic update query
        $allowed_fields = ['slogan', 'photo_path', 'status'];
        foreach ($allowed_fields as $field) {
            if (isset($input[$field])) {
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
        $params[] = $input['candidate_id'];
        
        $db->beginTransaction();
        
        try {
            $sql = "UPDATE candidates SET " . implode(', ', $update_fields) . " WHERE candidate_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            // Log activity
            logActivity($current_user['user_id'], 'update', 'candidates', $input['candidate_id'], "Updated candidate: {$existing_candidate['first_name']} {$existing_candidate['last_name']} in {$existing_candidate['election_name']}");
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Candidate updated successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to update candidate: ' . $e->getMessage());
    }
}

function handleDelete() {
    global $db, $current_user;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['candidate_id'])) {
            throw new Exception('Candidate ID is required');
        }
        
        // Check if candidate exists
        $check_stmt = $db->prepare("
            SELECT c.candidate_id, s.first_name, s.last_name, e.name as election_name, e.status
            FROM candidates c
            LEFT JOIN students s ON c.student_id = s.student_id
            LEFT JOIN elections e ON c.election_id = e.election_id
            WHERE c.candidate_id = ?
        ");
        $check_stmt->execute([$input['candidate_id']]);
        $candidate = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$candidate) {
            throw new Exception('Candidate not found');
        }
        
        // Prevent deletion from active elections
        if ($candidate['status'] === 'active') {
            throw new Exception('Cannot delete candidates from active elections');
        }
        
        // Check if candidate has votes (prevent deletion)
        $vote_check = $db->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE candidate_id = ?");
        $vote_check->execute([$input['candidate_id']]);
        $vote_count = $vote_check->fetch(PDO::FETCH_ASSOC)['vote_count'];
        
        if ($vote_count > 0) {
            throw new Exception('Cannot delete candidate who has received votes');
        }
        
        $db->beginTransaction();
        
        try {
            // Soft delete candidate
            $stmt = $db->prepare("UPDATE candidates SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE candidate_id = ?");
            $stmt->execute([$current_user['user_id'], $input['candidate_id']]);
            
            // Log activity
            logActivity($current_user['user_id'], 'delete', 'candidates', $input['candidate_id'], "Deleted candidate: {$candidate['first_name']} {$candidate['last_name']} from {$candidate['election_name']}");
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Candidate deleted successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to delete candidate: ' . $e->getMessage());
    }
}
?>