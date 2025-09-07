<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Set CORS headers if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

try {
    $election_id = intval($_GET['election_id'] ?? 0);
    
    if (!$election_id) {
        throw new Exception('Election ID is required');
    }
    
    $db = Database::getInstance()->getConnection();
    
    // Get positions for the specified election
    $stmt = $db->prepare("
        SELECT 
            p.position_id,
            p.title as position_name,
            p.max_candidates,
            COUNT(c.candidate_id) as current_candidates
        FROM positions p
        LEFT JOIN candidates c ON p.position_id = c.position_id
        WHERE p.election_id = ? AND p.is_active = 1
        GROUP BY p.position_id, p.title, p.max_candidates
        ORDER BY p.title
    ");
    
    $stmt->execute([$election_id]);
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format positions data
    $formatted_positions = [];
    foreach ($positions as $position) {
        $formatted_positions[] = [
            'position_id' => (int)$position['position_id'],
            'position_name' => $position['position_name'],
            'max_candidates' => (int)$position['max_candidates'],
            'current_candidates' => (int)$position['current_candidates'],
            'available_spots' => (int)$position['max_candidates'] - (int)$position['current_candidates']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'positions' => $formatted_positions
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>