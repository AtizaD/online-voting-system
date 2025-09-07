<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('view_reports') && !hasPermission('manage_system'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get time period filter
$period = isset($_GET['period']) ? $_GET['period'] : '30days';
$custom_from = isset($_GET['from']) ? $_GET['from'] : '';
$custom_to = isset($_GET['to']) ? $_GET['to'] : '';

// Set date range based on period
switch ($period) {
    case '7days':
        $date_from = date('Y-m-d', strtotime('-7 days'));
        $date_to = date('Y-m-d');
        break;
    case '30days':
        $date_from = date('Y-m-d', strtotime('-30 days'));
        $date_to = date('Y-m-d');
        break;
    case '90days':
        $date_from = date('Y-m-d', strtotime('-90 days'));
        $date_to = date('Y-m-d');
        break;
    case 'year':
        $date_from = date('Y-m-d', strtotime('-1 year'));
        $date_to = date('Y-m-d');
        break;
    case 'custom':
        $date_from = $custom_from ?: date('Y-m-d', strtotime('-30 days'));
        $date_to = $custom_to ?: date('Y-m-d');
        break;
    default:
        $date_from = date('Y-m-d', strtotime('-30 days'));
        $date_to = date('Y-m-d');
}

// Overall Statistics
$stmt = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM elections) as total_elections,
        (SELECT COUNT(*) FROM elections WHERE status = 'completed') as completed_elections,
        (SELECT COUNT(*) FROM elections WHERE status = 'active') as active_elections,
        (SELECT COUNT(*) FROM students WHERE is_active = 1) as total_students,
        (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_users,
        (SELECT COUNT(DISTINCT student_id) FROM voting_sessions WHERE status = 'completed') as students_voted,
        (SELECT COUNT(*) FROM voting_sessions WHERE status = 'completed') as total_voting_sessions,
        (SELECT COUNT(*) FROM votes) as total_votes
");
$overall_stats = $stmt->fetch();

// Voting participation rate
$participation_rate = $overall_stats['total_students'] > 0 
    ? round(($overall_stats['students_voted'] / $overall_stats['total_students']) * 100, 2) 
    : 0;

// Elections by Status
$stmt = $db->query("
    SELECT status, COUNT(*) as count 
    FROM elections 
    GROUP BY status 
    ORDER BY count DESC
");
$elections_by_status = $stmt->fetchAll();

// Elections by Type
$stmt = $db->query("
    SELECT et.name, COUNT(e.election_id) as count 
    FROM election_types et
    LEFT JOIN elections e ON et.election_type_id = e.election_type_id
    GROUP BY et.election_type_id, et.name
    ORDER BY count DESC
");
$elections_by_type = $stmt->fetchAll();

// Voting Trends Over Time (last 12 months)
$stmt = $db->query("
    SELECT 
        DATE_FORMAT(vs.created_at, '%Y-%m') as month,
        COUNT(*) as vote_count
    FROM voting_sessions vs
    WHERE vs.status = 'completed' 
    AND vs.created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(vs.created_at, '%Y-%m')
    ORDER BY month
");
$voting_trends = $stmt->fetchAll();

// Students by Program
$stmt = $db->query("
    SELECT 
        COALESCE(s.program, 'Unknown') as program,
        COUNT(*) as total_students,
        COUNT(CASE WHEN s.student_id IN (
            SELECT DISTINCT student_id FROM voting_sessions WHERE status = 'completed'
        ) THEN 1 END) as voted_students
    FROM students s 
    WHERE s.is_active = 1
    GROUP BY s.program
    ORDER BY total_students DESC
");
$program_stats = $stmt->fetchAll();

// Top Active Elections (by votes)
$stmt = $db->query("
    SELECT 
        e.name,
        e.status,
        COUNT(DISTINCT vs.student_id) as unique_voters,
        COUNT(v.vote_id) as total_votes,
        e.start_date,
        e.end_date
    FROM elections e
    LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
    LEFT JOIN votes v ON vs.session_id = v.session_id
    GROUP BY e.election_id
    ORDER BY total_votes DESC
    LIMIT 10
");
$top_elections = $stmt->fetchAll();

// Recent Activity Metrics
$stmt = $db->prepare("
    SELECT 
        DATE(timestamp) as activity_date,
        action,
        COUNT(*) as count
    FROM audit_logs 
    WHERE timestamp >= ? AND timestamp <= ?
    GROUP BY DATE(timestamp), action
    ORDER BY activity_date DESC
");
$stmt->execute([$date_from, $date_to . ' 23:59:59']);
$activity_metrics = $stmt->fetchAll();

// Peak Voting Hours
$stmt = $db->query("
    SELECT 
        HOUR(vs.created_at) as hour_of_day,
        COUNT(*) as vote_count
    FROM voting_sessions vs
    WHERE vs.status = 'completed'
    GROUP BY HOUR(vs.created_at)
    ORDER BY hour_of_day
");
$peak_hours = $stmt->fetchAll();

// System Performance Metrics
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_audit_logs,
        COUNT(CASE WHEN severity = 'ERROR' THEN 1 END) as error_count,
        COUNT(CASE WHEN severity = 'WARNING' THEN 1 END) as warning_count,
        COUNT(CASE WHEN action = 'login' THEN 1 END) as login_count,
        COUNT(CASE WHEN action = 'logout' THEN 1 END) as logout_count
    FROM audit_logs
    WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
");
$performance_stats = $stmt->fetch();

$page_title = "Statistical Reports";
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-chart-line"></i> Statistical Reports</h2>
                <div class="btn-group">
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Reports
                    </a>
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <!-- Time Period Filter -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row align-items-end">
                        <div class="col-md-3">
                            <label for="period">Time Period</label>
                            <select name="period" id="period" class="form-control" onchange="toggleCustomDates()">
                                <option value="7days" <?= $period === '7days' ? 'selected' : '' ?>>Last 7 Days</option>
                                <option value="30days" <?= $period === '30days' ? 'selected' : '' ?>>Last 30 Days</option>
                                <option value="90days" <?= $period === '90days' ? 'selected' : '' ?>>Last 90 Days</option>
                                <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Last Year</option>
                                <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Range</option>
                            </select>
                        </div>
                        <div class="col-md-2" id="custom-from" style="display: <?= $period === 'custom' ? 'block' : 'none' ?>">
                            <label for="from">From Date</label>
                            <input type="date" name="from" id="from" class="form-control" value="<?= $custom_from ?>">
                        </div>
                        <div class="col-md-2" id="custom-to" style="display: <?= $period === 'custom' ? 'block' : 'none' ?>">
                            <label for="to">To Date</label>
                            <input type="date" name="to" id="to" class="form-control" value="<?= $custom_to ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Update
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Key Metrics Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h3><?= number_format($overall_stats['total_elections']) ?></h3>
                            <p class="mb-0">Total Elections</p>
                            <small><?= $overall_stats['active_elections'] ?> Active</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h3><?= $participation_rate ?>%</h3>
                            <p class="mb-0">Participation Rate</p>
                            <small><?= number_format($overall_stats['students_voted']) ?> of <?= number_format($overall_stats['total_students']) ?> students</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h3><?= number_format($overall_stats['total_votes']) ?></h3>
                            <p class="mb-0">Total Votes Cast</p>
                            <small><?= number_format($overall_stats['total_voting_sessions']) ?> sessions</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h3><?= $performance_stats['error_count'] ?></h3>
                            <p class="mb-0">System Errors</p>
                            <small>Last 30 days</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="row mb-4">
                <!-- Voting Trends Chart -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line"></i> Voting Trends (Last 12 Months)</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="votingTrendsChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Elections by Status -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-pie"></i> Elections by Status</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Charts Row -->
            <div class="row mb-4">
                <!-- Peak Voting Hours -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-clock"></i> Peak Voting Hours</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="peakHoursChart" height="120"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Elections by Type -->
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-bar"></i> Elections by Type</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="electionTypesChart" height="120"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Program Statistics Table -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-graduation-cap"></i> Participation by Program</h5>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="thead-light">
                                        <tr>
                                            <th>Program</th>
                                            <th>Total Students</th>
                                            <th>Voted Students</th>
                                            <th>Participation Rate</th>
                                            <th>Progress</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($program_stats as $program): ?>
                                            <?php 
                                            $rate = $program['total_students'] > 0 
                                                ? round(($program['voted_students'] / $program['total_students']) * 100, 1) 
                                                : 0;
                                            ?>
                                            <tr>
                                                <td><strong><?= sanitize($program['program']) ?></strong></td>
                                                <td><?= number_format($program['total_students']) ?></td>
                                                <td><?= number_format($program['voted_students']) ?></td>
                                                <td><?= $rate ?>%</td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar" role="progressbar" 
                                                             style="width: <?= $rate ?>%" 
                                                             aria-valuenow="<?= $rate ?>" 
                                                             aria-valuemin="0" 
                                                             aria-valuemax="100">
                                                            <?= $rate ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Elections -->
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-trophy"></i> Top Elections by Votes</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($top_elections)): ?>
                                <p class="text-muted text-center">No election data available</p>
                            <?php else: ?>
                                <?php foreach (array_slice($top_elections, 0, 5) as $index => $election): ?>
                                    <div class="mb-3 pb-3 <?= $index < 4 ? 'border-bottom' : '' ?>">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
                                                <h6 class="mb-1"><?= sanitize($election['name']) ?></h6>
                                                <small class="text-muted">
                                                    <?= number_format($election['unique_voters']) ?> voters, 
                                                    <?= number_format($election['total_votes']) ?> votes
                                                </small>
                                                <br>
                                                <span class="badge badge-<?= $election['status'] === 'completed' ? 'success' : ($election['status'] === 'active' ? 'primary' : 'secondary') ?> badge-sm">
                                                    <?= ucfirst($election['status']) ?>
                                                </span>
                                            </div>
                                            <div class="text-right">
                                                <span class="badge badge-light">#<?= $index + 1 ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<script src="<?= SITE_URL ?>/assets/js/chart.min.js"></script>
<script>
// Voting Trends Chart
const votingTrendsCtx = document.getElementById('votingTrendsChart').getContext('2d');
new Chart(votingTrendsCtx, {
    type: 'line',
    data: {
        labels: [<?php echo '"' . implode('", "', array_column($voting_trends, 'month')) . '"'; ?>],
        datasets: [{
            label: 'Votes Cast',
            data: [<?php echo implode(', ', array_column($voting_trends, 'vote_count')); ?>],
            borderColor: 'rgb(54, 162, 235)',
            backgroundColor: 'rgba(54, 162, 235, 0.1)',
            tension: 0.4,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Elections by Status Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: [<?php echo '"' . implode('", "', array_map('ucfirst', array_column($elections_by_status, 'status'))) . '"'; ?>],
        datasets: [{
            data: [<?php echo implode(', ', array_column($elections_by_status, 'count')); ?>],
            backgroundColor: ['#28a745', '#007bff', '#6c757d', '#dc3545'],
            borderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Peak Hours Chart
const peakHoursCtx = document.getElementById('peakHoursChart').getContext('2d');
const hourLabels = Array.from({length: 24}, (_, i) => i + ':00');
const hourData = Array(24).fill(0);
<?php foreach ($peak_hours as $hour): ?>
    hourData[<?= $hour['hour_of_day'] ?>] = <?= $hour['vote_count'] ?>;
<?php endforeach; ?>

new Chart(peakHoursCtx, {
    type: 'bar',
    data: {
        labels: hourLabels,
        datasets: [{
            label: 'Votes',
            data: hourData,
            backgroundColor: 'rgba(255, 193, 7, 0.8)',
            borderColor: 'rgba(255, 193, 7, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

// Election Types Chart
const typesCtx = document.getElementById('electionTypesChart').getContext('2d');
new Chart(typesCtx, {
    type: 'bar',
    data: {
        labels: [<?php echo '"' . implode('", "', array_column($elections_by_type, 'name')) . '"'; ?>],
        datasets: [{
            label: 'Number of Elections',
            data: [<?php echo implode(', ', array_column($elections_by_type, 'count')); ?>],
            backgroundColor: 'rgba(40, 167, 69, 0.8)',
            borderColor: 'rgba(40, 167, 69, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true
            }
        }
    }
});

function toggleCustomDates() {
    const period = document.getElementById('period').value;
    const customFrom = document.getElementById('custom-from');
    const customTo = document.getElementById('custom-to');
    
    if (period === 'custom') {
        customFrom.style.display = 'block';
        customTo.style.display = 'block';
    } else {
        customFrom.style.display = 'none';
        customTo.style.display = 'none';
    }
}
</script>

<style>
.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: none;
    margin-bottom: 1.5rem;
}

.progress {
    background-color: #e9ecef;
}

.badge-sm {
    font-size: 0.65em;
}

@media print {
    .btn-group, .card-header .btn {
        display: none !important;
    }
}

@media (max-width: 768px) {
    .btn-group {
        flex-direction: column;
    }
    
    .btn-group .btn {
        margin-bottom: 0.25rem;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>