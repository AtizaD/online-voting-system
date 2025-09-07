<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Check if user is authenticated
$is_authenticated = false;
$user_info = null;

try {
    if (isLoggedIn()) {
        $user_info = getCurrentUser();
        $is_authenticated = true;
    }
} catch (Exception $e) {
    // Not authenticated, continue to show public info
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Get system statistics (public info)
    $stats = [];
    
    if ($is_authenticated) {
        // Get detailed stats based on user role
        $user_role = $user_info['role'];
        
        // Students stats
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_students,
                SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) as verified_students,
                COUNT(DISTINCT program_id) as total_programs,
                COUNT(DISTINCT class_id) as total_classes
            FROM students WHERE is_active = 1
        ");
        $stmt->execute();
        $stats['students'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Elections stats
        $election_where = $user_role === 'student' ? "WHERE status IN ('active', 'completed')" : "";
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_elections,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_elections,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_elections,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_elections
            FROM elections $election_where
        ");
        $stmt->execute();
        $stats['elections'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Candidates stats
        $stmt = $db->prepare("
            SELECT COUNT(*) as total_candidates
            FROM candidates c
            JOIN elections e ON c.election_id = e.election_id
            " . ($user_role === 'student' ? "WHERE e.status IN ('active', 'completed')" : "")
        );
        $stmt->execute();
        $stats['candidates'] = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Votes stats
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_votes,
                COUNT(DISTINCT session_id) as total_voters
            FROM votes v
            JOIN elections e ON v.election_id = e.election_id
            " . ($user_role === 'student' ? "WHERE e.status IN ('active', 'completed')" : "")
        );
        $stmt->execute();
        $stats['votes'] = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Available endpoints based on user role
    $endpoints = [
        'public' => [
            'GET /api/' => 'This API overview'
        ]
    ];
    
    if ($is_authenticated) {
        switch ($user_info['role']) {
            case 'admin':
                $endpoints['admin'] = [
                    'Students' => [
                        'GET /api/students/list.php' => 'List students with filtering',
                        'POST /api/students/create.php' => 'Create new student',
                        'PUT /api/students/update.php' => 'Update student information',
                        'DELETE /api/students/delete.php' => 'Delete student',
                        'POST /api/students/verify.php' => 'Verify/unverify student'
                    ],
                    'Elections' => [
                        'GET /api/elections/list.php' => 'List all elections',
                        'POST /api/elections/create.php' => 'Create new election'
                    ],
                    'Candidates' => [
                        'GET /api/candidates/list.php' => 'List all candidates'
                    ],
                    'Results' => [
                        'GET /api/results/election.php' => 'Get election results'
                    ]
                ];
                break;
                
            case 'election_officer':
                $endpoints['election_officer'] = [
                    'Students' => [
                        'GET /api/students/list.php' => 'List students with filtering',
                        'POST /api/students/verify.php' => 'Verify/unverify student'
                    ],
                    'Elections' => [
                        'GET /api/elections/list.php' => 'List all elections',
                        'POST /api/elections/create.php' => 'Create new election'
                    ],
                    'Candidates' => [
                        'GET /api/candidates/list.php' => 'List all candidates'
                    ],
                    'Results' => [
                        'GET /api/results/election.php' => 'Get election results'
                    ]
                ];
                break;
                
            case 'staff':
                $endpoints['staff'] = [
                    'Students' => [
                        'GET /api/students/list.php' => 'List students with filtering',
                        'POST /api/students/create.php' => 'Create new student',
                        'PUT /api/students/update.php' => 'Update student information',
                        'POST /api/students/verify.php' => 'Verify/unverify student'
                    ],
                    'Elections' => [
                        'GET /api/elections/list.php' => 'List all elections'
                    ],
                    'Candidates' => [
                        'GET /api/candidates/list.php' => 'List all candidates'
                    ],
                    'Results' => [
                        'GET /api/results/election.php' => 'Get election results'
                    ]
                ];
                break;
                
            case 'student':
                $endpoints['student'] = [
                    'Elections' => [
                        'GET /api/elections/list.php' => 'List active/completed elections'
                    ],
                    'Candidates' => [
                        'GET /api/candidates/list.php' => 'List candidates'
                    ],
                    'Voting' => [
                        'POST /api/votes/cast.php' => 'Cast votes in election'
                    ],
                    'Results' => [
                        'GET /api/results/election.php' => 'Get public election results'
                    ]
                ];
                break;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'E-Voting System API',
        'version' => '1.0.0',
        'timestamp' => date('Y-m-d H:i:s'),
        'authentication' => [
            'authenticated' => $is_authenticated,
            'user' => $is_authenticated ? [
                'username' => $user_info['username'],
                'role' => $user_info['role'],
                'name' => ($user_info['first_name'] ?? '') . ' ' . ($user_info['last_name'] ?? '')
            ] : null
        ],
        'statistics' => $stats,
        'endpoints' => $endpoints,
        'documentation' => 'See /api/README.md for detailed documentation'
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    error_log("API index error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error'
    ]);
}
?>