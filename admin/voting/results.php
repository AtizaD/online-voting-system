<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || !hasPermission('view_results')) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get and validate election ID from URL parameter
$election_id = filter_input(INPUT_GET, 'election_id', FILTER_VALIDATE_INT);
$election_id = $election_id > 0 ? $election_id : null;

// Get elections with voter counts
try {
    $stmt = $db->query("
    SELECT e.election_id, e.name, e.status, e.start_date, e.end_date, e.results_published_at,
           COALESCE(vs.voter_count, 0) as voter_count
    FROM elections e
    LEFT JOIN (
        SELECT election_id, COUNT(DISTINCT student_id) as voter_count
        FROM voting_sessions 
        WHERE status = 'completed'
        GROUP BY election_id
    ) vs ON e.election_id = vs.election_id
    WHERE e.status IN ('active', 'completed', 'cancelled', 'draft')
    ORDER BY e.start_date DESC
");
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Database error in results.php: ' . $e->getMessage());
    $elections = [];
}

// If no election selected, use the most recent active/completed one
if (!$election_id && !empty($elections)) {
    foreach ($elections as $election) {
        if (in_array($election['status'], ['completed', 'active'])) {
            $election_id = $election['election_id'];
            break;
        }
    }
    if (!$election_id) {
        $election_id = $elections[0]['election_id'];
    }
}

$election_data = null;
$results = [];
$statistics = [];
$timeline_data = [];

if ($election_id) {
    // Get election details with type
    try {
        $stmt = $db->prepare("
        SELECT e.election_id, e.name, e.description, e.start_date, e.end_date, 
               e.status, e.results_published_at, e.created_at, e.updated_at,
               et.name as election_type_name
        FROM elections e 
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        WHERE e.election_id = ?
        ");
        $stmt->execute([$election_id]);
        $election_data = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Election data fetch error: ' . $e->getMessage());
        $election_data = null;
    }

    if ($election_data) {
        // Get all positions for this election
        try {
            $positions_stmt = $db->prepare("
            SELECT position_id, title, description, display_order
            FROM positions 
            WHERE election_id = ? AND is_active = 1 
            ORDER BY display_order, position_id
            ");
            $positions_stmt->execute([$election_id]);
            $all_positions = $positions_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Database error in results.php: ' . $e->getMessage());
            $all_positions = [];
        }

        // Get total voters count
        $total_voters_stmt = $db->prepare("
            SELECT COUNT(DISTINCT student_id) as total_voters
            FROM voting_sessions 
            WHERE election_id = ? AND status = 'completed'
        ");
        $total_voters_stmt->execute([$election_id]);
        $total_voters_result = $total_voters_stmt->fetch();
        $total_voters = $total_voters_result['total_voters'] ?? 0;

        // Initialize results array with all positions
        $results = [];
        foreach ($all_positions as $position) {
            $results[$position['position_id']] = [
                'position_title' => $position['title'],
                'position_description' => $position['description'],
                'display_order' => (int)$position['display_order'],
                'total_voters' => $total_voters,
                'candidates' => []
            ];
        }

        // Get candidates with vote counts using the same structure as export-pdf.php
        $candidates_stmt = $db->prepare("
            SELECT 
                p.position_id,
                c.candidate_id,
                c.student_id,
                c.photo_url,
                s.first_name,
                s.last_name,
                s.student_number,
                prog.program_name,
                cl.class_name,
                COALESCE(vote_counts.vote_count, 0) as vote_count
            FROM positions p
            LEFT JOIN candidates c ON p.position_id = c.position_id
            LEFT JOIN students s ON c.student_id = s.student_id
            LEFT JOIN programs prog ON s.program_id = prog.program_id
            LEFT JOIN classes cl ON s.class_id = cl.class_id
            LEFT JOIN (
                SELECT 
                    v.candidate_id, 
                    COUNT(*) as vote_count
                FROM votes v
                JOIN voting_sessions vs ON v.session_id = vs.session_id
                WHERE vs.election_id = ? AND vs.status = 'completed'
                GROUP BY v.candidate_id
            ) vote_counts ON c.candidate_id = vote_counts.candidate_id
            WHERE p.election_id = ? AND p.is_active = 1
            ORDER BY p.display_order, p.position_id, vote_count DESC, s.first_name, s.last_name
        ");
        $candidates_stmt->execute([$election_id, $election_id]);
        $candidates_results = $candidates_stmt->fetchAll();

        // Add candidates to their respective positions (matching export-pdf.php logic)
        foreach ($candidates_results as $candidate) {
            $position_id = $candidate['position_id'];
            
            // Only add if candidate exists (candidate_id is not null)
            if ($candidate['candidate_id']) {
                $results[$position_id]['candidates'][] = [
                    'candidate_id' => $candidate['candidate_id'],
                    'student_id' => $candidate['student_id'],
                    'name' => trim($candidate['first_name'] . ' ' . $candidate['last_name']),
                    'student_number' => $candidate['student_number'],
                    'photo_url' => $candidate['photo_url'],
                    'program_name' => $candidate['program_name'] ?? 'N/A',
                    'class_name' => $candidate['class_name'] ?? 'N/A',
                    'vote_count' => (int)$candidate['vote_count']
                ];
            }
        }
        // Calculate percentages and determine winners
        foreach ($results as $position_id => &$position) {
            $total_votes = array_sum(array_column($position['candidates'], 'vote_count'));
            $position['total_votes'] = $total_votes;
            $position['participation_rate'] = $position['total_voters'] > 0 
                ? round(($total_votes / $position['total_voters']) * 100, 2)
                : 0;

            // Find highest vote count
            $highest_votes = 0;
            foreach ($position['candidates'] as &$candidate) {
                $candidate['percentage'] = $total_votes > 0 
                    ? round(($candidate['vote_count'] / $total_votes) * 100, 2) 
                    : 0;
                if ($candidate['vote_count'] > $highest_votes) {
                    $highest_votes = $candidate['vote_count'];
                }
            }

            // Mark winners and calculate vote margins
            $winners = array_filter($position['candidates'], function($c) use ($highest_votes) {
                return $c['vote_count'] == $highest_votes && $highest_votes > 0;
            });
            
            foreach ($position['candidates'] as &$candidate) {
                $candidate['is_winner'] = ($candidate['vote_count'] == $highest_votes && $highest_votes > 0);
                $candidate['margin'] = $highest_votes > 0 ? $highest_votes - $candidate['vote_count'] : 0;
                $candidate['is_close'] = $candidate['margin'] <= 5 && $candidate['margin'] > 0;
            }
            unset($candidate); // Break reference to prevent memory issues
            
            $position['is_tie'] = count($winners) > 1;
            $position['is_contested'] = count($position['candidates']) > 1;
        }
        unset($position); // Break reference to prevent memory issues

        // Sort positions by display order (matching export-pdf.php logic)
        uasort($results, function($a, $b) {
            return $a['display_order'] <=> $b['display_order'];
        });
        // Get comprehensive election statistics
        $stmt = $db->prepare("
            SELECT 
                COUNT(DISTINCT vs.student_id) as total_voters,
                COUNT(v.vote_id) as total_votes,
                (SELECT COUNT(*) FROM students WHERE is_active = 1 AND is_verified = 1) as total_eligible,
                (SELECT COUNT(*) FROM positions WHERE election_id = ? AND is_active = 1) as total_positions,
                MIN(vs.started_at) as first_vote,
                MAX(vs.completed_at) as last_vote,
                ROUND(AVG(vs.votes_cast), 1) as avg_votes_per_voter
            FROM voting_sessions vs
            LEFT JOIN votes v ON vs.session_id = v.session_id
            WHERE vs.election_id = ? AND vs.status = 'completed'
        ");
        $stmt->execute([$election_id, $election_id]);
        $statistics = $stmt->fetch();

        // Get daily voting timeline
        $stmt = $db->prepare("
            SELECT DATE(completed_at) as vote_date, 
                   COUNT(DISTINCT student_id) as daily_voters
            FROM voting_sessions 
            WHERE election_id = ? AND status = 'completed'
            GROUP BY DATE(completed_at)
            ORDER BY vote_date ASC
        ");
        $stmt->execute([$election_id]);
        $timeline_data = $stmt->fetchAll();
    }
}

// Handle result publishing
if ($_POST && isset($_POST['publish_results']) && hasPermission('publish_results') && $election_id) {
    try {
        $db->beginTransaction();
        
        // Update election status and publish timestamp
        $stmt = $db->prepare("
            UPDATE elections 
            SET results_published_at = CURRENT_TIMESTAMP,
                status = CASE WHEN status = 'active' THEN 'completed' ELSE status END,
                updated_at = CURRENT_TIMESTAMP
            WHERE election_id = ?
        ");
        $stmt->execute([$election_id]);
        
        // Log result publication
        $stmt = $db->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, ip_address, user_agent, created_at)
            VALUES (?, 'publish_results', 'elections', ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $current_user['id'],
            $election_id,
            json_encode([
                'election_name' => $election_data['name'],
                'published_by' => $current_user['first_name'] . ' ' . $current_user['last_name'],
                'total_voters' => $statistics['total_voters'] ?? 0,
                'total_votes' => $statistics['total_votes'] ?? 0
            ]),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
        
        $db->commit();
        $_SESSION['success_message'] = "Results published successfully!";
        
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error_message'] = "Failed to publish results. Please try again.";
    }
    
    header("Location: results.php?election_id=$election_id");
    exit;
}

$page_title = "Election Results";
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <!-- Header -->
            <div class="results-header mb-4">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-0"><i class="fas fa-chart-bar text-primary"></i> Election Results</h2>
                        <p class="text-muted mb-0">Comprehensive election results and analytics</p>
                    </div>
                    <div class="btn-group">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                        <?php if ($election_data): ?>
                            <button onclick="exportResults('pdf')" class="btn btn-danger">
                                <i class="fas fa-file-pdf"></i> Export PDF
                            </button>
                            <?php if (hasPermission('publish_results')): ?>
                                <?php if (!$election_data['results_published_at']): ?>
                                    <button onclick="publishResults()" class="btn btn-primary">
                                        <i class="fas fa-eye"></i> Publish Results
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Election Selection -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card election-selector">
                        <div class="card-body">
                            <form method="GET" class="row align-items-center">
                                <div class="col-md-8">
                                    <div class="form-group mb-0">
                                        <label for="election_select" class="form-label fw-bold">Select Election:</label>
                                        <select name="election_id" id="election_select" class="form-control form-control-lg" onchange="this.form.submit()">
                                            <option value="">Choose an election to view results...</option>
                                            <?php foreach ($elections as $election): ?>
                                                <option value="<?= $election['election_id'] ?>" 
                                                        <?= $election['election_id'] == $election_id ? 'selected' : '' ?>>
                                                    <?= sanitize($election['name']) ?> 
                                                    (<?= ucfirst($election['status']) ?> - <?= $election['voter_count'] ?> voters)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                
                                <?php if ($election_data): ?>
                                    <div class="col-md-4 text-end">
                                        <div class="publication-status">
                                            <?php if ($election_data['results_published_at']): ?>
                                                <span class="badge bg-success fs-6 p-2">
                                                    <i class="fas fa-eye"></i> Results Published
                                                </span>
                                                <small class="d-block text-muted mt-1">
                                                    <?= date('M j, Y g:i A', strtotime($election_data['results_published_at'])) ?>
                                                </small>
                                            <?php else: ?>
                                                <span class="badge bg-warning fs-6 p-2">
                                                    <i class="fas fa-eye-slash"></i> Draft Results
                                                </span>
                                                <small class="d-block text-muted mt-1">Not yet published</small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$election_data): ?>
                <div class="empty-state">
                    <div class="text-center py-5">
                        <i class="fas fa-chart-bar fa-4x text-muted mb-3"></i>
                        <h4 class="text-muted">No Election Selected</h4>
                        <p class="text-muted">Please select an election from the dropdown above to view detailed results.</p>
                        <?php if (empty($elections)): ?>
                            <p class="text-muted"><small>No elections found in the database.</small></p>
                            <a href="../elections/" class="btn btn-primary">
                                <i class="fas fa-plus"></i> Create First Election
                            </a>
                        <?php else: ?>
                            <p class="text-muted"><small><?= count($elections) ?> election(s) available for analysis.</small></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                
                <!-- Alert Messages -->
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle"></i> <?= sanitize($_SESSION['success_message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle"></i> <?= sanitize($_SESSION['error_message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>

                <!-- Election Overview -->
                <div class="row mb-4">
                    <div class="col-md-12">
                        <div class="card election-overview">
                            <div class="card-header bg-gradient-primary text-white">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h3 class="mb-1"><?= sanitize($election_data['name']) ?></h3>
                                        <p class="mb-0 opacity-75"><?= sanitize($election_data['election_type_name']) ?></p>
                                    </div>
                                    <div class="text-end">
                                        <div class="election-status">
                                            <span class="badge bg-light text-dark fs-6">
                                                <?= ucfirst($election_data['status']) ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="election-details">
                                            <p class="mb-2">
                                                <i class="fas fa-calendar text-primary me-2"></i>
                                                <strong>Duration:</strong> 
                                                <?= date('M j, Y g:i A', strtotime($election_data['start_date'])) ?> - 
                                                <?= date('M j, Y g:i A', strtotime($election_data['end_date'])) ?>
                                            </p>
                                            <?php if ($election_data['results_published_at']): ?>
                                                <p class="mb-2">
                                                    <i class="fas fa-broadcast-tower text-success me-2"></i>
                                                    <strong>Published:</strong> 
                                                    <?= date('M j, Y g:i A', strtotime($election_data['results_published_at'])) ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if ($statistics['first_vote'] && $statistics['last_vote']): ?>
                                                <p class="mb-0">
                                                    <i class="fas fa-clock text-info me-2"></i>
                                                    <strong>Voting Period:</strong>
                                                    <?= date('M j g:i A', strtotime($statistics['first_vote'])) ?> - 
                                                    <?= date('M j g:i A', strtotime($statistics['last_vote'])) ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="turnout-display">
                                            <h5 class="text-center mb-3">Voter Turnout</h5>
                                            <?php 
                                            $turnout = $statistics['total_eligible'] > 0 
                                                ? round(($statistics['total_voters'] / $statistics['total_eligible']) * 100, 1) 
                                                : 0;
                                            $turnout_color = $turnout >= 50 ? 'success' : ($turnout >= 25 ? 'warning' : 'danger');
                                            ?>
                                            <div class="text-center">
                                                <div class="turnout-circle mb-2">
                                                    <span class="percentage text-<?= $turnout_color ?>"><?= $turnout ?>%</span>
                                                </div>
                                                <p class="mb-0">
                                                    <strong><?= number_format($statistics['total_voters']) ?></strong> of 
                                                    <strong><?= number_format($statistics['total_eligible']) ?></strong> eligible voters
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Dashboard -->
                <div class="row mb-4">
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card bg-gradient-primary">
                            <div class="stat-icon">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?= number_format($statistics['total_voters']) ?></h3>
                                <p>Total Voters</p>
                                <small class="stat-change">
                                    <?= $turnout ?>% turnout rate
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card bg-gradient-success">
                            <div class="stat-icon">
                                <i class="fas fa-vote-yea"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?= number_format($statistics['total_votes']) ?></h3>
                                <p>Total Votes Cast</p>
                                <small class="stat-change">
                                    ~<?= round($statistics['avg_votes_per_voter'] ?? 0, 1) ?> votes per voter
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card bg-gradient-info">
                            <div class="stat-icon">
                                <i class="fas fa-list-alt"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?= $statistics['total_positions'] ?></h3>
                                <p>Total Positions</p>
                                <small class="stat-change">
                                    <?= count(array_filter($results, function($p) { return $p['is_contested']; })) ?> contested
                                </small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 mb-3">
                        <div class="stat-card bg-gradient-warning">
                            <div class="stat-icon">
                                <i class="fas fa-trophy"></i>
                            </div>
                            <div class="stat-content">
                                <h3><?= count(array_filter($results, function($p) { return $p['is_tie'] ?? false; })) ?></h3>
                                <p>Tied Positions</p>
                                <small class="stat-change">
                                    Require review
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Voting Timeline Chart -->
                <?php if (!empty($timeline_data)): ?>
                    <div class="row mb-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-header">
                                    <h5><i class="fas fa-chart-line me-2"></i>Voting Timeline</h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="votingTimelineChart" height="100"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Results by Position -->
                <?php if (empty($results)): ?>
                    <div class="alert alert-info text-center">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <h5>No Results Available</h5>
                        <p>No voting data found for this election. This could be because:</p>
                        <ul class="list-unstyled">
                            <li>• No votes have been cast yet</li>
                            <li>• No positions were created for this election</li>
                            <li>• Voting is still in progress</li>
                        </ul>
                    </div>
                <?php else: ?>
                    <div class="results-grid">
                        <?php 
                        $position_counter = 0;
                        foreach ($results as $position_id => $position): 
                            $position_counter++;
                        ?>
                            <div class="position-results mb-4" data-position="<?= $position_id ?>">
                                <div class="card">
                                    <div class="card-header position-header">
                                        <div class="row align-items-center">
                                            <div class="col-md-8">
                                                <h5 class="mb-0">
                                                    <i class="fas fa-trophy text-warning me-2"></i>
                                                    #<?= $position_counter ?>: <?= sanitize($position['position_title']) ?>
                                                    
                                                    <?php if ($position['is_tie'] ?? false): ?>
                                                        <span class="badge bg-warning ms-2">
                                                            <i class="fas fa-exclamation-triangle"></i> TIE
                                                        </span>
                                                    <?php endif; ?>
                                                </h5>
                                                <?php if ($position['position_description']): ?>
                                                    <small class="text-muted"><?= sanitize($position['position_description']) ?></small>
                                                <?php endif; ?>
                                            </div>
                                            <div class="col-md-4 text-end">
                                                <div class="position-stats">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <?php if (empty($position['candidates'])): ?>
                                            <div class="empty-candidates text-center py-4">
                                                <i class="fas fa-user-slash fa-3x text-muted mb-3"></i>
                                                <h6 class="text-muted">No candidates registered for this position</h6>
                                            </div>
                                        <?php else: ?>
                                            <div class="candidates-grid">
                                                <?php foreach ($position['candidates'] as $index => $candidate): ?>
                                                    <div class="candidate-card <?= $candidate['is_winner'] ? 'winner' : '' ?> <?= $candidate['is_close'] ? 'close-race' : '' ?>"
                                                         data-rank="<?= $index + 1 ?>">
                                                        
                                                        <div class="card-header">
                                                            <div class="rank-position">#<?= $index + 1 ?></div>
                                                            <div class="header-badges">
                                                                <?php if ($candidate['is_winner']): ?>
                                                                    <span class="badge winner-badge-header">
                                                                        <i class="fas fa-trophy"></i> Winner
                                                                    </span>
                                                                <?php endif; ?>
                                                                
                                                                <?php if ($candidate['is_close']): ?>
                                                                    <span class="badge close-race-badge-header">
                                                                        <i class="fas fa-fire"></i> Close
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if ($candidate['is_winner']): ?>
                                                                <div class="winner-crown">
                                                                    <i class="fas fa-crown"></i>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <div class="card-body">
                                                            <div class="candidate-photo-section">
                                                                <?php 
                                                                $photo_path = '';
                                                                $photo_exists = false;
                                                                
                                                                if ($candidate['photo_url']) {
                                                                    if (strpos($candidate['photo_url'], '/online_voting/') === 0) {
                                                                        $relative_path = str_replace('/online_voting/', '', $candidate['photo_url']);
                                                                        $photo_path = $candidate['photo_url'];
                                                                        $photo_exists = file_exists('../../' . $relative_path);
                                                                    } else {
                                                                        $photo_path = '../../' . $candidate['photo_url'];
                                                                        $photo_exists = file_exists('../../' . $candidate['photo_url']);
                                                                    }
                                                                }
                                                                ?>
                                                                <?php if ($photo_exists): ?>
                                                                    <img src="<?= sanitize($photo_path) ?>" 
                                                                         class="candidate-photo" 
                                                                         alt="<?= sanitize($candidate['name']) ?>">
                                                                <?php else: ?>
                                                                    <div class="candidate-placeholder">
                                                                        <i class="fas fa-user"></i>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                            
                                                            <div class="candidate-info">
                                                                <h6 class="candidate-name">
                                                                    <?= sanitize($candidate['name']) ?>
                                                                </h6>
                                                                <div class="candidate-vote-badge">
                                                                    <strong><?= number_format($candidate['vote_count']) ?> votes</strong>
                                                                    <span class="vote-percentage-small">(<?= $candidate['percentage'] ?>%)</span>
                                                                </div>
                                                                <div class="candidate-details">
                                                                    <div class="student-id"><?= sanitize($candidate['student_number']) ?></div>
                                                                    <?php if ($candidate['program_name'] && $candidate['class_name']): ?>
                                                                        <div class="program-class">
                                                                            <?= sanitize($candidate['program_name']) ?> - <?= sanitize($candidate['class_name']) ?>
                                                                        </div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                            
                                            <!-- Position Summary -->
                                            <div class="position-summary mt-3">
                                                <div class="row text-center">
                                                    <div class="col-md-6">
                                                        <small class="text-muted">Total Votes</small>
                                                        <div class="fw-bold"><?= number_format($position['total_votes']) ?></div>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <small class="text-muted">Candidates</small>
                                                        <div class="fw-bold"><?= count($position['candidates']) ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Summary Footer -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card results-footer">
                            <div class="card-body">
                                <div class="row align-items-center">
                                    <div class="col-md-8">
                                        <div class="footer-info">
                                            <h6 class="mb-1">Election Summary</h6>
                                            <p class="text-muted mb-0">
                                                Results generated on <strong><?= date('M j, Y \a\t g:i A') ?></strong> • 
                                                Election conducted from <strong><?= date('M j', strtotime($election_data['start_date'])) ?></strong> to 
                                                <strong><?= date('M j, Y', strtotime($election_data['end_date'])) ?></strong>
                                                <?php if ($statistics['total_voters']): ?>
                                                    • <strong><?= number_format($statistics['total_voters']) ?></strong> voters participated
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <div class="col-md-4 text-end">
                                        <div class="footer-actions">
                                            <?php if ($election_data['results_published_at']): ?>
                                                <span class="badge bg-success fs-6">
                                                    <i class="fas fa-check-circle"></i> Results are Public
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary fs-6">
                                                    <i class="fas fa-lock"></i> Internal Preview
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Publish Results Modal -->
<div class="modal fade" id="publishModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">Publish Election Results</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        Publishing results will make them visible to students and the public. This action cannot be undone.
                    </div>
                    
                    <h6>Results Summary:</h6>
                    <ul class="list-unstyled">
                        <li><strong><?= $statistics['total_voters'] ?></strong> students voted</li>
                        <li><strong><?= $statistics['total_votes'] ?></strong> total votes cast</li>
                        <li><strong><?= $statistics['total_positions'] ?></strong> positions contested</li>
                    </ul>
                    
                    <p class="text-muted small mb-0">
                        Once published, students will be able to view the complete election results including vote counts and percentages.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="publish_results" class="btn btn-primary">
                        <i class="fas fa-eye"></i> Publish Results
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<style>
/* Results Page Styles */
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --success-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --info-gradient: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
    --warning-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
    --danger-gradient: linear-gradient(135deg, #ff9a9e 0%, #fecfef 100%);
}

.results-header {
    background: var(--primary-gradient);
    color: white;
    padding: 2rem;
    border-radius: 15px;
    margin-bottom: 2rem;
}

.election-selector {
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    border: none;
    border-radius: 15px;
}

.election-overview {
    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    border: none;
    border-radius: 15px;
    overflow: hidden;
}

.bg-gradient-primary {
    background: var(--primary-gradient) !important;
}

.turnout-circle {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.percentage {
    font-size: 1.5rem;
    font-weight: bold;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 1.5rem;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    border: none;
    position: relative;
    overflow: hidden;
    color: white;
    height: 140px;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    opacity: 0.1;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="20" cy="20" r="2" fill="white"/><circle cx="80" cy="20" r="2" fill="white"/><circle cx="80" cy="80" r="2" fill="white"/><circle cx="20" cy="80" r="2" fill="white"/></svg>');
}

.bg-gradient-success {
    background: var(--success-gradient) !important;
}

.bg-gradient-info {
    background: var(--info-gradient) !important;
}

.bg-gradient-warning {
    background: var(--warning-gradient) !important;
}

.stat-icon {
    position: absolute;
    top: 1rem; right: 1rem;
    font-size: 2rem; opacity: 0.3;
}

.stat-content h3 { font-size: 2.5rem; font-weight: bold; margin-bottom: 0.5rem; }
.stat-content p { margin-bottom: 0.5rem; font-size: 1.1rem; }
.stat-change { opacity: 0.8; font-size: 0.9rem; }

.position-results {
    animation: fadeInUp 0.5s ease forwards;
}

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.position-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border-bottom: 2px solid #dee2e6;
}

.stat-badge {
    background: rgba(108, 117, 125, 0.1);
    color: #495057;
    padding: 0.25rem 0.75rem;
    border-radius: 20px;
    font-size: 0.85rem;
    margin-left: 0.5rem;
}

.candidates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-top: 1rem;
}

.candidate-card {
    background: white;
    border: 2px solid #e9ecef;
    border-radius: 15px;
    position: relative;
    transition: all 0.3s ease;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-height: 280px;
}

.card-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    padding: 0.75rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #dee2e6;
    min-height: 50px;
}

.card-body {
    padding: 1rem;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.candidate-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 30px rgba(0,0,0,0.15);
}

.candidate-card.winner {
    border-color: #28a745;
    background: linear-gradient(135deg, #fff 0%, #f8fff9 100%);
}

.candidate-card.close-race {
    border-color: #ffc107;
    background: linear-gradient(135deg, #fff 0%, #fffbf0 100%);
}

.rank-position {
    background: #6c757d;
    color: white;
    border-radius: 20px;
    padding: 0.25rem 0.75rem;
    font-size: 0.85rem;
    font-weight: bold;
}

.header-badges {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.winner-badge-header {
    background: linear-gradient(45deg, #28a745, #20c997);
    color: white;
    border: none;
    font-size: 0.7rem;
    padding: 0.2rem 0.4rem;
    border-radius: 12px;
    font-weight: bold;
}

.close-race-badge-header {
    background: linear-gradient(45deg, #ffc107, #ffca2c);
    color: #212529;
    border: none;
    font-size: 0.7rem;
    padding: 0.2rem 0.4rem;
    border-radius: 12px;
    font-weight: bold;
}

.winner-crown {
    background: linear-gradient(45deg, #ffd700, #ffed4e);
    color: #b8860b;
    border-radius: 50%;
    width: 35px;
    height: 35px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    box-shadow: 0 3px 10px rgba(255, 193, 7, 0.4);
}

.candidate-photo-section {
    text-align: center;
    margin-bottom: 0.75rem;
}

.candidate-photo {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #dee2e6;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    transition: transform 0.3s ease;
}

.candidate-photo:hover {
    transform: scale(1.05);
}

.candidate-placeholder {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: 3px solid #dee2e6;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto;
    font-size: 2rem;
    color: #6c757d;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.candidate-info {
    text-align: center;
    margin-bottom: 0.75rem;
}

.candidate-name {
    font-weight: bold;
    color: #212529;
    margin-bottom: 0.5rem;
    font-size: 1.1rem;
    line-height: 1.3;
}

.candidate-vote-badge {
    background: linear-gradient(135deg, #e3f2fd 0%, #bbdefb 100%);
    border: 1px solid #2196f3;
    border-radius: 20px;
    padding: 0.4rem 0.8rem;
    margin: 0.5rem auto;
    display: inline-block;
    font-size: 0.9rem;
    color: #1565c0;
}

.vote-percentage-small {
    margin-left: 0.3rem;
    font-weight: normal;
    opacity: 0.8;
}

.candidate-details {
    color: #6c757d;
    font-size: 0.9rem;
    line-height: 1.4;
    margin-top: 0.5rem;
}

.student-id {
    font-weight: 600;
    color: #495057;
    margin-bottom: 0.25rem;
    font-size: 1rem;
}

.program-class {
    color: #6c757d;
    font-size: 0.9rem;
}

.vote-stats {
    text-align: center;
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.vote-display {
    margin-bottom: 1rem;
}

.vote-count {
    font-size: 2rem;
    font-weight: bold;
    color: #007bff;
    line-height: 1;
    margin-bottom: 0.25rem;
}

.vote-label {
    color: #6c757d;
    font-size: 0.85rem;
    margin: 0 0.5rem;
}

.vote-percentage {
    font-size: 1.5rem;
    font-weight: bold;
    color: #28a745;
    margin-left: 0.5rem;
}

.progress-bar-container {
    background: #e9ecef;
    border-radius: 10px;
    height: 8px;
    margin-bottom: 1rem;
    overflow: hidden;
}

.progress-bar-fill {
    height: 100%;
    background: linear-gradient(90deg, #007bff, #0056b3);
    border-radius: 10px;
    transition: width 0.3s ease;
}

.progress-bar-fill.winner {
    background: linear-gradient(90deg, #28a745, #1e7e34);
}

.badges {
    display: flex;
    justify-content: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.winner-badge {
    background: linear-gradient(45deg, #28a745, #20c997);
    color: white;
    border: none;
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    border-radius: 15px;
    font-weight: bold;
}

.close-race-badge {
    background: linear-gradient(45deg, #ffc107, #ffca2c);
    color: #212529;
    border: none;
    font-size: 0.75rem;
    padding: 0.25rem 0.5rem;
    border-radius: 15px;
    font-weight: bold;
}

.margin-info {
    margin-bottom: 1rem;
}

.candidate-badges {
    text-align: center;
}

.position-summary {
    background: #f8f9fa;
    border-radius: 10px;
    padding: 1rem;
    border-top: 1px solid #dee2e6;
}

.results-footer {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
    border: none;
    border-radius: 15px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.08);
}

.empty-state {
    background: #f8f9fa;
    border-radius: 15px;
    padding: 3rem;
}

/* Mobile Responsiveness */
@media (max-width: 768px) {
    .candidates-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-card {
        margin-bottom: 1rem;
    }
    
    .results-header {
        padding: 1rem;
    }
    
    .candidate-card { height: 250px; }
    .card-header { padding: 0.5rem; min-height: 40px; }
    .card-body { padding: 0.75rem; gap: 0.5rem; }
    .candidate-photo, .candidate-placeholder { width: 60px; height: 60px; }
    .vote-count { font-size: 1.5rem; }
    .vote-percentage { font-size: 1.2rem; }
    .rank-position { padding: 0.2rem 0.5rem; font-size: 0.75rem; }
    .winner-crown { width: 30px; height: 30px; font-size: 1rem; }
    .btn-group { flex-direction: column; }
    .btn-group .btn { margin-bottom: 0.5rem; }
}

</style>

<script src="<?= SITE_URL ?>/assets/js/chart.min.js"></script>
<script>
// Voting Timeline Chart
<?php if (!empty($timeline_data)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('votingTimelineChart').getContext('2d');
    const timelineData = <?= json_encode($timeline_data) ?>;
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: timelineData.map(d => new Date(d.vote_date).toLocaleDateString()),
            datasets: [{
                label: 'Daily Voters',
                data: timelineData.map(d => d.daily_voters),
                borderColor: 'rgb(75, 192, 192)',
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
});
<?php endif; ?>

// Modal Functions
const publishResults = () => new bootstrap.Modal(document.getElementById('publishModal')).show();



const exportResults = (format) => {
    const electionId = <?= $election_id ?>;
    if (format === 'pdf') {
        window.open(`export-pdf.php?election_id=${electionId}`, '_blank');
    }
};

// Auto-refresh for active elections
<?php if ($election_data && $election_data['status'] === 'active'): ?>
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 60000); // Refresh every minute for active elections
<?php endif; ?>

// Animation on scroll
const initScrollAnimations = () => {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                Object.assign(entry.target.style, {
                    opacity: '1',
                    transform: 'translateY(0)'
                });
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });

    document.querySelectorAll('.position-results').forEach(el => {
        Object.assign(el.style, {
            opacity: '0',
            transform: 'translateY(30px)',
            transition: 'all 0.6s ease'
        });
        observer.observe(el);
    });
};

// Initialize animations when DOM is ready
initScrollAnimations();
</script>

<?php include '../../includes/footer.php'; ?>