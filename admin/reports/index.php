<?php
require_once '../../config/config.php';
require_once '../../config/database.php';

if (!isLoggedIn() || (!hasPermission('view_reports') && !hasPermission('manage_system'))) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$current_user = getCurrentUser();

// Get report statistics
$stmt = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM elections) as total_elections,
        (SELECT COUNT(*) FROM elections WHERE status = 'completed') as completed_elections,
        (SELECT COUNT(*) FROM students WHERE is_active = 1) as total_students,
        (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_users,
        (SELECT COUNT(*) FROM voting_sessions WHERE status = 'completed') as total_voting_sessions,
        (SELECT COUNT(*) FROM audit_logs WHERE DATE(timestamp) = CURDATE()) as today_audit_logs
");
$stats = $stmt->fetch();

// Get recent elections for quick reports
$stmt = $db->query("
    SELECT e.election_id, e.name, e.status, e.start_date, e.end_date,
           et.name as election_type_name,
           COUNT(DISTINCT vs.student_id) as voter_count,
           COUNT(v.vote_id) as vote_count
    FROM elections e
    LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
    LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
    LEFT JOIN votes v ON vs.session_id = v.session_id
    GROUP BY e.election_id
    ORDER BY e.start_date DESC
    LIMIT 10
");
$recent_elections = $stmt->fetchAll();

// Get recent audit activities
$stmt = $db->query("
    SELECT al.*, u.first_name, u.last_name, s.first_name as student_first_name, s.last_name as student_last_name
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    LEFT JOIN students s ON al.student_id = s.student_id
    ORDER BY al.timestamp DESC
    LIMIT 10
");
$recent_activities = $stmt->fetchAll();

$page_title = "Reports Dashboard";
include '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-chart-line"></i> Reports Dashboard</h2>
                <div class="btn-group">
                    <a href="../" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to Admin
                    </a>
                    <a href="comprehensive.php" class="btn btn-success">
                        <i class="fas fa-chart-line"></i> Comprehensive Reports
                    </a>
                    <div class="dropdown">
                        <button class="btn btn-primary dropdown-toggle" type="button" id="quickReports" data-toggle="dropdown">
                            <i class="fas fa-bolt"></i> Quick Reports
                        </button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="comprehensive.php">
                                <i class="fas fa-tachometer-alt"></i> Advanced Dashboard
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item" href="elections.php">
                                <i class="fas fa-vote-yea"></i> Election Reports
                            </a>
                            <a class="dropdown-item" href="audit-logs.php">
                                <i class="fas fa-shield-alt"></i> Audit Logs
                            </a>
                            <a class="dropdown-item" href="statistics.php">
                                <i class="fas fa-chart-bar"></i> Statistical Reports
                            </a>
                            <a class="dropdown-item" href="export.php">
                                <i class="fas fa-download"></i> Data Export
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Overview Statistics -->
            <div class="row mb-4">
                <div class="col-md-2">
                    <div class="card bg-primary text-white">
                        <div class="card-body text-center">
                            <h3><?= $stats['total_elections'] ?></h3>
                            <p class="mb-0">Total Elections</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-success text-white">
                        <div class="card-body text-center">
                            <h3><?= $stats['completed_elections'] ?></h3>
                            <p class="mb-0">Completed</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-info text-white">
                        <div class="card-body text-center">
                            <h3><?= $stats['total_students'] ?></h3>
                            <p class="mb-0">Students</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-warning text-white">
                        <div class="card-body text-center">
                            <h3><?= $stats['total_users'] ?></h3>
                            <p class="mb-0">Users</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-secondary text-white">
                        <div class="card-body text-center">
                            <h3><?= $stats['total_voting_sessions'] ?></h3>
                            <p class="mb-0">Voting Sessions</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-2">
                    <div class="card bg-dark text-white">
                        <div class="card-body text-center">
                            <h3><?= $stats['today_audit_logs'] ?></h3>
                            <p class="mb-0">Today's Logs</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Featured Report System -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card border-success">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-star"></i> Enhanced Admin Reporting System
                            </h5>
                        </div>
                        <div class="card-body text-center">
                            <div class="mb-3">
                                <i class="fas fa-chart-line fa-4x text-success"></i>
                            </div>
                            <h4 class="text-success">Comprehensive Reports & Analytics</h4>
                            <p class="card-text lead">Access advanced reporting dashboard with student management, election analytics, security auditing, and system performance monitoring.</p>
                            <div class="row text-center mb-3">
                                <div class="col-md-3">
                                    <strong>15+</strong><br><small class="text-muted">Report Types</small>
                                </div>
                                <div class="col-md-3">
                                    <strong>Real-time</strong><br><small class="text-muted">Data Updates</small>
                                </div>
                                <div class="col-md-3">
                                    <strong>Advanced</strong><br><small class="text-muted">Analytics</small>
                                </div>
                                <div class="col-md-3">
                                    <strong>Multiple</strong><br><small class="text-muted">Export Formats</small>
                                </div>
                            </div>
                            <a href="comprehensive.php" class="btn btn-success btn-lg">
                                <i class="fas fa-rocket"></i> Launch Advanced Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Legacy Report Categories -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="mb-3">
                                <i class="fas fa-vote-yea fa-3x text-primary"></i>
                            </div>
                            <h5 class="card-title">Election Reports</h5>
                            <p class="card-text">Basic election analysis, results, and voter turnout reports.</p>
                            <a href="elections.php" class="btn btn-primary">
                                <i class="fas fa-chart-bar"></i> View Reports
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="mb-3">
                                <i class="fas fa-shield-alt fa-3x text-success"></i>
                            </div>
                            <h5 class="card-title">Audit Logs</h5>
                            <p class="card-text">Security audit trails, user activities, and system event logs.</p>
                            <a href="audit-logs.php" class="btn btn-success">
                                <i class="fas fa-search"></i> View Logs
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="mb-3">
                                <i class="fas fa-chart-line fa-3x text-info"></i>
                            </div>
                            <h5 class="card-title">Statistical Reports</h5>
                            <p class="card-text">Advanced analytics, trends, and performance metrics.</p>
                            <a href="statistics.php" class="btn btn-info">
                                <i class="fas fa-analytics"></i> View Statistics
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="card h-100">
                        <div class="card-body text-center">
                            <div class="mb-3">
                                <i class="fas fa-download fa-3x text-warning"></i>
                            </div>
                            <h5 class="card-title">Data Export</h5>
                            <p class="card-text">Export system data in various formats for external analysis.</p>
                            <a href="export.php" class="btn btn-warning">
                                <i class="fas fa-file-export"></i> Export Data
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Elections & Activity -->
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-calendar-alt"></i> Recent Elections
                                <a href="elections.php" class="btn btn-sm btn-outline-primary float-right">View All</a>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_elections)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-inbox fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No elections found</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="thead-light">
                                            <tr>
                                                <th>Election</th>
                                                <th>Type</th>
                                                <th>Status</th>
                                                <th>Date</th>
                                                <th>Voters</th>
                                                <th>Votes</th>
                                                <th>Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recent_elections as $election): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= sanitize($election['name']) ?></strong>
                                                    </td>
                                                    <td>
                                                        <span class="badge badge-secondary">
                                                            <?= sanitize($election['election_type_name']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status_colors = [
                                                            'draft' => 'secondary',
                                                            'active' => 'success',
                                                            'completed' => 'primary',
                                                            'cancelled' => 'danger'
                                                        ];
                                                        ?>
                                                        <span class="badge badge-<?= $status_colors[$election['status']] ?? 'secondary' ?>">
                                                            <?= ucfirst($election['status']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <small>
                                                            <?= date('M j, Y', strtotime($election['start_date'])) ?>
                                                        </small>
                                                    </td>
                                                    <td><?= $election['voter_count'] ?></td>
                                                    <td><?= $election['vote_count'] ?></td>
                                                    <td>
                                                        <a href="elections.php?election_id=<?= $election['election_id'] ?>" 
                                                           class="btn btn-sm btn-outline-primary">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
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

                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-history"></i> Recent Activity
                                <a href="audit-logs.php" class="btn btn-sm btn-outline-success float-right">View All</a>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_activities)): ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-clock fa-2x text-muted mb-3"></i>
                                    <p class="text-muted">No recent activity</p>
                                </div>
                            <?php else: ?>
                                <div class="activity-feed" style="max-height: 400px; overflow-y: auto;">
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <div class="activity-item mb-3 pb-3 border-bottom">
                                            <div class="d-flex">
                                                <div class="activity-icon mr-3">
                                                    <?php
                                                    $icons = [
                                                        'login' => 'fas fa-sign-in-alt text-success',
                                                        'logout' => 'fas fa-sign-out-alt text-warning',
                                                        'vote' => 'fas fa-vote-yea text-primary',
                                                        'create' => 'fas fa-plus text-info',
                                                        'update' => 'fas fa-edit text-warning',
                                                        'delete' => 'fas fa-trash text-danger',
                                                        'publish_results' => 'fas fa-share text-success'
                                                    ];
                                                    $icon = $icons[$activity['action']] ?? 'fas fa-info-circle text-muted';
                                                    ?>
                                                    <i class="<?= $icon ?>"></i>
                                                </div>
                                                <div class="activity-content flex-grow-1">
                                                    <div class="activity-action">
                                                        <strong><?= sanitize($activity['action']) ?></strong>
                                                    </div>
                                                    <div class="activity-user text-muted small">
                                                        <?php if ($activity['user_id']): ?>
                                                            by <?= sanitize($activity['first_name'] . ' ' . $activity['last_name']) ?>
                                                        <?php elseif ($activity['student_id']): ?>
                                                            by <?= sanitize($activity['student_first_name'] . ' ' . $activity['student_last_name']) ?>
                                                        <?php else: ?>
                                                            by System
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="activity-time text-muted small">
                                                        <?= date('M j, g:i A', strtotime($activity['timestamp'])) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.card {
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border: none;
    margin-bottom: 1.5rem;
}

.card-body i.fa-3x {
    margin-bottom: 1rem;
}

.activity-feed {
    scrollbar-width: thin;
    scrollbar-color: #888 #f1f1f1;
}

.activity-feed::-webkit-scrollbar {
    width: 6px;
}

.activity-feed::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.activity-feed::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 3px;
}

.activity-feed::-webkit-scrollbar-thumb:hover {
    background: #555;
}

.activity-item:last-child {
    border-bottom: none !important;
}

.activity-icon {
    width: 30px;
    text-align: center;
}

.table th {
    border-top: none;
    font-weight: 600;
}

.badge {
    font-size: 0.75em;
}

@media (max-width: 768px) {
    .btn-group {
        flex-direction: column;
    }
    
    .dropdown-toggle {
        margin-top: 0.5rem;
    }
}
</style>

<?php include '../../includes/footer.php'; ?>