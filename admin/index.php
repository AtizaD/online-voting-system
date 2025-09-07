<?php
require_once '../config/config.php';
require_once '../auth/session.php';
require_once '../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user after authentication
$current_user = getCurrentUser();

$page_title = 'Admin Dashboard';
$breadcrumbs = [
    ['title' => 'Dashboard']
];

// Get dashboard statistics
try {
    $db = Database::getInstance()->getConnection();
    
    // Total users by role
    $stmt = $db->prepare("SELECT ur.role_name, COUNT(*) as count 
                          FROM users u 
                          JOIN user_roles ur ON u.role_id = ur.role_id 
                          WHERE u.is_active = 1 
                          GROUP BY ur.role_name");
    $stmt->execute();
    $userStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Comprehensive statistics
    $stmt = $db->query("
        SELECT 
            (SELECT COUNT(*) FROM students WHERE is_active = 1) as total_students,
            (SELECT COUNT(*) FROM elections) as total_elections,
            (SELECT COUNT(*) FROM elections WHERE status = 'active') as active_elections,
            (SELECT COUNT(*) FROM elections WHERE status = 'completed') as completed_elections,
            (SELECT COUNT(*) FROM votes) as total_votes,
            (SELECT COUNT(*) FROM users WHERE is_active = 1) as total_users,
            (SELECT COUNT(*) FROM classes WHERE is_active = 1) as total_classes,
            (SELECT COUNT(DISTINCT student_id) FROM voting_sessions WHERE status = 'completed') as students_voted
    ");
    $stats = $stmt->fetch();
    
    // Voter participation rate
    $participationRate = $stats['total_students'] > 0 
        ? round(($stats['students_voted'] / $stats['total_students']) * 100, 1) 
        : 0;
    
    // Recent elections with more details
    $stmt = $db->prepare("
        SELECT e.election_id, e.name as title, e.status, e.start_date, e.end_date, e.created_at,
               et.name as election_type,
               COUNT(DISTINCT vs.student_id) as voter_count,
               COUNT(v.vote_id) as vote_count
        FROM elections e
        LEFT JOIN election_types et ON e.election_type_id = et.election_type_id
        LEFT JOIN voting_sessions vs ON e.election_id = vs.election_id AND vs.status = 'completed'
        LEFT JOIN votes v ON vs.session_id = v.session_id
        GROUP BY e.election_id
        ORDER BY e.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recentElections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent audit logs with better formatting
    $stmt = $db->prepare("
        SELECT al.*, u.first_name, u.last_name, s.first_name as student_first_name, s.last_name as student_last_name
        FROM audit_logs al 
        LEFT JOIN users u ON al.user_id = u.user_id 
        LEFT JOIN students s ON al.student_id = s.student_id
        ORDER BY al.timestamp DESC 
        LIMIT 8
    ");
    $stmt->execute();
    $recentLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // System health checks with more details
    $systemHealth = [
        'database' => 'connected',
        'logs_writable' => is_writable(__DIR__ . '/../logs/'),
        'uploads_writable' => is_writable(__DIR__ . '/../uploads/'),
        'session_active' => session_status() === PHP_SESSION_ACTIVE,
        'php_version' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'compatible' : 'outdated'
    ];
    
    // Election status distribution
    $stmt = $db->query("
        SELECT status, COUNT(*) as count 
        FROM elections 
        GROUP BY status
    ");
    $electionStatusStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Today's activity summary
    $stmt = $db->query("
        SELECT 
            COUNT(CASE WHEN action = 'user_login' THEN 1 END) as logins_today,
            COUNT(CASE WHEN action = 'vote_cast' THEN 1 END) as votes_today,
            COUNT(*) as total_activities_today
        FROM audit_logs 
        WHERE DATE(timestamp) = CURDATE()
    ");
    $todayActivity = $stmt->fetch();
    
    // Debug: Check what actions exist in audit_logs today
    $debug_query = "
        SELECT action, COUNT(*) as count 
        FROM audit_logs 
        WHERE DATE(timestamp) = CURDATE() 
        GROUP BY action
    ";
    $debug_result = $db->query($debug_query)->fetchAll();
    
} catch (Exception $e) {
    logError("Dashboard query error: " . $e->getMessage());
    $error = "Unable to load dashboard statistics.";
}

// Helper function for time ago
function timeAgo($datetime) {
    // Convert database datetime to timestamp, accounting for timezone
    $datetime_timestamp = strtotime($datetime . ' UTC');
    $current_timestamp = time();
    $time = $current_timestamp - $datetime_timestamp;
    
    if ($time < 60) return 'just now';
    if ($time < 3600) {
        $minutes = floor($time / 60);
        return $minutes . ($minutes == 1 ? ' minute ago' : ' minutes ago');
    }
    if ($time < 86400) {
        $hours = floor($time / 3600);
        return $hours . ($hours == 1 ? ' hour ago' : ' hours ago');
    }
    if ($time < 2592000) {
        $days = floor($time / 86400);
        return $days . ($days == 1 ? ' day ago' : ' days ago');
    }
    return date('M j, Y', $datetime_timestamp);
}


include '../includes/header.php';
?>

<style>
/* Dashboard Styles - Consistent with Admin Panel */
:root {
    --primary-color: #667eea;
    --secondary-color: #764ba2;
    --success-color: #059669;
    --warning-color: #f59e0b;
    --danger-color: #dc2626;
    --info-color: #0ea5e9;
    --light-bg: #f8fafc;
    --card-shadow: 0 1px 3px rgba(0,0,0,0.1);
    --border-color: #e2e8f0;
}

/* Clean Welcome Section */
.dashboard-welcome {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
    border-radius: 0.5rem;
    padding: 2rem;
    color: white;
    margin-bottom: 2rem;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color);
}

.dashboard-welcome h2 {
    font-weight: 600;
    font-size: 2rem;
    margin-bottom: 0.5rem;
}

.dashboard-welcome p {
    font-size: 1rem;
    opacity: 0.9;
    margin-bottom: 0;
}

.dashboard-welcome .btn {
    background: rgba(255, 255, 255, 0.2);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: white;
    font-weight: 500;
    border-radius: 0.5rem;
    padding: 0.625rem 1.25rem;
    transition: all 0.2s ease;
}

.dashboard-welcome .btn:hover {
    background: rgba(255, 255, 255, 0.3);
    color: white;
    text-decoration: none;
}

/* Clean Activity Alert */
.activity-alert {
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    color: #1e293b;
    padding: 1.5rem;
    margin-bottom: 2rem;
    box-shadow: var(--card-shadow);
}

.activity-alert-icon {
    background: var(--info-color);
    color: white;
    width: 48px;
    height: 48px;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 1rem;
    flex-shrink: 0;
    font-size: 1.25rem;
}

/* Activity Metrics */
.activity-metric {
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.2s ease;
    box-shadow: var(--card-shadow);
}

.activity-metric:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}

.activity-metric-icon {
    width: 40px;
    height: 40px;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1rem;
    flex-shrink: 0;
}

.activity-metric-content {
    flex: 1;
    min-width: 0;
}

.activity-metric-number {
    font-size: 1.75rem;
    font-weight: 800;
    line-height: 1;
    margin-bottom: 0.25rem;
    color: #1e293b;
}

.activity-metric-label {
    font-size: 0.8rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    line-height: 1;
}

/* Real-time indicator */
.real-time-indicator {
    font-size: 0.875rem;
    font-weight: 500;
}

/* Clean Statistic Cards */
.stat-card {
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    box-shadow: var(--card-shadow);
    transition: all 0.2s ease;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}

.stat-card-body {
    padding: 1.5rem;
}

.stat-content {
    position: relative;
}

.stat-number {
    font-size: 2.5rem;
    font-weight: 700;
    color: var(--primary-color);
    margin-bottom: 0.5rem;
    line-height: 1;
}

.stat-label {
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    margin-bottom: 0.75rem;
}

.stat-change {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.stat-icon {
    position: absolute;
    right: 1.5rem;
    top: 50%;
    transform: translateY(-50%);
    font-size: 3rem;
    opacity: 0.1;
    color: var(--primary-color);
}

/* Stat card color variants */
.stat-card-success .stat-number,
.stat-card-success .stat-icon {
    color: var(--success-color);
}

.stat-card-info .stat-number,
.stat-card-info .stat-icon {
    color: var(--info-color);
}

.stat-card-warning .stat-number,
.stat-card-warning .stat-icon {
    color: var(--warning-color);
}

/* Clean Dashboard Cards */
.dashboard-card {
    background: white;
    border: 1px solid var(--border-color);
    border-radius: 0.5rem;
    box-shadow: var(--card-shadow);
    overflow: hidden;
    transition: all 0.2s ease;
}

.dashboard-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    border-color: #cbd5e1;
}

.dashboard-card .card-header {
    background: #f8fafc;
    border-bottom: 1px solid var(--border-color);
    padding: 1rem 1.5rem;
}

.dashboard-card .card-header h5,
.dashboard-card .card-header h6 {
    font-weight: 600;
    color: #1e293b;
    margin: 0;
    font-size: 1rem;
}

.dashboard-card .card-body {
    padding: 1.5rem;
}

/* Chart Container */
.chart-container {
    background: white;
    border-radius: 0.5rem;
    padding: 1rem;
    border: 1px solid var(--border-color);
}

/* Election Items */
.election-list {
    max-height: 400px;
    overflow-y: auto;
    padding-right: 0.5rem;
}

.election-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 0.75rem;
    background: #f8fafc;
    border: 1px solid var(--border-color);
    transition: all 0.2s ease;
}

.election-item:hover {
    background: white;
    transform: translateX(4px);
    box-shadow: var(--card-shadow);
}

.election-title {
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.5rem;
    font-size: 1.1rem;
}

.election-meta .badge {
    font-weight: 600;
    padding: 0.5rem 1rem;
    border-radius: 25px;
}

.election-stats {
    display: flex;
    gap: 1.5rem;
    margin-top: 0.75rem;
}

.election-stats small {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    color: #64748b;
    font-weight: 500;
}

/* Activity Timeline */
.activity-timeline {
    max-height: 400px;
    overflow-y: auto;
    padding-right: 0.5rem;
}

.activity-item {
    display: flex;
    align-items: flex-start;
    padding: 0.875rem;
    border-radius: 0.5rem;
    margin-bottom: 0.75rem;
    background: #f8fafc;
    border: 1px solid var(--border-color);
    transition: all 0.2s ease;
}

.activity-item:hover {
    background: white;
    box-shadow: var(--card-shadow);
}

.activity-icon {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--primary-color);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 0.875rem;
    flex-shrink: 0;
    color: white;
    font-size: 0.875rem;
}

.activity-content {
    flex: 1;
    min-width: 0;
}

.activity-action {
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.25rem;
    font-size: 1rem;
}

.activity-user {
    color: #64748b;
    margin-bottom: 0.25rem;
    font-size: 0.9rem;
    font-weight: 500;
}

.activity-time {
    color: #94a3b8;
    font-size: 0.8rem;
    font-weight: 500;
}

/* System Health Cards */
.health-item {
    background: #f8fafc;
    border-radius: 0.5rem;
    padding: 1rem;
    border: 1px solid var(--border-color);
    transition: all 0.2s ease;
    margin-bottom: 0.75rem;
}

.health-item:hover {
    background: white;
    box-shadow: var(--card-shadow);
}

.health-icon {
    width: 40px;
    height: 40px;
    border-radius: 0.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 0.875rem;
    flex-shrink: 0;
}

.health-icon.success {
    background: var(--success-color);
    color: white;
}

.health-icon.danger {
    background: var(--danger-color);
    color: white;
}

.health-content h6 {
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 0.25rem;
}

.health-content p {
    color: #64748b;
    margin: 0;
    font-weight: 500;
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 3rem 2rem;
    background: #f8fafc;
    border-radius: 0.5rem;
    border: 1px solid var(--border-color);
}

.empty-state i {
    color: var(--primary-color);
    margin-bottom: 1.5rem;
}

.empty-state h5 {
    color: #1e293b;
    font-weight: 700;
    margin-bottom: 0.75rem;
}

.empty-state p {
    color: #64748b;
    margin-bottom: 2rem;
}

/* Responsive Design */
@media (max-width: 768px) {
    .dashboard-welcome {
        padding: 2rem 1.5rem;
        text-align: center;
    }
    
    .dashboard-welcome h2 {
        font-size: 2rem;
    }
    
    .activity-alert {
        padding: 1.5rem;
    }
    
    .activity-alert-icon {
        width: 48px;
        height: 48px;
        margin-right: 1rem;
    }
    
    .activity-metric {
        padding: 1rem;
    }
    
    .activity-metric-icon {
        width: 40px;
        height: 40px;
        font-size: 1rem;
    }
    
    .activity-metric-number {
        font-size: 1.5rem;
    }
    
    .activity-metric-label {
        font-size: 0.75rem;
    }
    
    .stat-card-body {
        padding: 1.5rem;
    }
    
    .stat-number {
        font-size: 2.25rem;
    }
    
    .stat-icon {
        font-size: 3rem;
        right: 1.5rem;
    }
    
    .election-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 1rem;
    }
    
    .election-stats {
        flex-wrap: wrap;
        gap: 1rem;
    }
    
    .activity-item {
        padding: 0.75rem;
    }
    
    .activity-icon {
        width: 35px;
        height: 35px;
    }
}

/* Scrollbars */
.election-list::-webkit-scrollbar,
.activity-timeline::-webkit-scrollbar {
    width: 6px;
}

.election-list::-webkit-scrollbar-track,
.activity-timeline::-webkit-scrollbar-track {
    background: #f1f5f9;
    border-radius: 3px;
}

.election-list::-webkit-scrollbar-thumb,
.activity-timeline::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

.election-list::-webkit-scrollbar-thumb:hover,
.activity-timeline::-webkit-scrollbar-thumb:hover {
    background: #94a3b8;
}

/* Loading Animation */
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.loading {
    animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

/* Additional Styles */
.btn:focus {
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
}

/* Improved focus states */
.stat-card:focus-within,
.dashboard-card:focus-within {
    outline: 2px solid var(--primary-color);
    outline-offset: 2px;
    opacity: 0.8;
}
</style>

<!-- Enhanced Welcome Section -->
<div class="dashboard-welcome">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h2>Welcome back, <?= sanitize($current_user['first_name']) ?>!</h2>
            <p>Here's what's happening with your e-voting system today.</p>
            <div class="d-flex align-items-center mt-3">
                <div class="me-3">
                    <i class="fas fa-circle text-success me-2"></i>
                    <small class="text-white-50">System Status: Online</small>
                </div>
                <div>
                    <i class="fas fa-clock me-2"></i>
                    <small class="text-white-50" id="current-time"><?= date('M j, Y g:i A') ?></small>
                </div>
            </div>
        </div>
        <div class="col-md-4 text-md-end">
            <div class="d-flex gap-2 justify-content-md-end flex-wrap">
                <a href="elections/" class="btn btn-lg">
                    <i class="fas fa-plus me-2"></i>New Election
                </a>
                <a href="reports/" class="btn btn-lg">
                    <i class="fas fa-chart-line me-2"></i>Reports
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Today's Activity -->
<div class="row mb-4">
    <div class="col-12">
        <div class="activity-alert">
            <div class="d-flex align-items-start">
                <div class="activity-alert-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div>
                            <h5 class="fw-bold mb-1">Today's System Overview</h5>
                            <p class="text-muted mb-0">Real-time activity metrics for <?= date('F j, Y') ?></p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-success real-time-indicator">
                                <i class="fas fa-circle me-1" style="font-size: 0.6rem;"></i>Live
                            </span>
                            <button class="btn btn-sm btn-outline-primary" onclick="refreshActivityData()" title="Refresh Data">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Activity Metrics Grid -->
                    <div class="row g-3">
                        <div class="col-md-3 col-6">
                            <div class="activity-metric">
                                <div class="activity-metric-icon bg-primary">
                                    <i class="fas fa-sign-in-alt"></i>
                                </div>
                                <div class="activity-metric-content">
                                    <div class="activity-metric-number" data-value="<?= $todayActivity['logins_today'] ?? 0 ?>">
                                        <?= number_format($todayActivity['logins_today'] ?? 0) ?>
                                    </div>
                                    <div class="activity-metric-label">Logins Today</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 col-6">
                            <div class="activity-metric">
                                <div class="activity-metric-icon bg-success">
                                    <i class="fas fa-vote-yea"></i>
                                </div>
                                <div class="activity-metric-content">
                                    <div class="activity-metric-number" data-value="<?= $todayActivity['votes_today'] ?? 0 ?>">
                                        <?= number_format($todayActivity['votes_today'] ?? 0) ?>
                                    </div>
                                    <div class="activity-metric-label">Votes Cast</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 col-6">
                            <div class="activity-metric">
                                <div class="activity-metric-icon bg-info">
                                    <i class="fas fa-list-alt"></i>
                                </div>
                                <div class="activity-metric-content">
                                    <div class="activity-metric-number" data-value="<?= $todayActivity['total_activities_today'] ?? 0 ?>">
                                        <?= number_format($todayActivity['total_activities_today'] ?? 0) ?>
                                    </div>
                                    <div class="activity-metric-label">Total Activities</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-3 col-6">
                            <div class="activity-metric">
                                <div class="activity-metric-icon bg-warning">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="activity-metric-content">
                                    <div class="activity-metric-number" data-value="<?= $stats['active_elections'] ?? 0 ?>">
                                        <?= number_format($stats['active_elections'] ?? 0) ?>
                                    </div>
                                    <div class="activity-metric-label">Active Elections</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card">
            <div class="stat-card-body">
                <div class="stat-content">
                    <div class="stat-number"><?= number_format($stats['total_students'] ?? 0) ?></div>
                    <div class="stat-label">Total Students</div>
                    <div class="stat-change">
                        <i class="fas fa-graduation-cap text-success"></i>
                        <small class="text-muted fw-medium">
                            <?= number_format($stats['students_voted'] ?? 0) ?> participated
                        </small>
                    </div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-graduation-cap"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card stat-card-success">
            <div class="stat-card-body">
                <div class="stat-content">
                    <div class="stat-number"><?= number_format($stats['total_elections'] ?? 0) ?></div>
                    <div class="stat-label">Total Elections</div>
                    <div class="stat-change">
                        <i class="fas fa-check-circle text-success"></i>
                        <small class="text-muted fw-medium">
                            <?= number_format($stats['completed_elections'] ?? 0) ?> completed
                        </small>
                    </div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-poll"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card stat-card-info">
            <div class="stat-card-body">
                <div class="stat-content">
                    <div class="stat-number"><?= $participationRate ?>%</div>
                    <div class="stat-label">Voter Participation</div>
                    <div class="stat-change">
                        <i class="fas fa-trending-up text-info"></i>
                        <small class="text-muted fw-medium">
                            <?= number_format($stats['students_voted'] ?? 0) ?> of <?= number_format($stats['total_students'] ?? 0) ?>
                        </small>
                    </div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-chart-pie"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stat-card stat-card-warning">
            <div class="stat-card-body">
                <div class="stat-content">
                    <div class="stat-number"><?= number_format($stats['active_elections'] ?? 0) ?></div>
                    <div class="stat-label">Active Elections</div>
                    <div class="stat-change">
                        <i class="fas fa-pulse text-warning"></i>
                        <small class="text-muted fw-medium">
                            <?= number_format($stats['total_votes'] ?? 0) ?> votes cast
                        </small>
                    </div>
                </div>
                <div class="stat-icon">
                    <i class="fas fa-vote-yea"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Enhanced User Statistics & System Health -->
    <div class="col-xl-4 col-lg-5">
        <!-- User Statistics Chart -->
        <div class="dashboard-card mb-4">
            <div class="card-header">
                <h5><i class="fas fa-users me-2"></i>User Distribution</h5>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="userStatsChart" height="200"></canvas>
                </div>
                <div class="mt-3">
                    <?php foreach ($userStats ?? [] as $role => $count): ?>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="fw-medium">
                                <i class="fas fa-circle text-<?= $role === 'admin' ? 'primary' : ($role === 'student' ? 'success' : 'info') ?> me-2"></i>
                                <?= ucfirst(str_replace('_', ' ', $role)) ?>
                            </span>
                            <span class="fw-bold"><?= $count ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Enhanced System Health -->
        <div class="dashboard-card">
            <div class="card-header">
                <h5><i class="fas fa-heartbeat me-2"></i>System Health</h5>
            </div>
            <div class="card-body">
                <?php foreach ($systemHealth ?? [] as $check => $status): ?>
                    <div class="health-item d-flex align-items-center">
                        <div class="health-icon <?= $status === 'connected' || $status === true ? 'success' : 'danger' ?>">
                            <i class="fas fa-<?= $status === 'connected' || $status === true ? 'check' : 'times' ?>"></i>
                        </div>
                        <div class="health-content">
                            <h6><?= ucfirst(str_replace('_', ' ', $check)) ?></h6>
                            <p><?= $status === true ? 'Operating normally' : ($status === false ? 'Service unavailable' : ucfirst($status)) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Enhanced Recent Elections -->
    <div class="col-xl-5 col-lg-7">
        <div class="dashboard-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="fas fa-poll me-2"></i>Recent Elections</h5>
                <a href="elections/" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-arrow-right me-1"></i>View All
                </a>
            </div>
            <div class="card-body">
                <?php if (!empty($recentElections)): ?>
                    <div class="election-list">
                        <?php foreach ($recentElections as $election): ?>
                            <div class="election-item">
                                <div class="election-info flex-grow-1">
                                    <h6 class="election-title"><?= sanitize($election['title']) ?></h6>
                                    <div class="election-meta mb-2">
                                        <span class="badge bg-<?= $election['status'] === 'active' ? 'success' : ($election['status'] === 'draft' ? 'warning' : ($election['status'] === 'completed' ? 'primary' : 'secondary')) ?> me-2">
                                            <?= ucfirst($election['status']) ?>
                                        </span>
                                        <?php if ($election['election_type']): ?>
                                            <small class="text-muted fw-medium"><?= sanitize($election['election_type']) ?></small>
                                        <?php endif; ?>
                                    </div>
                                    <div class="election-stats">
                                        <small><i class="fas fa-users me-1"></i><?= number_format($election['voter_count'] ?? 0) ?> voters</small>
                                        <small><i class="fas fa-vote-yea me-1"></i><?= number_format($election['vote_count'] ?? 0) ?> votes</small>
                                        <small><i class="fas fa-calendar me-1"></i><?= date('M j, Y', strtotime($election['start_date'])) ?></small>
                                    </div>
                                </div>
                                <div class="election-actions">
                                    <a href="elections/view.php?id=<?= $election['election_id'] ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-poll fa-3x"></i>
                        <h5>No elections created yet</h5>
                        <p>Create your first election to get started with the voting system.</p>
                        <a href="elections/" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>Create Election
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Enhanced Recent Activity -->
    <div class="col-xl-3">
        <div class="dashboard-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="fas fa-history me-2"></i>Recent Activity</h5>
                <a href="reports/audit-logs.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-arrow-right me-1"></i>View All
                </a>
            </div>
            <div class="card-body">
                <?php if (!empty($recentLogs)): ?>
                    <div class="activity-timeline">
                        <?php foreach ($recentLogs as $log): ?>
                            <div class="activity-item">
                                <div class="activity-icon">
                                    <?php
                                    $icons = [
                                        'login' => 'fa-sign-in-alt',
                                        'user_login' => 'fa-sign-in-alt',
                                        'logout' => 'fa-sign-out-alt', 
                                        'vote' => 'fa-vote-yea',
                                        'create' => 'fa-plus',
                                        'update' => 'fa-edit',
                                        'delete' => 'fa-trash'
                                    ];
                                    $icon = $icons[$log['action']] ?? 'fa-circle';
                                    ?>
                                    <i class="fas <?= $icon ?>"></i>
                                </div>
                                <div class="activity-content">
                                    <div class="activity-action"><?= ucfirst(str_replace('_', ' ', $log['action'])) ?></div>
                                    <div class="activity-user">
                                        <?php if ($log['first_name']): ?>
                                            by <strong><?= sanitize($log['first_name'] . ' ' . $log['last_name']) ?></strong>
                                        <?php elseif ($log['student_first_name']): ?>
                                            by <strong><?= sanitize($log['student_first_name'] . ' ' . $log['student_last_name']) ?></strong>
                                        <?php else: ?>
                                            by System
                                        <?php endif; ?>
                                    </div>
                                    <div class="activity-time"><?= timeAgo($log['timestamp']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-history fa-2x"></i>
                        <h5>No recent activity</h5>
                        <p class="mb-0">System activity will appear here</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions Section -->
<div class="row mt-4">
    <div class="col-12">
        <div class="dashboard-card">
            <div class="card-header">
                <h5><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-3 col-md-6 mb-3">
                        <a href="elections/" class="btn btn-outline-primary btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center">
                            <i class="fas fa-plus fa-2x mb-2"></i>
                            <span class="fw-bold">Create Election</span>
                            <small class="text-muted">Start a new voting process</small>
                        </a>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <a href="students/" class="btn btn-outline-success btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center">
                            <i class="fas fa-graduation-cap fa-2x mb-2"></i>
                            <span class="fw-bold">Manage Students</span>
                            <small class="text-muted">Add or verify students</small>
                        </a>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <a href="reports/" class="btn btn-outline-info btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center">
                            <i class="fas fa-chart-line fa-2x mb-2"></i>
                            <span class="fw-bold">View Reports</span>
                            <small class="text-muted">Analytics and insights</small>
                        </a>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <a href="voting/" class="btn btn-outline-danger btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center">
                            <i class="fas fa-undo fa-2x mb-2"></i>
                            <span class="fw-bold">Reset Votes</span>
                            <small class="text-muted">Manage vote resets</small>
                        </a>
                    </div>
                    <div class="col-lg-3 col-md-6 mb-3">
                        <a href="settings/" class="btn btn-outline-warning btn-lg w-100 h-100 d-flex flex-column align-items-center justify-content-center">
                            <i class="fas fa-cog fa-2x mb-2"></i>
                            <span class="fw-bold">System Settings</span>
                            <small class="text-muted">Configure the system</small>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= SITE_URL ?>/assets/js/chart.min.js"></script>
<script>
// Enhanced User Statistics Chart
const ctx = document.getElementById('userStatsChart');
if (ctx) {
    const gradient1 = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
    gradient1.addColorStop(0, '#667eea');
    gradient1.addColorStop(1, '#764ba2');
    
    const gradient2 = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
    gradient2.addColorStop(0, '#56ab2f');
    gradient2.addColorStop(1, '#a8e6cf');
    
    const gradient3 = ctx.getContext('2d').createLinearGradient(0, 0, 0, 400);
    gradient3.addColorStop(0, '#3a7bd5');
    gradient3.addColorStop(1, '#00d2ff');

    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: [<?php 
                $labels = [];
                foreach ($userStats ?? [] as $role => $count) {
                    $labels[] = '"' . ucfirst(str_replace('_', ' ', $role)) . '"';
                }
                echo implode(',', $labels);
            ?>],
            datasets: [{
                data: [<?= implode(',', array_values($userStats ?? [])) ?>],
                backgroundColor: [gradient1, gradient2, gradient3, '#ffc107', '#dc3545'],
                borderWidth: 0,
                cutout: '60%'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            elements: {
                arc: {
                    borderRadius: 8
                }
            }
        }
    });
}

// Enhanced Dashboard Functionality
document.addEventListener('DOMContentLoaded', function() {
    // Animate stat numbers on load
    const statNumbers = document.querySelectorAll('.stat-number');
    statNumbers.forEach(stat => {
        const target = parseInt(stat.textContent.replace(/,/g, ''));
        let current = 0;
        const increment = target / 50;
        const timer = setInterval(() => {
            current += increment;
            if (current >= target) {
                current = target;
                clearInterval(timer);
            }
            stat.textContent = Math.floor(current).toLocaleString();
        }, 20);
    });

    // Add hover effects to cards
    const cards = document.querySelectorAll('.dashboard-card, .stat-card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-4px)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });

    // Update current time every minute
    function updateTime() {
        const timeElement = document.getElementById('current-time');
        if (timeElement) {
            const now = new Date();
            const options = { 
                year: 'numeric', 
                month: 'short', 
                day: 'numeric', 
                hour: 'numeric', 
                minute: '2-digit',
                hour12: true 
            };
            timeElement.textContent = now.toLocaleDateString('en-US', options);
        }
    }
    
    setInterval(updateTime, 60000); // Update every minute

    // Add staggered animation to cards
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry, index) => {
            if (entry.isIntersecting) {
                setTimeout(() => {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }, index * 100);
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);

    // Apply staggered animation to cards
    document.querySelectorAll('.stat-card, .dashboard-card').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(30px)';
        card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(card);
    });

    // Add pulse animation to real-time indicators
    const realtimeElements = document.querySelectorAll('[class*="fas fa-sync-alt"], [class*="fas fa-circle text-success"]');
    realtimeElements.forEach(element => {
        element.style.animation = 'pulse 2s infinite';
    });

    // Add tooltip functionality for stat cards
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach(card => {
        const statNumber = card.querySelector('.stat-number');
        const statLabel = card.querySelector('.stat-label');
        
        if (statNumber && statLabel) {
            card.setAttribute('title', `${statLabel.textContent}: ${statNumber.textContent}`);
            
            // Initialize Bootstrap tooltips if available
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                new bootstrap.Tooltip(card, {
                    placement: 'top',
                    trigger: 'hover'
                });
            }
        }
    });
});

// Refresh activity data function
async function refreshActivityData() {
    const button = document.querySelector('[onclick="refreshActivityData()"]');
    const originalHTML = button.innerHTML;
    
    // Show loading state
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;
    
    // Add loading class to activity metrics
    const metrics = document.querySelectorAll('.activity-metric');
    metrics.forEach(metric => metric.classList.add('loading'));
    
    try {
        // Fetch real data from the dashboard API
        const response = await fetch('api/dashboard-data.php', {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        
        // Update activity metrics with real data
        const metricNumbers = document.querySelectorAll('.activity-metric-number');
        const metricData = [
            data.today_activity?.logins_today || 0,
            data.today_activity?.votes_today || 0,  
            data.today_activity?.total_activities_today || 0,
            data.stats?.active_elections || 0
        ];

        metricNumbers.forEach((number, index) => {
            const currentValue = parseInt(number.textContent.replace(/,/g, '')) || 0;
            const newValue = parseInt(metricData[index]) || 0;
            
            // Update data attribute
            number.setAttribute('data-value', newValue);
            
            // Animate counter if value changed
            if (currentValue !== newValue) {
                animateCounter(number, newValue);
            }
        });
        
        // Update the date in the header
        const today = new Date().toLocaleDateString('en-US', { 
            year: 'numeric', 
            month: 'long', 
            day: 'numeric' 
        });
        const dateElement = document.querySelector('.activity-alert p');
        if (dateElement) {
            dateElement.textContent = `Real-time activity metrics for ${today}`;
        }
        
        // Remove loading states
        metrics.forEach(metric => metric.classList.remove('loading'));
        button.innerHTML = originalHTML;
        button.disabled = false;
        
        // Show success feedback
        const badge = document.querySelector('.real-time-indicator');
        if (badge) {
            badge.style.background = '#10b981';
            setTimeout(() => {
                badge.style.background = '';
            }, 1000);
        }
        
    } catch (error) {
        console.error('Error refreshing activity data:', error);
        
        // Remove loading states
        metrics.forEach(metric => metric.classList.remove('loading'));
        button.innerHTML = originalHTML;
        button.disabled = false;
        
        // Show error feedback
        const badge = document.querySelector('.real-time-indicator');
        if (badge) {
            badge.style.background = '#ef4444';
            badge.textContent = 'Error';
            setTimeout(() => {
                badge.style.background = '';
                badge.innerHTML = '<i class="fas fa-circle me-1" style="font-size: 0.6rem;"></i>Live';
            }, 2000);
        }
    }
}

// Helper function for counter animation
function animateCounter(element, targetValue) {
    const currentValue = parseInt(element.textContent.replace(/,/g, '')) || 0;
    const increment = (targetValue - currentValue) / 20;
    let current = currentValue;
    
    const timer = setInterval(() => {
        current += increment;
        if ((increment > 0 && current >= targetValue) || (increment < 0 && current <= targetValue)) {
            current = targetValue;
            clearInterval(timer);
        }
        element.textContent = Math.floor(current).toLocaleString();
    }, 25);
}

// Auto-refresh activity data every 30 seconds
setInterval(() => {
    if (document.visibilityState === 'visible') {
        refreshActivityData();
    }
}, 30000);

// Fix for user dropdown functionality - ensure Bootstrap dropdowns work
document.addEventListener('DOMContentLoaded', function() {
    // Wait for Bootstrap to load, then initialize dropdowns
    setTimeout(() => {
        const dropdownElements = document.querySelectorAll('[data-bs-toggle="dropdown"]');
        dropdownElements.forEach(element => {
            if (typeof bootstrap !== 'undefined' && bootstrap.Dropdown) {
                try {
                    new bootstrap.Dropdown(element);
                } catch (e) {
                    // Dropdown already initialized or error
                }
            }
        });
    }, 100);
});
</script>

<?php include '../includes/footer.php'; ?>