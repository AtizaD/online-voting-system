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
            // List/Read elections - accessible by all authenticated users
            handleList();
            break;
            
        case 'POST':
            // Create election - accessible by admin, election-officer
            if (!in_array($current_user['role'], ['admin', 'election-officer'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
                exit;
            }
            handleCreate();
            break;
            
        case 'PUT':
            // Update election - accessible by admin, election-officer
            if (!in_array($current_user['role'], ['admin', 'election-officer'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
                exit;
            }
            handleUpdate();
            break;
            
        case 'DELETE':
            // Delete election - accessible by admin only
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
    global $db, $current_user;
    
    try {
        // Handle filtering
        $status_filter = $_GET['status'] ?? '';
        $election_type_filter = $_GET['type'] ?? '';
        $search = $_GET['search'] ?? '';
        
        // Handle pagination
        $page = max(1, intval($_GET['page'] ?? 1));
        $per_page = max(10, min(100, intval($_GET['per_page'] ?? 25)));
        $offset = ($page - 1) * $per_page;
        
        // Build WHERE clause
        $where_conditions = ['e.is_active = 1'];
        $params = [];
        
        // Role-based filtering
        if ($current_user['role'] === 'election-officer') {
            // Election officers can only see elections they created or are assigned to
            $where_conditions[] = "e.created_by = ?";
            $params[] = $current_user['user_id'];
        }
        
        if (!empty($search)) {
            $where_conditions[] = "(e.name LIKE ? OR e.description LIKE ?)";
            $search_term = "%$search%";
            $params = array_merge($params, [$search_term, $search_term]);
        }
        
        if (!empty($status_filter)) {
            $where_conditions[] = "e.status = ?";
            $params[] = $status_filter;
        }
        
        if (!empty($election_type_filter)) {
            $where_conditions[] = "e.election_type_id = ?";
            $params[] = $election_type_filter;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        
        // Get total count for pagination
        $count_sql = "
            SELECT COUNT(*) as total
            FROM elections e
            LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
            WHERE $where_clause
        ";
        $count_stmt = $db->prepare($count_sql);
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Get elections with pagination
        $sql = "
            SELECT 
                e.election_id,
                e.name,
                e.description,
                e.start_date,
                e.end_date,
                e.status,
                e.created_at,
                e.updated_at,
                et.name as election_type_name,
                COUNT(DISTINCT c.candidate_id) as total_candidates,
                COUNT(DISTINCT v.vote_id) as total_votes
            FROM elections e
            LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
            LEFT JOIN candidates c ON e.election_id = c.election_id
            LEFT JOIN votes v ON e.election_id = v.election_id
            WHERE $where_clause
            GROUP BY e.election_id
            ORDER BY e.created_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get statistics
        $stats_sql = "
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft
            FROM elections e
            WHERE e.is_active = 1
        ";
        
        // Add role filtering to stats
        if ($current_user['role'] === 'election-officer') {
            $stats_sql .= " AND e.created_by = ?";
            $stats_params = [$current_user['user_id']];
        } else {
            $stats_params = [];
        }
        
        $stats_stmt = $db->prepare($stats_sql);
        $stats_stmt->execute($stats_params);
        $statistics = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate pagination info
        $total_pages = ceil($total_records / $per_page);
        
        echo json_encode([
            'success' => true,
            'data' => $elections,
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
                'active' => intval($statistics['active']),
                'completed' => intval($statistics['completed']),
                'draft' => intval($statistics['draft'])
            ]
        ]);
        
    } catch (Exception $e) {
        throw new Exception('Failed to retrieve elections: ' . $e->getMessage());
    }
}

function handleCreate() {
    global $db, $current_user;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Required fields validation
        $required_fields = ['name', 'election_type_id', 'start_date', 'end_date'];
        foreach ($required_fields as $field) {
            if (!isset($input[$field]) || trim($input[$field]) === '') {
                throw new Exception("Missing required field: $field");
            }
        }
        
        // Validate dates
        $start_date = new DateTime($input['start_date']);
        $end_date = new DateTime($input['end_date']);
        $now = new DateTime();
        
        if ($end_date <= $start_date) {
            throw new Exception('End date must be after start date');
        }
        
        if ($start_date < $now) {
            throw new Exception('Start date cannot be in the past');
        }
        
        // Validate election type exists
        $type_stmt = $db->prepare("SELECT election_type_id FROM election_types WHERE election_type_id = ? AND is_active = 1");
        $type_stmt->execute([$input['election_type_id']]);
        if (!$type_stmt->fetch()) {
            throw new Exception('Invalid election type selected');
        }
        
        $db->beginTransaction();
        
        try {
            // Insert election
            $stmt = $db->prepare("
                INSERT INTO elections (
                    name, description, election_type_id, start_date, end_date, 
                    status, created_by, created_at
                ) VALUES (?, ?, ?, ?, ?, 'draft', ?, NOW())
            ");
            
            $stmt->execute([
                $input['name'],
                $input['description'] ?? '',
                $input['election_type_id'],
                $input['start_date'],
                $input['end_date'],
                $current_user['user_id']
            ]);
            
            $election_id = $db->lastInsertId();
            
            // Log activity
            logActivity($current_user['user_id'], 'create', 'elections', $election_id, "Created election: {$input['name']}");
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Election created successfully',
                'election_id' => $election_id
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to create election: ' . $e->getMessage());
    }
}

function handleUpdate() {
    global $db, $current_user;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['election_id'])) {
            throw new Exception('Election ID is required');
        }
        
        // Check if election exists and user has permission
        $check_sql = "SELECT election_id, name, status, created_by FROM elections WHERE election_id = ? AND is_active = 1";
        $check_stmt = $db->prepare($check_sql);
        $check_stmt->execute([$input['election_id']]);
        $existing_election = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing_election) {
            throw new Exception('Election not found');
        }
        
        // Role-based permission check
        if ($current_user['role'] === 'election-officer' && $existing_election['created_by'] != $current_user['user_id']) {
            throw new Exception('You can only update elections you created');
        }
        
        // Prevent updates to active elections (except status changes)
        if ($existing_election['status'] === 'active' && isset($input['start_date'])) {
            throw new Exception('Cannot modify dates of active elections');
        }
        
        $update_fields = [];
        $params = [];
        
        // Build dynamic update query
        $allowed_fields = ['name', 'description', 'start_date', 'end_date', 'status'];
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
        $params[] = $input['election_id'];
        
        $db->beginTransaction();
        
        try {
            $sql = "UPDATE elections SET " . implode(', ', $update_fields) . " WHERE election_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            
            // Log activity
            logActivity($current_user['user_id'], 'update', 'elections', $input['election_id'], "Updated election: {$existing_election['name']}");
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Election updated successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to update election: ' . $e->getMessage());
    }
}

function handleDelete() {
    global $db, $current_user;
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['election_id'])) {
            throw new Exception('Election ID is required');
        }
        
        // Check if election exists
        $check_stmt = $db->prepare("SELECT election_id, name, status FROM elections WHERE election_id = ? AND is_active = 1");
        $check_stmt->execute([$input['election_id']]);
        $election = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$election) {
            throw new Exception('Election not found');
        }
        
        // Prevent deletion of active elections
        if ($election['status'] === 'active') {
            throw new Exception('Cannot delete active elections');
        }
        
        // Check if election has votes (prevent deletion)
        $vote_check = $db->prepare("SELECT COUNT(*) as vote_count FROM votes WHERE election_id = ?");
        $vote_check->execute([$input['election_id']]);
        $vote_count = $vote_check->fetch(PDO::FETCH_ASSOC)['vote_count'];
        
        if ($vote_count > 0) {
            throw new Exception('Cannot delete election with votes. Consider marking as cancelled instead.');
        }
        
        $db->beginTransaction();
        
        try {
            // Soft delete election
            $stmt = $db->prepare("UPDATE elections SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE election_id = ?");
            $stmt->execute([$current_user['user_id'], $input['election_id']]);
            
            // Also soft delete related candidates
            $candidate_stmt = $db->prepare("UPDATE candidates SET is_active = 0 WHERE election_id = ?");
            $candidate_stmt->execute([$input['election_id']]);
            
            // Log activity
            logActivity($current_user['user_id'], 'delete', 'elections', $input['election_id'], "Deleted election: {$election['name']}");
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Election deleted successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } catch (Exception $e) {
        throw new Exception('Failed to delete election: ' . $e->getMessage());
    }
}
?>