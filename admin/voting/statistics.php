<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('view_reports') && !hasPermission('manage_voting'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get election ID from URL parameter
$election_id = isset($_GET['election_id']) ? (int)$_GET['election_id'] : null;
$time_range = isset($_GET['range']) ? $_GET['range'] : 'all';

// Get elections for selection
$stmt = $db->query("
    SELECT election_id, name, status, start_date, end_date
    FROM elections 
    ORDER BY start_date DESC
");
$elections = $stmt->fetchAll();

// If no election selected, use the first one
if (!$election_id && !empty($elections)) {
    $election_id = $elections[0]['election_id'];
}

$election_data = null;
$voting_patterns = [];
$demographic_stats = [];
$turnout_by_program = [];
$hourly_patterns = [];

if ($election_id) {
    // Get election details
    $stmt = $db->prepare("
        SELECT e.*, et.name as election_type_name
        FROM elections e 
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        WHERE e.election_id = ?
    ");
    $stmt->execute([$election_id]);
    $election_data = $stmt->fetch();

    if ($election_data) {
        // Build date filter based on time range
        $date_filter = "";
        $params = [$election_id];
        
        switch ($time_range) {
            case 'today':
                $date_filter = "AND DATE(vs.started_at) = CURDATE()";
                break;
            case 'week':
                $date_filter = "AND vs.started_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK)";
                break;
            case 'month':
                $date_filter = "AND vs.started_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)";
                break;
            case 'all':
            default:
                break;
        }

        // Get voting patterns by time
        $stmt = $db->prepare("
            SELECT 
                DATE(vs.started_at) as vote_date,
                COUNT(DISTINCT vs.student_id) as voters_count,
                COUNT(v.vote_id) as votes_count,
                AVG(vs.votes_cast) as avg_votes_per_session
            FROM voting_sessions vs
            LEFT JOIN votes v ON vs.session_id = v.session_id
            WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
            GROUP BY DATE(vs.started_at)
            ORDER BY vote_date
        ");
        $stmt->execute($params);
        $voting_patterns = $stmt->fetchAll();

        // Get demographic statistics
        $stmt = $db->prepare("
            SELECT 
                p.program_name,
                l.level_name,
                COUNT(DISTINCT s.student_id) as total_students,
                COUNT(DISTINCT vs.student_id) as voted_students,
                ROUND(COUNT(DISTINCT vs.student_id) / COUNT(DISTINCT s.student_id) * 100, 2) as turnout_rate
            FROM programs p
            LEFT JOIN students s ON p.program_id = s.program_id AND s.is_active = 1 AND s.is_verified = 1
            LEFT JOIN classes c ON s.class_id = c.class_id
            LEFT JOIN levels l ON c.level_id = l.level_id
            LEFT JOIN voting_sessions vs ON s.student_id = vs.student_id AND vs.election_id = ? AND vs.status = 'completed'
            WHERE p.is_active = 1
            GROUP BY p.program_id, l.level_id
            HAVING total_students > 0
            ORDER BY p.program_name, l.level_name
        ");
        $stmt->execute([$election_id]);
        $demographic_stats = $stmt->fetchAll();

        // Get turnout by program
        $stmt = $db->prepare("
            SELECT 
                p.program_name,
                COUNT(DISTINCT s.student_id) as total_students,
                COUNT(DISTINCT vs.student_id) as voted_students,
                ROUND(COUNT(DISTINCT vs.student_id) / COUNT(DISTINCT s.student_id) * 100, 2) as turnout_rate
            FROM programs p
            LEFT JOIN students s ON p.program_id = s.program_id AND s.is_active = 1 AND s.is_verified = 1
            LEFT JOIN voting_sessions vs ON s.student_id = vs.student_id AND vs.election_id = ? AND vs.status = 'completed'
            WHERE p.is_active = 1
            GROUP BY p.program_id, p.program_name
            HAVING total_students > 0
            ORDER BY turnout_rate DESC
        ");
        $stmt->execute([$election_id]);
        $turnout_by_program = $stmt->fetchAll();

        // Get hourly voting patterns
        $stmt = $db->prepare("
            SELECT 
                HOUR(vs.started_at) as hour,
                COUNT(DISTINCT vs.student_id) as voters_count,
                AVG(TIMESTAMPDIFF(MINUTE, vs.started_at, vs.completed_at)) as avg_session_duration
            FROM voting_sessions vs
            WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
            GROUP BY HOUR(vs.started_at)
            ORDER BY hour
        ");
        $stmt->execute($params);
        $hourly_patterns = $stmt->fetchAll();

        // Get position-wise statistics
        $stmt = $db->prepare("
            SELECT 
                p.title as position_title,
                COUNT(DISTINCT c.candidate_id) as candidate_count,
                COUNT(v.vote_id) as total_votes,
                COUNT(av.abstain_id) as abstain_votes,
                ROUND(COUNT(v.vote_id) / (COUNT(v.vote_id) + COUNT(av.abstain_id)) * 100, 2) as vote_rate
            FROM positions p
            LEFT JOIN candidates c ON p.position_id = c.position_id
            LEFT JOIN votes v ON c.candidate_id = v.candidate_id
            LEFT JOIN abstain_votes av ON p.position_id = av.position_id
            WHERE p.election_id = ?
            GROUP BY p.position_id, p.title
            ORDER BY p.display_order
        ");
        $stmt->execute([$election_id]);
        $position_stats = $stmt->fetchAll();

        // Get voting device/browser statistics
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
                COUNT(*) as vote_count,
                ROUND(COUNT(*) / (SELECT COUNT(*) FROM voting_sessions WHERE election_id = ? AND status = 'completed') * 100, 2) as percentage
            FROM voting_sessions vs
            WHERE vs.election_id = ? AND vs.status = 'completed' $date_filter
            GROUP BY device_type
            ORDER BY vote_count DESC
        ");
        $stmt->execute([$election_id, $election_id]);
        $device_stats = $stmt->fetchAll();
    }
}

$page_title = "Voting Statistics & Analytics";
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-chart-line"></i> Voting Statistics & Analytics</h2>
                <div class="btn-group">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                    <button onclick="exportData()" class="btn btn-success">
                        <i class="fas fa-download"></i> Export Data
                    </button>
                </div>
            </div>

            <!-- Filters -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body">
                            <form method="GET" class="form-inline">
                                <label for="election_select" class="mr-2"><strong>Election:</strong></label>
                                <select name="election_id" id="election_select" class="form-control mr-3">
                                    <option value="">Choose an election...</option>
                                    <?php foreach ($elections as $election): ?>
                                        <option value="<?= $election['election_id'] ?>" 
                                                <?= $election['election_id'] == $election_id ? 'selected' : '' ?>>
                                            <?= sanitize($election['name']) ?> 
                                            (<?= ucfirst($election['status']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                
                                <label for="range_select" class="mr-2"><strong>Time Range:</strong></label>
                                <select name="range" id="range_select" class="form-control mr-3">
                                    <option value="all" <?= $time_range == 'all' ? 'selected' : '' ?>>All Time</option>
                                    <option value="today" <?= $time_range == 'today' ? 'selected' : '' ?>>Today</option>
                                    <option value="week" <?= $time_range == 'week' ? 'selected' : '' ?>>This Week</option>
                                    <option value="month" <?= $time_range == 'month' ? 'selected' : '' ?>>This Month</option>
                                </select>
                                
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-filter"></i> Apply Filters
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$election_data): ?>
                <div class="alert alert-warning text-center">
                    <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
                    <h5>No Election Selected</h5>
                    <p>Please select an election to view statistics from the dropdown above.</p>
                </div>
            <?php else: ?>

                <!-- Voting Trends Chart -->
                <div class="row mb-4">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-chart-area"></i> Voting Trends Over Time</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="votingTrendsChart" height="100"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-clock"></i> Hourly Voting Pattern</h5>
                            </div>
                            <div class="card-body">
                                <canvas id="hourlyChart" height="150"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Turnout by Program -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-graduation-cap"></i> Turnout by Program</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($turnout_by_program)): ?>
                                    <p class="text-center text-muted">No data available</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Program</th>
                                                    <th>Students</th>
                                                    <th>Voted</th>
                                                    <th>Turnout</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($turnout_by_program as $program): ?>
                                                    <tr>
                                                        <td><?= sanitize($program['program_name']) ?></td>
                                                        <td><?= $program['total_students'] ?></td>
                                                        <td><?= $program['voted_students'] ?></td>
                                                        <td>
                                                            <div class="progress" style="height: 20px;">
                                                                <div class="progress-bar" style="width: <?= $program['turnout_rate'] ?>%">
                                                                    <?= $program['turnout_rate'] ?>%
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Position Statistics -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-users"></i> Position Statistics</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($position_stats)): ?>
                                    <p class="text-center text-muted">No data available</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Position</th>
                                                    <th>Candidates</th>
                                                    <th>Votes</th>
                                                    <th>Abstains</th>
                                                    <th>Vote Rate</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($position_stats as $pos): ?>
                                                    <tr>
                                                        <td><?= sanitize($pos['position_title']) ?></td>
                                                        <td><span class="badge badge-info"><?= $pos['candidate_count'] ?></span></td>
                                                        <td><span class="badge badge-success"><?= $pos['total_votes'] ?></span></td>
                                                        <td><span class="badge badge-warning"><?= $pos['abstain_votes'] ?></span></td>
                                                        <td><?= $pos['vote_rate'] ?>%</td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Device/Browser Statistics -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-mobile-alt"></i> Voting Devices</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($device_stats)): ?>
                                    <p class="text-center text-muted">No data available</p>
                                <?php else: ?>
                                    <canvas id="deviceChart" height="200"></canvas>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Demographic Breakdown -->
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-users-cog"></i> Demographic Breakdown</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($demographic_stats)): ?>
                                    <p class="text-center text-muted">No data available</p>
                                <?php else: ?>
                                    <div style="max-height: 300px; overflow-y: auto;">
                                        <table class="table table-sm">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th>Program</th>
                                                    <th>Level</th>
                                                    <th>Total</th>
                                                    <th>Voted</th>
                                                    <th>Rate</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($demographic_stats as $demo): ?>
                                                    <tr>
                                                        <td><small><?= sanitize($demo['program_name']) ?></small></td>
                                                        <td><small><?= sanitize($demo['level_name']) ?></small></td>
                                                        <td><?= $demo['total_students'] ?></td>
                                                        <td><?= $demo['voted_students'] ?></td>
                                                        <td>
                                                            <span class="badge badge-<?= $demo['turnout_rate'] > 50 ? 'success' : 'warning' ?>">
                                                                <?= $demo['turnout_rate'] ?>%
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Summary Statistics -->
                <div class="row">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-clipboard-list"></i> Summary Statistics</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="stat-item text-center">
                                            <h4 class="text-primary">
                                                <?= array_sum(array_column($voting_patterns, 'voters_count')) ?>
                                            </h4>
                                            <p class="mb-0">Total Unique Voters</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="stat-item text-center">
                                            <h4 class="text-success">
                                                <?= array_sum(array_column($voting_patterns, 'votes_count')) ?>
                                            </h4>
                                            <p class="mb-0">Total Votes Cast</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="stat-item text-center">
                                            <h4 class="text-info">
                                                <?= !empty($voting_patterns) ? round(array_sum(array_column($voting_patterns, 'avg_votes_per_session')) / count($voting_patterns), 1) : 0 ?>
                                            </h4>
                                            <p class="mb-0">Avg Votes per Session</p>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="stat-item text-center">
                                            <h4 class="text-warning">
                                                <?= count($voting_patterns) ?>
                                            </h4>
                                            <p class="mb-0">Voting Days</p>
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

<script src="<?= SITE_URL ?>/assets/js/chart.min.js"></script>
<script>
// Voting Trends Chart
<?php if (!empty($voting_patterns)): ?>
const votingTrendsCtx = document.getElementById('votingTrendsChart').getContext('2d');
new Chart(votingTrendsCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode(array_column($voting_patterns, 'vote_date')) ?>,
        datasets: [{
            label: 'Voters',
            data: <?= json_encode(array_column($voting_patterns, 'voters_count')) ?>,
            borderColor: 'rgb(54, 162, 235)',
            backgroundColor: 'rgba(54, 162, 235, 0.2)',
            tension: 0.1
        }, {
            label: 'Votes',
            data: <?= json_encode(array_column($voting_patterns, 'votes_count')) ?>,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
<?php endif; ?>

// Hourly Chart
<?php if (!empty($hourly_patterns)): ?>
const hourlyCtx = document.getElementById('hourlyChart').getContext('2d');
new Chart(hourlyCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_map(function($h) { return $h['hour'] . ':00'; }, $hourly_patterns)) ?>,
        datasets: [{
            label: 'Voters',
            data: <?= json_encode(array_column($hourly_patterns, 'voters_count')) ?>,
            backgroundColor: 'rgba(153, 102, 255, 0.8)'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});
<?php endif; ?>

// Device Chart
<?php if (!empty($device_stats)): ?>
const deviceCtx = document.getElementById('deviceChart').getContext('2d');
new Chart(deviceCtx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_column($device_stats, 'device_type')) ?>,
        datasets: [{
            data: <?= json_encode(array_column($device_stats, 'vote_count')) ?>,
            backgroundColor: [
                '#FF6384',
                '#36A2EB', 
                '#FFCE56',
                '#4BC0C0',
                '#9966FF',
                '#FF9F40'
            ]
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});
<?php endif; ?>

function exportData() {
    // Simple CSV export functionality
    const election_id = <?= $election_id ?>;
    const range = '<?= $time_range ?>';
    window.open(`export_stats.php?election_id=${election_id}&range=${range}&format=csv`, '_blank');
}
</script>

<style>
.stat-item {
    padding: 1rem;
    border: 1px solid #e9ecef;
    border-radius: 0.25rem;
    margin-bottom: 1rem;
}

.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: none;
}

.progress {
    height: 20px;
    border-radius: 10px;
}

.table-sm th, .table-sm td {
    padding: 0.3rem;
}
</style>

<?php include '../../includes/footer.php'; ?>