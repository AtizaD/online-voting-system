<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Check authentication - only admin and election_officer can create elections
try {
    requireAuth(['admin', 'election_officer']);
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
    
    if ($start_date <= $now) {
        throw new Exception('Start date must be in the future');
    }
    
    if ($end_date <= $start_date) {
        throw new Exception('End date must be after start date');
    }
    
    // Validate election type exists
    $stmt = $db->prepare("SELECT election_type_id FROM election_types WHERE election_type_id = ? AND is_active = 1");
    $stmt->execute([$input['election_type_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Invalid election type selected');
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    // Check for overlapping elections of same type
    $stmt = $db->prepare("
        SELECT election_id, name 
        FROM elections 
        WHERE election_type_id = ? 
        AND status IN ('active', 'draft')
        AND (
            (start_date <= ? AND end_date >= ?) OR
            (start_date <= ? AND end_date >= ?) OR
            (start_date >= ? AND end_date <= ?)
        )
    ");
    $stmt->execute([
        $input['election_type_id'],
        $input['start_date'], $input['start_date'],
        $input['end_date'], $input['end_date'],
        $input['start_date'], $input['end_date']
    ]);
    
    if ($stmt->fetch()) {
        throw new Exception('Another election of this type is scheduled during this period');
    }
    
    // Set defaults for optional fields
    $max_votes_per_position = intval($input['max_votes_per_position'] ?? 1);
    $allow_abstain = isset($input['allow_abstain']) ? (bool)$input['allow_abstain'] : true;
    $require_all_positions = isset($input['require_all_positions']) ? (bool)$input['require_all_positions'] : false;
    $results_public = isset($input['results_public']) ? (bool)$input['results_public'] : true;
    
    // Insert election
    $stmt = $db->prepare("
        INSERT INTO elections (
            name, description, election_type_id, start_date, end_date,
            max_votes_per_position, allow_abstain, require_all_positions, results_public,
            created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([
        trim($input['name']),
        trim($input['description'] ?? ''),
        $input['election_type_id'],
        $input['start_date'],
        $input['end_date'],
        $max_votes_per_position,
        $allow_abstain,
        $require_all_positions,
        $results_public,
        $current_user['id']
    ]);
    
    $election_id = $db->lastInsertId();
    
    // Get the created election with related data
    $stmt = $db->prepare("
        SELECT e.*, 
               et.name as election_type_name,
               CONCAT(u.first_name, ' ', u.last_name) as created_by_name
        FROM elections e
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        LEFT JOIN users u ON e.created_by = u.user_id
        WHERE e.election_id = ?
    ");
    $stmt->execute([$election_id]);
    $election = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $db->commit();
    
    // Log the activity
    logActivity('election_create', "Created election: {$election['name']}");
    
    echo json_encode([
        'success' => true,
        'message' => 'Election created successfully',
        'data' => $election
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    
    error_log("Election create API error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>