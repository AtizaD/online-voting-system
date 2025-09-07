<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

// Check authentication - only students can vote
try {
    requireAuth(['student']);
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
    
    // Handle JSON input
    $json_input = json_decode(file_get_contents('php://input'), true);
    $input = $json_input ?: $_POST;
    
    // Validate required fields
    if (!isset($input['election_id']) || !intval($input['election_id'])) {
        throw new Exception('Election ID is required');
    }
    
    if (!isset($input['votes']) || !is_array($input['votes'])) {
        throw new Exception('Votes array is required');
    }
    
    $election_id = intval($input['election_id']);
    $votes = $input['votes'];
    
    // Get student info
    $stmt = $db->prepare("SELECT student_id FROM students WHERE student_number = ? AND is_verified = 1 AND is_active = 1");
    $stmt->execute([$current_user['username']]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found or not verified');
    }
    
    $student_id = $student['student_id'];
    
    // Begin transaction
    $db->beginTransaction();
    
    // Check if election exists and is active
    $stmt = $db->prepare("SELECT * FROM elections WHERE election_id = ? AND status = 'active' AND start_date <= NOW() AND end_date >= NOW()");
    $stmt->execute([$election_id]);
    $election = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$election) {
        throw new Exception('Election not found, not active, or voting period has ended');
    }
    
    // Check if student has already voted
    $stmt = $db->prepare("SELECT session_id FROM voting_sessions WHERE student_id = ? AND election_id = ?");
    $stmt->execute([$student_id, $election_id]);
    $existing_session = $stmt->fetch();
    
    if ($existing_session) {
        throw new Exception('You have already voted in this election');
    }
    
    // Create voting session
    $session_token = bin2hex(random_bytes(32));
    $stmt = $db->prepare("
        INSERT INTO voting_sessions (student_id, election_id, session_token, ip_address, user_agent, started_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $student_id, 
        $election_id, 
        $session_token, 
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
    
    $session_id = $db->lastInsertId();
    
    // Validate and cast votes
    $votes_cast = 0;
    $position_votes = [];
    
    foreach ($votes as $vote) {
        if (!isset($vote['position_id']) || !isset($vote['candidate_id'])) {
            throw new Exception('Each vote must have position_id and candidate_id');
        }
        
        $position_id = intval($vote['position_id']);
        $candidate_id = intval($vote['candidate_id']);
        
        // Handle abstain votes
        if ($candidate_id === 0 || $candidate_id === -1) {
            if (!$election['allow_abstain']) {
                throw new Exception('Abstaining is not allowed in this election');
            }
            
            // Record abstain vote
            $stmt = $db->prepare("
                INSERT INTO abstain_votes (position_id, election_id, session_id, vote_timestamp) 
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$position_id, $election_id, $session_id]);
            
        } else {
            // Validate candidate exists and is for this election/position
            $stmt = $db->prepare("
                SELECT c.candidate_id 
                FROM candidates c 
                JOIN positions p ON c.position_id = p.position_id
                WHERE c.candidate_id = ? AND c.election_id = ? AND c.position_id = ? AND p.is_active = 1
            ");
            $stmt->execute([$candidate_id, $election_id, $position_id]);
            
            if (!$stmt->fetch()) {
                throw new Exception("Invalid candidate selection for position $position_id");
            }
            
            // Check vote limits per position
            if (!isset($position_votes[$position_id])) {
                $position_votes[$position_id] = 0;
            }
            $position_votes[$position_id]++;
            
            if ($position_votes[$position_id] > $election['max_votes_per_position']) {
                throw new Exception("Too many votes for position $position_id (max: {$election['max_votes_per_position']})");
            }
            
            // Generate verification code
            $verification_code = substr(hash('sha256', $session_token . $candidate_id . time()), 0, 8);
            
            // Cast vote
            $stmt = $db->prepare("
                INSERT INTO votes (candidate_id, position_id, election_id, session_id, ip_address, vote_timestamp, verification_code) 
                VALUES (?, ?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $candidate_id, 
                $position_id, 
                $election_id, 
                $session_id, 
                $_SERVER['REMOTE_ADDR'] ?? null,
                $verification_code
            ]);
            
            // Update candidate vote count cache
            $stmt = $db->prepare("UPDATE candidates SET vote_count = vote_count + 1 WHERE candidate_id = ?");
            $stmt->execute([$candidate_id]);
        }
        
        $votes_cast++;
    }
    
    // Complete voting session
    $stmt = $db->prepare("UPDATE voting_sessions SET completed_at = NOW(), status = 'completed', votes_cast = ? WHERE session_id = ?");
    $stmt->execute([$votes_cast, $session_id]);
    
    $db->commit();
    
    // Log the activity
    logActivity('vote_cast', "Student voted in election: {$election['name']} ($votes_cast votes)", $student_id);
    
    echo json_encode([
        'success' => true,
        'message' => 'Votes cast successfully',
        'data' => [
            'session_id' => $session_id,
            'votes_cast' => $votes_cast,
            'session_token' => $session_token
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    
    error_log("Vote cast API error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>