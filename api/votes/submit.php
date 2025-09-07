<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../auth/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json');

try {
    // Check if student is logged in - check both session formats for compatibility
    $is_student_logged_in = (
        (isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in'] === true) ||
        (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'student')
    );
    
    if (!$is_student_logged_in) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }

    // Check request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed']);
        exit;
    }

    // Verify CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit;
    }

    $db = Database::getInstance()->getConnection();
    $student_id = $_SESSION['student_id'];
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // SECURITY: Rate limiting - check for too many requests from same student only
    // Note: Removed IP-based limiting since multiple students may use same computer
    $stmt = $db->prepare("
        SELECT COUNT(*) as attempt_count 
        FROM audit_logs 
        WHERE user_id = ? 
        AND action IN ('vote_cast', 'vote_attempt_failed') 
        AND timestamp > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
    ");
    $stmt->execute([$student_id]);
    $recent_attempts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($recent_attempts['attempt_count'] >= 5) {
        // Log suspicious activity for this specific student
        $stmt = $db->prepare("
            INSERT INTO audit_logs (student_id, action, new_values, ip_address, user_agent) 
            VALUES (?, 'security_violation', ?, ?, ?)
        ");
        $stmt->execute([
            $student_id,
            json_encode([
                'type' => 'student_rate_limit_exceeded', 
                'attempts' => $recent_attempts['attempt_count'],
                'note' => 'Multiple failed vote attempts by same student'
            ]),
            $client_ip,
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many voting attempts. Please contact an administrator if this persists.']);
        exit;
    }
    
    // Get and validate election ID
    $election_id = intval($_POST['election_id'] ?? 0);
    if (!$election_id) {
        echo json_encode(['success' => false, 'message' => 'Invalid election ID']);
        exit;
    }

    // Verify election exists and is active
    $stmt = $db->prepare("
        SELECT election_id, name, start_date, end_date,
               CASE 
                   WHEN NOW() < start_date THEN 'upcoming'
                   WHEN NOW() > end_date THEN 'ended'
                   ELSE 'active'
               END as election_status
        FROM elections 
        WHERE election_id = ? AND status != 'cancelled'
    ");
    $stmt->execute([$election_id]);
    $election = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$election || $election['election_status'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Election not active or not found']);
        exit;
    }

    // Check if student has already voted
    $stmt = $db->prepare("
        SELECT session_id 
        FROM voting_sessions 
        WHERE student_id = ? AND election_id = ? AND status = 'completed'
    ");
    $stmt->execute([$student_id, $election_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'You have already voted in this election']);
        exit;
    }

    // Get votes from POST data
    $votes = $_POST['votes'] ?? [];
    if (empty($votes)) {
        echo json_encode(['success' => false, 'message' => 'No votes submitted']);
        exit;
    }

    // SECURITY: Validate that votes don't exceed limits per position
    $position_vote_counts = [];
    foreach ($votes as $position_id => $candidate_votes) {
        if (!is_array($candidate_votes)) {
            $candidate_votes = [$candidate_votes];
        }
        $position_vote_counts[$position_id] = count($candidate_votes);
    }

    // SECURITY: Get ALL positions for this election and verify vote limits
    $stmt = $db->prepare("
        SELECT position_id, title, 1 as max_votes 
        FROM positions 
        WHERE election_id = ? AND is_active = 1
    ");
    $stmt->execute([$election_id]);
    $all_positions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // SECURITY: Validate vote counts against position limits
    foreach ($position_vote_counts as $pos_id => $vote_count) {
        $position_found = false;
        foreach ($all_positions as $pos) {
            if ($pos['position_id'] == $pos_id) {
                $position_found = true;
                if ($vote_count > $pos['max_votes']) {
                    echo json_encode([
                        'success' => false, 
                        'message' => "Security violation: Too many votes for position {$pos['title']} (max: {$pos['max_votes']}, submitted: {$vote_count})"
                    ]);
                    exit;
                }
                break;
            }
        }
        
        if (!$position_found) {
            echo json_encode([
                'success' => false, 
                'message' => "Security violation: Invalid position ID: $pos_id"
            ]);
            exit;
        }
    }

    // SECURITY: Check for duplicate votes to same candidate (should not happen in UI)
    $all_candidate_ids = [];
    foreach ($votes as $position_id => $candidate_votes) {
        if (!is_array($candidate_votes)) {
            $candidate_votes = [$candidate_votes];
        }
        foreach ($candidate_votes as $candidate_id) {
            if (in_array($candidate_id, $all_candidate_ids)) {
                echo json_encode([
                    'success' => false, 
                    'message' => "Security violation: Duplicate vote for candidate ID: $candidate_id"
                ]);
                exit;
            }
            $all_candidate_ids[] = $candidate_id;
        }
    }

    // Start database transaction
    $db->beginTransaction();

    try {
        // Create voting session with additional security info
        $session_token = bin2hex(random_bytes(32));
        $stmt = $db->prepare("
            INSERT INTO voting_sessions (student_id, election_id, session_token, status, ip_address, user_agent) 
            VALUES (?, ?, ?, 'active', ?, ?)
        ");
        $stmt->execute([
            $student_id, 
            $election_id, 
            $session_token,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        $session_id = $db->lastInsertId();

        $total_votes = 0;
        
        // Process votes for each position
        foreach ($votes as $position_id => $candidate_votes) {
            $position_id = intval($position_id);
            
            // Verify position belongs to this election
            $stmt = $db->prepare("
                SELECT position_id, title, 1 as max_votes 
                FROM positions 
                WHERE position_id = ? AND election_id = ? AND is_active = 1
            ");
            $stmt->execute([$position_id, $election_id]);
            $position = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$position) {
                throw new Exception("Invalid position: $position_id");
            }

            // Normalize candidate votes to array
            if (!is_array($candidate_votes)) {
                $candidate_votes = [$candidate_votes];
            }

            // Validate vote count
            if (count($candidate_votes) > $position['max_votes']) {
                throw new Exception("Too many votes for position: " . $position['title']);
            }

            // Cast votes for each selected candidate
            foreach ($candidate_votes as $candidate_id) {
                $candidate_id = intval($candidate_id);
                
                // Verify candidate belongs to this position
                $stmt = $db->prepare("
                    SELECT c.candidate_id, s.first_name, s.last_name
                    FROM candidates c
                    JOIN students s ON c.student_id = s.student_id
                    WHERE c.candidate_id = ? AND c.position_id = ?
                ");
                $stmt->execute([$candidate_id, $position_id]);
                $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$candidate) {
                    throw new Exception("Invalid candidate: $candidate_id for position: " . $position['title']);
                }

                // Insert the vote
                $stmt = $db->prepare("
                    INSERT INTO votes (session_id, position_id, candidate_id, election_id) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$session_id, $position_id, $candidate_id, $election_id]);
                $total_votes++;
            }
        }

        // Mark voting session as completed
        $stmt = $db->prepare("
            UPDATE voting_sessions 
            SET status = 'completed', completed_at = NOW() 
            WHERE session_id = ?
        ");
        $stmt->execute([$session_id]);

        // SECURITY: Log the voting activity with detailed information
        $stmt = $db->prepare("
            INSERT INTO audit_logs (student_id, action, new_values, ip_address, user_agent) 
            VALUES (?, 'vote_cast', ?, ?, ?)
        ");
        $details = json_encode([
            'election_id' => $election_id,
            'election_name' => $election['name'],
            'session_id' => $session_id,
            'total_votes' => $total_votes,
            'positions_voted' => count($votes),
            'candidate_ids' => $all_candidate_ids,
            'timestamp' => date('Y-m-d H:i:s'),
            'session_token' => $session_token
        ]);
        $stmt->execute([
            $student_id,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);

        // Commit transaction
        $db->commit();

        // Clear CSRF token to prevent replay
        unset($_SESSION['csrf_token']);

        echo json_encode([
            'success' => true,
            'message' => 'Vote submitted successfully',
            'data' => [
                'session_id' => $session_id,
                'total_votes' => $total_votes,
                'election' => $election['name']
            ]
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        
        // Log failed attempt for rate limiting purposes
        try {
            $stmt = $db->prepare("
                INSERT INTO audit_logs (student_id, action, new_values, ip_address, user_agent) 
                VALUES (?, 'vote_attempt_failed', ?, ?, ?)
            ");
            $stmt->execute([
                $student_id,
                json_encode([
                    'error' => $e->getMessage(),
                    'election_id' => $election_id,
                    'timestamp' => date('Y-m-d H:i:s')
                ]),
                $client_ip,
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $log_error) {
            // If logging fails, continue anyway
            error_log("Failed to log vote attempt: " . $log_error->getMessage());
        }
        
        // Log the error
        error_log("Vote submission error: " . $e->getMessage() . " - Student ID: $student_id, Election ID: $election_id");
        
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }

} catch (Exception $e) {
    // Log the error
    error_log("Vote submission system error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while processing your vote'
    ]);
}
?>