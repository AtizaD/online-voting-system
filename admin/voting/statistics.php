<?php
$page_title = 'Voting Statistics & Analytics';
$breadcrumbs = [
    ['title' => 'Admin', 'url' => '/admin/'],
    ['title' => 'Voting', 'url' => '/admin/voting/'],
    ['title' => 'Statistics']
];

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../auth/session.php';

// Check authentication and authorization
requireAuth(['admin', 'election_officer']);

$db = Database::getInstance()->getConnection();

// Get parameters
$election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;
$time_range = isset($_GET['range']) ? $_GET['range'] : 'all';
$program_filter = isset($_GET['program_id']) ? (int)$_GET['program_id'] : null;

try {
    // Get elections for selection
    $stmt = $db->query("
        SELECT e.election_id, e.name, e.status, e.start_date, e.end_date,
               COUNT(DISTINCT vs.student_id) as voter_count,
               COUNT(v.vote_id) as vote_count
        FROM elections e
        LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
        LEFT JOIN votes v ON vs.session_id = v.session_id
        GROUP BY e.election_id
        ORDER BY e.created_at DESC
    ");
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get programs for filtering
    $stmt = $db->query("SELECT program_id, program_name FROM programs WHERE is_active = TRUE ORDER BY program_name");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Auto-select first election if none selected
    if (!$election_id && !empty($elections)) {
        $election_id = $elections[0]['election_id'];
    }

    $election_data = null;
    $statistics = [];

    if ($election_id) {
        // Get election details
        $stmt = $db->prepare("
            SELECT e.*, et.name as election_type_name,
                   u.first_name as created_by_name, u.last_name as created_by_lastname
            FROM elections e 
            LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
            LEFT JOIN users u ON e.created_by = u.user_id
            WHERE e.election_id = ?
        ");
        $stmt->execute([$election_id]);
        $election_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($election_data) {
            // Build date and program filters
            $date_filter = "";
            $program_join = "";
            $params = [$election_id];
            
            switch ($time_range) {
                case 'today':
                    $date_filter = "AND DATE(vs.started_at) = CURDATE()";
                    break;
                case 'yesterday':
                    $date_filter = "AND DATE(vs.started_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
                    break;
                case 'week':
                    $date_filter = "AND vs.started_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
                    break;
                case 'month':
                    $date_filter = "AND vs.started_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                    break;
            }

            if ($program_filter) {
                $program_join = "JOIN students s_filter ON vs.student_id = s_filter.student_id";
                $date_filter .= " AND s_filter.program_id = ?";
                $params[] = $program_filter;
            }

            // 1. Overall Election Statistics
            $stmt = $db->prepare("
                SELECT 
                    COUNT(DISTINCT vs.student_id) as total_voters,
                    COUNT(v.vote_id) as total_votes,
                    COUNT(DISTINCT p.position_id) as total_positions,
                    COUNT(DISTINCT c.candidate_id) as total_candidates,
                    (SELECT COUNT(*) FROM students s JOIN classes cl ON s.class_id = cl.class_id 
                     WHERE s.is_active = TRUE AND s.is_verified = TRUE 
                     " . ($program_filter ? "AND s.program_id = $program_filter" : "") . ") as eligible_voters,
                    AVG(vs.votes_cast) as avg_votes_per_session,
                    MIN(vs.started_at) as first_vote_time,
                    MAX(vs.completed_at) as last_vote_time
                FROM voting_sessions vs
                $program_join
                LEFT JOIN votes v ON vs.session_id = v.session_id
                LEFT JOIN positions p ON v.position_id = p.position_id AND p.election_id = ?
                LEFT JOIN candidates c ON v.candidate_id = c.candidate_id AND c.election_id = ?
                WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
            ");
            $stmt->execute([...$params, $election_id, $election_id]);
            $statistics['overall'] = $stmt->fetch(PDO::FETCH_ASSOC);

            // Calculate turnout percentage
            $statistics['overall']['turnout_percentage'] = $statistics['overall']['eligible_voters'] > 0 
                ? round(($statistics['overall']['total_voters'] / $statistics['overall']['eligible_voters']) * 100, 2) 
                : 0;

            // 2. Daily Voting Trends
            $stmt = $db->prepare("
                SELECT 
                    DATE(vs.started_at) as vote_date,
                    COUNT(DISTINCT vs.student_id) as voters_count,
                    COUNT(v.vote_id) as votes_count,
                    AVG(vs.votes_cast) as avg_votes_per_session,
                    AVG(TIMESTAMPDIFF(MINUTE, vs.started_at, vs.completed_at)) as avg_session_duration
                FROM voting_sessions vs
                $program_join
                LEFT JOIN votes v ON vs.session_id = v.session_id
                WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
                GROUP BY DATE(vs.started_at)
                ORDER BY vote_date
            ");
            $stmt->execute($params);
            $statistics['daily_trends'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Hourly Voting Patterns
            $stmt = $db->prepare("
                SELECT 
                    HOUR(vs.started_at) as hour,
                    COUNT(DISTINCT vs.student_id) as voters_count,
                    COUNT(v.vote_id) as votes_count,
                    AVG(TIMESTAMPDIFF(MINUTE, vs.started_at, vs.completed_at)) as avg_duration
                FROM voting_sessions vs
                $program_join
                LEFT JOIN votes v ON vs.session_id = v.session_id
                WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
                GROUP BY HOUR(vs.started_at)
                ORDER BY hour
            ");
            $stmt->execute($params);
            $statistics['hourly_patterns'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4. Program-wise Statistics
            $stmt = $db->prepare("
                SELECT 
                    p.program_name,
                    p.program_id,
                    COALESCE(student_counts.total_students, 0) as total_students,
                    COALESCE(voter_counts.voted_students, 0) as voted_students,
                    COALESCE(voter_counts.total_votes, 0) as total_votes,
                    COALESCE(voter_counts.avg_votes_per_voter, 0) as avg_votes_per_voter,
                    CASE 
                        WHEN COALESCE(student_counts.total_students, 0) > 0 
                        THEN ROUND(COALESCE(voter_counts.voted_students, 0) / student_counts.total_students * 100, 2) 
                        ELSE 0 
                    END as turnout_rate
                FROM programs p
                LEFT JOIN (
                    SELECT 
                        s.program_id,
                        COUNT(DISTINCT s.student_id) as total_students
                    FROM students s 
                    WHERE s.is_active = TRUE AND s.is_verified = TRUE
                    GROUP BY s.program_id
                ) student_counts ON p.program_id = student_counts.program_id
                LEFT JOIN (
                    SELECT 
                        s.program_id,
                        COUNT(DISTINCT vs.student_id) as voted_students,
                        COUNT(v.vote_id) as total_votes,
                        AVG(vs.votes_cast) as avg_votes_per_voter
                    FROM students s
                    JOIN voting_sessions vs ON s.student_id = vs.student_id 
                        AND vs.election_id = ? AND vs.status = 'completed' $date_filter
                    LEFT JOIN votes v ON vs.session_id = v.session_id
                    WHERE s.is_active = TRUE AND s.is_verified = TRUE
                    GROUP BY s.program_id
                ) voter_counts ON p.program_id = voter_counts.program_id
                WHERE p.is_active = TRUE 
                " . ($program_filter ? "AND p.program_id = $program_filter" : "") . "
                  AND COALESCE(student_counts.total_students, 0) > 0
                ORDER BY turnout_rate DESC, p.program_name
            ");
            $stmt->execute($params);
            $statistics['program_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5. Level-wise Statistics
            $stmt = $db->prepare("
                SELECT 
                    l.level_name,
                    p.program_name,
                    COUNT(DISTINCT s.student_id) as total_students,
                    COUNT(DISTINCT vs.student_id) as voted_students,
                    ROUND(COUNT(DISTINCT vs.student_id) / COUNT(DISTINCT s.student_id) * 100, 2) as turnout_rate
                FROM levels l
                JOIN classes c ON l.level_id = c.level_id
                JOIN students s ON c.class_id = s.class_id AND s.is_active = TRUE AND s.is_verified = TRUE
                JOIN programs p ON s.program_id = p.program_id
                LEFT JOIN voting_sessions vs ON s.student_id = vs.student_id AND vs.election_id = ? AND vs.status = 'completed' $date_filter
                WHERE l.is_active = TRUE AND p.is_active = TRUE 
                " . ($program_filter ? "AND p.program_id = $program_filter" : "") . "
                GROUP BY l.level_id, p.program_id
                HAVING total_students > 0
                ORDER BY l.level_name, p.program_name
            ");
            $stmt->execute($params);
            $statistics['level_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 6. Position-wise Statistics
            $stmt = $db->prepare("
                SELECT 
                    pos.title as position_title,
                    pos.display_order,
                    COUNT(DISTINCT c.candidate_id) as candidate_count,
                    COUNT(v.vote_id) as total_votes,
                    COUNT(av.abstain_id) as abstain_votes,
                    COUNT(DISTINCT vs.student_id) as total_responses,
                    ROUND(COUNT(v.vote_id) / (COUNT(v.vote_id) + COUNT(av.abstain_id)) * 100, 2) as vote_rate
                FROM positions pos
                LEFT JOIN candidates c ON pos.position_id = c.position_id
                LEFT JOIN votes v ON c.candidate_id = v.candidate_id
                LEFT JOIN abstain_votes av ON pos.position_id = av.position_id
                LEFT JOIN voting_sessions vs ON (v.session_id = vs.session_id OR av.session_id = vs.session_id) AND vs.status = 'completed'
                WHERE pos.election_id = ?
                GROUP BY pos.position_id, pos.title, pos.display_order
                ORDER BY pos.display_order
            ");
            $stmt->execute([$election_id]);
            $statistics['position_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 7. Device/Browser Statistics
            $stmt = $db->prepare("
                SELECT 
                    CASE 
                        WHEN vs.user_agent LIKE '%Mobile%' OR vs.user_agent LIKE '%Android%' OR vs.user_agent LIKE '%iPhone%' THEN 'Mobile'
                        WHEN vs.user_agent LIKE '%Chrome%' THEN 'Chrome Desktop'
                        WHEN vs.user_agent LIKE '%Firefox%' THEN 'Firefox Desktop'
                        WHEN vs.user_agent LIKE '%Safari%' AND vs.user_agent NOT LIKE '%Chrome%' THEN 'Safari Desktop'
                        WHEN vs.user_agent LIKE '%Edge%' THEN 'Edge Desktop'
                        ELSE 'Other'
                    END as device_type,
                    COUNT(*) as session_count,
                    COUNT(v.vote_id) as vote_count,
                    ROUND(COUNT(*) / (SELECT COUNT(*) FROM voting_sessions WHERE election_id = ? AND status = 'completed') * 100, 2) as percentage
                FROM voting_sessions vs
                $program_join
                LEFT JOIN votes v ON vs.session_id = v.session_id
                WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
                GROUP BY device_type
                ORDER BY session_count DESC
            ");
            $stmt->execute([...$params, $election_id]);
            $statistics['device_stats'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 8. Peak Voting Times Analysis
            $stmt = $db->prepare("
                SELECT 
                    DATE(vs.started_at) as peak_date,
                    HOUR(vs.started_at) as peak_hour,
                    COUNT(*) as voting_sessions,
                    COUNT(v.vote_id) as votes_in_hour
                FROM voting_sessions vs
                $program_join
                LEFT JOIN votes v ON vs.session_id = v.session_id
                WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
                GROUP BY DATE(vs.started_at), HOUR(vs.started_at)
                ORDER BY voting_sessions DESC
                LIMIT 10
            ");
            $stmt->execute($params);
            $statistics['peak_times'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 9. Recent Voting Activity (for real-time monitoring)
            $stmt = $db->prepare("
                SELECT 
                    vs.completed_at,
                    s.student_number,
                    s.first_name,
                    s.last_name,
                    p.program_name,
                    vs.votes_cast,
                    vs.ip_address,
                    TIMESTAMPDIFF(MINUTE, vs.started_at, vs.completed_at) as session_duration
                FROM voting_sessions vs
                JOIN students s ON vs.student_id = s.student_id
                JOIN programs p ON s.program_id = p.program_id
                WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
                ORDER BY vs.completed_at DESC
                LIMIT 20
            ");
            $stmt->execute($params);
            $statistics['recent_activity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    error_log("Statistics Error: " . $e->getMessage());
    $statistics = [];
    $elections = [];
    $programs = [];
}

require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Header Section -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="fas fa-chart-line text-primary"></i> Voting Statistics & Analytics</h2>
        <div class="btn-group">
            <a href="<?= SITE_URL ?>/admin/voting/" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <?php if ($election_data): ?>
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-success dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-download"></i> Export Data
                </button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" onclick="exportStatistics('pdf')">
                        <i class="fas fa-file-pdf me-2"></i>Export as PDF
                    </a></li>
                    <li><a class="dropdown-item" href="#" onclick="exportStatistics('csv')">
                        <i class="fas fa-file-csv me-2"></i>Export as CSV
                    </a></li>
                </ul>
            </div>
            <button onclick="refreshData()" class="btn btn-info">
                <i class="fas fa-sync-alt"></i> Refresh
            </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Section -->
    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-4">
                    <label for="election_select" class="form-label"><strong>Election</strong></label>
                    <select name="election_id" id="election_select" class="form-select" onchange="this.form.submit()">
                        <option value="">Choose an election...</option>
                        <?php foreach ($elections as $election): ?>
                            <option value="<?= $election['election_id'] ?>" 
                                    <?= $election['election_id'] == $election_id ? 'selected' : '' ?>>
                                <?= sanitize($election['name']) ?> 
                                (<?= ucfirst($election['status']) ?>) - <?= $election['voter_count'] ?> voters
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <label for="range_select" class="form-label"><strong>Time Range</strong></label>
                    <select name="range" id="range_select" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?= $time_range == 'all' ? 'selected' : '' ?>>All Time</option>
                        <option value="today" <?= $time_range == 'today' ? 'selected' : '' ?>>Today</option>
                        <option value="yesterday" <?= $time_range == 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
                        <option value="week" <?= $time_range == 'week' ? 'selected' : '' ?>>This Week</option>
                        <option value="month" <?= $time_range == 'month' ? 'selected' : '' ?>>This Month</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label for="program_select" class="form-label"><strong>Program Filter</strong></label>
                    <select name="program_id" id="program_select" class="form-select" onchange="this.form.submit()">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= $program['program_id'] ?>" 
                                    <?= $program['program_id'] == $program_filter ? 'selected' : '' ?>>
                                <?= sanitize($program['program_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$election_data): ?>
        <div class="alert alert-warning text-center">
            <i class="fas fa-exclamation-triangle fa-3x mb-3"></i>
            <h5>No Election Selected</h5>
            <p>Please select an election to view detailed statistics from the dropdown above.</p>
            <?php if (empty($elections)): ?>
                <p class="mt-2"><small>No elections found in the database.</small></p>
            <?php endif; ?>
        </div>
    <?php else: ?>

        <!-- Election Overview -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-poll"></i> <?= sanitize($election_data['name']) ?>
                    <span class="badge bg-light text-dark ms-2"><?= ucfirst($election_data['status']) ?></span>
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Type:</strong> <?= sanitize($election_data['election_type_name']) ?></p>
                        <p><strong>Created by:</strong> <?= sanitize($election_data['created_by_name'] . ' ' . $election_data['created_by_lastname']) ?></p>
                        <p><strong>Period:</strong> <?= date('M j, Y H:i', strtotime($election_data['start_date'])) ?> - 
                           <?= date('M j, Y H:i', strtotime($election_data['end_date'])) ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Last Updated:</strong> <span id="lastUpdate"><?= date('M j, Y H:i:s') ?></span></p>
                        <p><strong>Filter Applied:</strong> 
                            <?= ucfirst(str_replace('_', ' ', $time_range)) ?>
                            <?php if ($program_filter): ?>
                                | <?= sanitize(array_filter($programs, fn($p) => $p['program_id'] == $program_filter)[0]['program_name'] ?? 'Unknown Program') ?>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Key Metrics -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card bg-gradient-primary text-white h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-users fa-2x"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h3 class="mb-0"><?= number_format($statistics['overall']['total_voters'] ?? 0) ?></h3>
                                <p class="mb-0">Total Voters</p>
                                <small class="opacity-75">
                                    <?= $statistics['overall']['turnout_percentage'] ?? 0 ?>% turnout 
                                    (<?= number_format($statistics['overall']['eligible_voters'] ?? 0) ?> eligible)
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card bg-gradient-success text-white h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-vote-yea fa-2x"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h3 class="mb-0"><?= number_format($statistics['overall']['total_votes'] ?? 0) ?></h3>
                                <p class="mb-0">Total Votes</p>
                                <small class="opacity-75">
                                    Across <?= $statistics['overall']['total_positions'] ?? 0 ?> positions
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card bg-gradient-info text-white h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-chart-bar fa-2x"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h3 class="mb-0"><?= number_format($statistics['overall']['avg_votes_per_session'] ?? 0, 1) ?></h3>
                                <p class="mb-0">Avg Votes/Session</p>
                                <small class="opacity-75">
                                    <?= $statistics['overall']['total_candidates'] ?? 0 ?> candidates total
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card bg-gradient-warning text-white h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="flex-shrink-0">
                                <i class="fas fa-clock fa-2x"></i>
                            </div>
                            <div class="flex-grow-1 ms-3">
                                <h3 class="mb-0" id="liveTime"><?= date('H:i:s') ?></h3>
                                <p class="mb-0">Current Time</p>
                                <small class="opacity-75">
                                    <?= count($statistics['daily_trends'] ?? []) ?> active days
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="row mb-4">
            <!-- Voting Trends Chart -->
            <div class="col-lg-8 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-chart-area"></i> Voting Trends Over Time</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($statistics['daily_trends'])): ?>
                            <canvas id="dailyTrendsChart" height="80"></canvas>
                        <?php else: ?>
                            <div class="text-center py-5">
                                <i class="fas fa-chart-area fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No voting trend data available for the selected period</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Hourly Pattern Chart -->
            <div class="col-lg-4 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-clock"></i> Hourly Voting Pattern</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($statistics['hourly_patterns'])): ?>
                            <canvas id="hourlyChart" height="120"></canvas>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-clock fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No hourly data</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Program and Position Statistics -->
        <div class="row mb-4">
            <!-- Program Statistics -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Program-wise Turnout</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($statistics['program_stats'])): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Program</th>
                                            <th>Students</th>
                                            <th>Voted</th>
                                            <th>Turnout</th>
                                            <th>Progress</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($statistics['program_stats'] as $program): ?>
                                            <tr>
                                                <td><?= sanitize($program['program_name']) ?></td>
                                                <td><?= number_format($program['total_students']) ?></td>
                                                <td><?= number_format($program['voted_students']) ?></td>
                                                <td>
                                                    <span class="badge bg-<?= $program['turnout_rate'] >= 70 ? 'success' : ($program['turnout_rate'] >= 40 ? 'warning' : 'danger') ?>">
                                                        <?= $program['turnout_rate'] ?>%
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar bg-<?= $program['turnout_rate'] >= 70 ? 'success' : ($program['turnout_rate'] >= 40 ? 'warning' : 'danger') ?>" 
                                                             style="width: <?= $program['turnout_rate'] ?>%">
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-graduation-cap fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No program data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Position Statistics -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list"></i> Position Statistics</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($statistics['position_stats'])): ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Position</th>
                                            <th>Candidates</th>
                                            <th>Votes</th>
                                            <th>Abstains</th>
                                            <th>Vote Rate</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($statistics['position_stats'] as $pos): ?>
                                            <tr>
                                                <td><?= sanitize($pos['position_title']) ?></td>
                                                <td><span class="badge bg-info"><?= $pos['candidate_count'] ?></span></td>
                                                <td><span class="badge bg-success"><?= number_format($pos['total_votes']) ?></span></td>
                                                <td><span class="badge bg-warning"><?= number_format($pos['abstain_votes']) ?></span></td>
                                                <td><?= $pos['vote_rate'] ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-list fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No position data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Device Statistics and Peak Times -->
        <div class="row mb-4">
            <!-- Device Statistics -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-mobile-alt"></i> Device Usage</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($statistics['device_stats'])): ?>
                            <canvas id="deviceChart" height="120"></canvas>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-mobile-alt fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No device data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Peak Times -->
            <div class="col-lg-6 mb-4">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-fire"></i> Peak Voting Times</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($statistics['peak_times'])): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Date</th>
                                            <th>Hour</th>
                                            <th>Sessions</th>
                                            <th>Votes</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($statistics['peak_times'], 0, 8) as $peak): ?>
                                            <tr>
                                                <td><?= date('M j', strtotime($peak['peak_date'])) ?></td>
                                                <td><?= $peak['peak_hour'] ?>:00</td>
                                                <td><span class="badge bg-primary"><?= $peak['voting_sessions'] ?></span></td>
                                                <td><span class="badge bg-success"><?= number_format($peak['votes_in_hour']) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-fire fa-2x text-muted mb-2"></i>
                                <p class="text-muted">No peak time data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-history"></i> Recent Voting Activity
                    <span class="badge bg-info ms-2"><?= count($statistics['recent_activity'] ?? []) ?></span>
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($statistics['recent_activity'])): ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Program</th>
                                    <th>Votes Cast</th>
                                    <th>Duration</th>
                                    <th>Time</th>
                                    <th>IP Address</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($statistics['recent_activity'] as $activity): ?>
                                    <tr>
                                        <td>
                                            <?= sanitize($activity['first_name'] . ' ' . $activity['last_name']) ?>
                                            <br><small class="text-muted"><?= sanitize($activity['student_number']) ?></small>
                                        </td>
                                        <td><?= sanitize($activity['program_name']) ?></td>
                                        <td><span class="badge bg-success"><?= $activity['votes_cast'] ?></span></td>
                                        <td><?= $activity['session_duration'] ?> min</td>
                                        <td>
                                            <small><?= date('M j, H:i:s', strtotime($activity['completed_at'])) ?></small>
                                        </td>
                                        <td><code class="small"><?= sanitize($activity['ip_address']) ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-history fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No recent voting activity found for the selected filters</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>
</div>

<!-- Include Chart.js -->
<script src="<?= SITE_URL ?>/assets/js/chart.min.js"></script>
<script>
// Live time update
setInterval(() => {
    document.getElementById('liveTime').textContent = new Date().toLocaleTimeString();
}, 1000);

// Chart configurations
Chart.defaults.responsive = true;
Chart.defaults.maintainAspectRatio = false;

<?php if (!empty($statistics['daily_trends'])): ?>
// Daily Trends Chart
const dailyCtx = document.getElementById('dailyTrendsChart').getContext('2d');
new Chart(dailyCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_map(fn($d) => date('M j', strtotime($d['vote_date'])), $statistics['daily_trends'])) ?>,
        datasets: [{
            label: 'Voters',
            data: <?= json_encode(array_column($statistics['daily_trends'], 'voters_count')) ?>,
            borderColor: 'rgb(54, 162, 235)',
            backgroundColor: 'rgba(54, 162, 235, 0.1)',
            tension: 0.4,
            fill: true
        }, {
            label: 'Votes',
            data: <?= json_encode(array_column($statistics['daily_trends'], 'votes_count')) ?>,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        plugins: {
            legend: {
                position: 'top'
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});
<?php endif; ?>

<?php if (!empty($statistics['hourly_patterns'])): ?>
// Hourly Pattern Chart
const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
new Chart(hourlyCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(fn($h) => $h['hour'] . ':00', $statistics['hourly_patterns'])) ?>,
        datasets: [{
            label: 'Voters',
            data: <?= json_encode(array_column($statistics['hourly_patterns'], 'voters_count')) ?>,
            backgroundColor: 'rgba(153, 102, 255, 0.8)',
            borderColor: 'rgba(153, 102, 255, 1)',
            borderWidth: 1
        }]
    },
    options: {
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});
<?php endif; ?>

<?php if (!empty($statistics['device_stats'])): ?>
// Device Statistics Chart
const deviceCtx = document.getElementById('deviceChart').getContext('2d');
new Chart(deviceCtx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($statistics['device_stats'], 'device_type')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($statistics['device_stats'], 'session_count')) ?>,
            backgroundColor: [
                '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', 
                '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF'
            ],
            borderWidth: 2,
            borderColor: '#fff'
        }]
    },
    options: {
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    padding: 15,
                    usePointStyle: true
                }
            }
        }
    }
});
<?php endif; ?>

// Utility functions
function refreshData() {
    document.getElementById('lastUpdate').textContent = new Date().toLocaleString();
    location.reload();
}

function exportStatistics(format = 'csv') {
    const params = new URLSearchParams(window.location.search);
    
    // Get election_id from URL params or dropdown
    let electionId = params.get('election_id');
    
    if (!electionId) {
        const electionSelect = document.getElementById('election_select');
        if (electionSelect && electionSelect.value) {
            electionId = electionSelect.value;
            params.set('election_id', electionId);
        } else {
            alert('Please select an election first.');
            return;
        }
    }
    
    // Set the correct parameter name for export format
    params.set('format', format);
    
    const exportUrl = `export-statistics.php?${params.toString()}`;
    window.open(exportUrl, '_blank');
}
</script>

<style>
.bg-gradient-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}
.bg-gradient-success {
    background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
}
.bg-gradient-info {
    background: linear-gradient(135deg, #3498db 0%, #85c1e9 100%);
}
.bg-gradient-warning {
    background: linear-gradient(135deg, #f39c12 0%, #f7dc6f 100%);
}

.card {
    border: none;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    transition: all 0.3s;
}

.card:hover {
    box-shadow: 0 0.25rem 2rem 0 rgba(58, 59, 69, 0.25);
}

.table-hover tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.1);
}

.progress {
    border-radius: 10px;
    overflow: hidden;
}

.badge {
    font-size: 0.75em;
}

.opacity-75 {
    opacity: 0.75;
}

.text-decoration-none:hover {
    text-decoration: underline !important;
}

@media (max-width: 768px) {
    .btn-group {
        flex-direction: column;
        width: 100%;
    }
    
    .btn-group .btn {
        margin-bottom: 0.25rem;
    }
}
</style>

<?php require_once '../../includes/footer.php'; ?>