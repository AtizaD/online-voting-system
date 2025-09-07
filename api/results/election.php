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
    
    // Get election ID from URL parameter
    $election_id = intval($_GET['election_id'] ?? 0);
    
    if (!$election_id) {
        throw new Exception('Election ID is required');
    }
    
    // Get election details
    $stmt = $db->prepare("
        SELECT e.*, et.name as election_type_name,
               CONCAT(u.first_name, ' ', u.last_name) as created_by_name
        FROM elections e
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        LEFT JOIN users u ON e.created_by = u.user_id
        WHERE e.election_id = ?
    ");
    $stmt->execute([$election_id]);
    $election = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$election) {
        throw new Exception('Election not found');
    }
    
    // Check if results are public for students
    if ($current_user['role'] === 'student' && !$election['results_public']) {
        throw new Exception('Election results are not yet public');
    }
    
    // Get overall voting statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT vs.student_id) as total_voters,
            COUNT(DISTINCT v.vote_id) as total_votes,
            COUNT(DISTINCT av.abstain_id) as total_abstains,
            (SELECT COUNT(*) FROM students WHERE is_verified = 1 AND is_active = 1) as eligible_voters
        FROM voting_sessions vs
        LEFT JOIN votes v ON vs.session_id = v.session_id
        LEFT JOIN abstain_votes av ON vs.session_id = av.session_id
        WHERE vs.election_id = ? AND vs.status = 'completed'
    ");
    $stmt->execute([$election_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate turnout percentage
    $stats['turnout_percentage'] = $stats['eligible_voters'] > 0 
        ? round(($stats['total_voters'] / $stats['eligible_voters']) * 100, 2)
        : 0;
    
    // Get positions with results
    $stmt = $db->prepare("
        SELECT p.position_id, p.title, p.description, p.display_order,
               COUNT(DISTINCT c.candidate_id) as total_candidates,
               COALESCE(SUM(c.vote_count), 0) as total_position_votes,
               (SELECT COUNT(*) FROM abstain_votes WHERE position_id = p.position_id AND election_id = ?) as abstain_count
        FROM positions p
        LEFT JOIN candidates c ON p.position_id = c.position_id AND c.election_id = ?
        WHERE p.election_id = ? AND p.is_active = 1
        GROUP BY p.position_id, p.title, p.description, p.display_order
        ORDER BY p.display_order
    ");
    $stmt->execute([$election_id, $election_id, $election_id]);
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get candidates with results for each position
    foreach ($positions as &$position) {
        $stmt = $db->prepare("
            SELECT c.candidate_id, c.vote_count, c.photo_url,
                   s.student_number, s.first_name, s.last_name, s.photo_url as student_photo,
                   prog.program_name, cl.class_name,
                   (SELECT COUNT(*) FROM votes WHERE candidate_id = c.candidate_id) as actual_vote_count,
                   CASE 
                       WHEN ? > 0 THEN ROUND((c.vote_count / ?) * 100, 2)
                       ELSE 0 
                   END as vote_percentage
            FROM candidates c
            JOIN students s ON c.student_id = s.student_id
            LEFT JOIN programs prog ON s.program_id = prog.program_id
            LEFT JOIN classes cl ON s.class_id = cl.class_id
            WHERE c.position_id = ? AND c.election_id = ?
            ORDER BY c.vote_count DESC, s.last_name, s.first_name
        ");
        $stmt->execute([
            $position['total_position_votes'],
            $position['total_position_votes'],
            $position['position_id'],
            $election_id
        ]);
        $position['candidates'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add ranking
        $rank = 1;
        $prev_votes = null;
        foreach ($position['candidates'] as &$candidate) {
            if ($prev_votes !== null && $candidate['vote_count'] < $prev_votes) {
                $rank++;
            }
            $candidate['rank'] = $rank;
            $candidate['is_winner'] = ($rank === 1 && $candidate['vote_count'] > 0);
            $prev_votes = $candidate['vote_count'];
        }
    }
    
    // Get voting timeline (hourly breakdown for active election monitoring)
    $timeline = [];
    if (in_array($election['status'], ['active', 'completed'])) {
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(vote_timestamp, '%Y-%m-%d %H:00:00') as hour,
                COUNT(*) as votes_count
            FROM votes 
            WHERE election_id = ?
            GROUP BY DATE_FORMAT(vote_timestamp, '%Y-%m-%d %H:00:00')
            ORDER BY hour
        ");
        $stmt->execute([$election_id]);
        $timeline = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'election' => $election,
            'statistics' => $stats,
            'positions' => $positions,
            'timeline' => $timeline
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Election results API error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>