<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
try {
    requireAuth(['admin', 'election_officer']);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$candidate_id = intval($_GET['id'] ?? 0);

if (!$candidate_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid candidate ID']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get candidate details with all related information
    $stmt = $db->prepare("
        SELECT 
            c.*,
            CONCAT(s.first_name, ' ', s.last_name) as candidate_name,
            s.student_number,
            s.gender,
            s.phone,
            prog.program_name as program,
            cl.class_name as class,
            l.level_name as level,
            e.name as election_name,
            e.start_date,
            e.end_date,
            e.status as election_status,
            p.title as position_name,
            p.description as position_description,
            COUNT(v.vote_id) as vote_count
        FROM candidates c
        JOIN students s ON c.student_id = s.student_id
        JOIN programs prog ON s.program_id = prog.program_id
        JOIN classes cl ON s.class_id = cl.class_id
        JOIN levels l ON cl.level_id = l.level_id
        JOIN elections e ON c.election_id = e.election_id
        JOIN positions p ON c.position_id = p.position_id
        LEFT JOIN votes v ON c.candidate_id = v.candidate_id
        WHERE c.candidate_id = ?
        GROUP BY c.candidate_id
    ");
    
    $stmt->execute([$candidate_id]);
    $candidate = $stmt->fetch();
    
    if (!$candidate) {
        echo json_encode(['success' => false, 'message' => 'Candidate not found']);
        exit;
    }
    
    // Return candidate data
    echo json_encode([
        'success' => true,
        'candidate' => [
            'candidate_id' => $candidate['candidate_id'],
            'candidate_name' => $candidate['candidate_name'],
            'student_number' => $candidate['student_number'],
            'gender' => $candidate['gender'],
            'phone' => $candidate['phone'],
            'program' => $candidate['program'],
            'class' => $candidate['class'],
            'level' => $candidate['level'],
            'election_name' => $candidate['election_name'],
            'start_date' => $candidate['start_date'],
            'end_date' => $candidate['end_date'],
            'election_status' => $candidate['election_status'],
            'position_name' => $candidate['position_name'],
            'position_description' => $candidate['position_description'],
            'vote_count' => intval($candidate['vote_count']),
            'created_at' => $candidate['created_at']
        ]
    ]);
    
} catch (Exception $e) {
    error_log("API candidate view error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Unable to load candidate details']);
}
?>