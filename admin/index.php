<?php
$page_title = 'Admin Dashboard';
$breadcrumbs = [
    ['title' => 'Admin', 'url' => '/admin/'],
    ['title' => 'Dashboard']
];

require_once '../config/config.php';
require_once '../config/database.php';
require_once '../auth/session.php';

// Check authentication and authorization
requireAuth(['admin']);

$db = Database::getInstance()->getConnection();

try {
    // Get system statistics
    $stats = [];
    
    // Total students
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE is_active = TRUE");
    $stmt->execute();
    $stats['total_students'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Verified students
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE is_verified = TRUE AND is_active = TRUE");
    $stmt->execute();
    $stats['verified_students'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Total elections
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM elections");
    $stmt->execute();
    $stats['total_elections'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Active elections
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM elections WHERE status = 'active'");
    $stmt->execute();
    $stats['active_elections'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Total candidates
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM candidates");
    $stmt->execute();
    $stats['total_candidates'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Total votes cast today
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM votes WHERE DATE(vote_timestamp) = CURDATE()");
    $stmt->execute();
    $stats['votes_today'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Total users
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE is_active = TRUE");
    $stmt->execute();
    $stats['total_users'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Active voting sessions
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM voting_sessions WHERE status = 'active'");
    $stmt->execute();
    $stats['active_sessions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Recent elections for quick overview
    $stmt = $db->prepare("
        SELECT e.*, et.name as election_type_name, 
               u.first_name, u.last_name,
               (SELECT COUNT(*) FROM candidates c WHERE c.election_id = e.election_id) as candidate_count,
               (SELECT COUNT(*) FROM votes v WHERE v.election_id = e.election_id) as vote_count
        FROM elections e 
        JOIN election_types et ON e.election_type_id = et.election_type_id
        JOIN users u ON e.created_by = u.user_id
        ORDER BY e.created_at DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $recent_elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent audit activities
    $stmt = $db->prepare("
        SELECT al.*, u.first_name, u.last_name, u.username
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        ORDER BY al.timestamp DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Election statistics by type
    $stmt = $db->prepare("
        SELECT et.name as election_type, COUNT(e.election_id) as count,
               COUNT(CASE WHEN e.status = 'active' THEN 1 END) as active_count
        FROM election_types et
        LEFT JOIN elections e ON et.election_type_id = e.election_type_id
        GROUP BY et.election_type_id, et.name
        ORDER BY count DESC
    ");
    $stmt->execute();
    $election_by_type = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Student distribution by program
    $stmt = $db->prepare("
        SELECT p.program_name, COUNT(s.student_id) as count,
               COUNT(CASE WHEN s.is_verified = TRUE THEN 1 END) as verified_count
        FROM programs p
        LEFT JOIN students s ON p.program_id = s.program_id AND s.is_active = TRUE
        GROUP BY p.program_id, p.program_name
        ORDER BY count DESC
    ");
    $stmt->execute();
    $students_by_program = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Admin Dashboard Error: " . $e->getMessage());
    $stats = array_fill_keys(['total_students', 'verified_students', 'total_elections', 'active_elections', 'total_candidates', 'votes_today', 'total_users', 'active_sessions'], 0);
    $recent_elections = [];
    $recent_activities = [];
    $election_by_type = [];
    $students_by_program = [];
}

require_once '../includes/header.php';
?>

<div class="row mb-4">
    <!-- Statistics Cards -->
    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6 mb-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="avatar-sm rounded-circle bg-primary bg-soft">
                            <span class="avatar-title rounded-circle bg-primary">
                                <i class="fas fa-graduation-cap text-white"></i>
                            </span>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h4 class="mb-0"><?= number_format($stats['total_students']) ?></h4>
                        <p class="text-muted mb-0">Total Students</p>
                        <small class="text-success">
                            <?= $stats['verified_students'] ?> verified
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6 mb-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="avatar-sm rounded-circle bg-info bg-soft">
                            <span class="avatar-title rounded-circle bg-info">
                                <i class="fas fa-poll text-white"></i>
                            </span>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h4 class="mb-0"><?= number_format($stats['total_elections']) ?></h4>
                        <p class="text-muted mb-0">Total Elections</p>
                        <small class="text-warning">
                            <?= $stats['active_elections'] ?> active
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6 mb-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="avatar-sm rounded-circle bg-success bg-soft">
                            <span class="avatar-title rounded-circle bg-success">
                                <i class="fas fa-vote-yea text-white"></i>
                            </span>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h4 class="mb-0"><?= number_format($stats['votes_today']) ?></h4>
                        <p class="text-muted mb-0">Votes Today</p>
                        <small class="text-info">
                            <?= $stats['active_sessions'] ?> active sessions
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-lg-6 col-md-6 col-sm-6 mb-3">
        <div class="card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="flex-shrink-0">
                        <div class="avatar-sm rounded-circle bg-warning bg-soft">
                            <span class="avatar-title rounded-circle bg-warning">
                                <i class="fas fa-users text-white"></i>
                            </span>
                        </div>
                    </div>
                    <div class="flex-grow-1 ms-3">
                        <h4 class="mb-0"><?= number_format($stats['total_candidates']) ?></h4>
                        <p class="text-muted mb-0">Total Candidates</p>
                        <small class="text-secondary">
                            <?= $stats['total_users'] ?> system users
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Recent Elections -->
    <div class="col-xl-8 col-lg-7">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title mb-0">Recent Elections</h4>
            </div>
            <div class="card-body">
                <?php if (!empty($recent_elections)): ?>
                <div class="table-responsive">
                    <table class="table table-borderless table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Election</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Candidates</th>
                                <th>Votes</th>
                                <th>Created By</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_elections as $election): ?>
                            <tr>
                                <td>
                                    <a href="<?= SITE_URL ?>/admin/elections/view.php?id=<?= $election['election_id'] ?>" class="text-decoration-none">
                                        <?= sanitize($election['name']) ?>
                                    </a>
                                </td>
                                <td><?= sanitize($election['election_type_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?= 
                                        $election['status'] === 'active' ? 'success' : 
                                        ($election['status'] === 'completed' ? 'info' : 
                                        ($election['status'] === 'draft' ? 'secondary' : 'warning')) ?>">
                                        <?= ucfirst($election['status']) ?>
                                    </span>
                                </td>
                                <td><?= $election['candidate_count'] ?></td>
                                <td><?= number_format($election['vote_count']) ?></td>
                                <td><?= sanitize($election['first_name'] . ' ' . $election['last_name']) ?></td>
                                <td><?= date('M d, Y', strtotime($election['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-center mt-3">
                    <a href="<?= SITE_URL ?>/admin/elections/" class="btn btn-sm btn-outline-primary">View All Elections</a>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-poll fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No elections found</p>
                    <a href="<?= SITE_URL ?>/admin/elections/create.php" class="btn btn-primary btn-sm">Create First Election</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Actions & System Info -->
    <div class="col-xl-4 col-lg-5">
        <!-- Quick Actions -->
        <div class="card mb-3">
            <div class="card-header">
                <h4 class="card-title mb-0">Quick Actions</h4>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="<?= SITE_URL ?>/admin/elections/create.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-plus me-2"></i>Create Election
                    </a>
                    <a href="<?= SITE_URL ?>/admin/students/manage.php" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-user-plus me-2"></i>Add Student
                    </a>
                    <a href="<?= SITE_URL ?>/admin/students/verify.php" class="btn btn-outline-warning btn-sm">
                        <i class="fas fa-user-check me-2"></i>Verify Students
                    </a>
                    <a href="<?= SITE_URL ?>/admin/reports/" class="btn btn-outline-info btn-sm">
                        <i class="fas fa-chart-line me-2"></i>View Reports
                    </a>
                    <a href="<?= SITE_URL ?>/admin/voting/" class="btn btn-outline-danger btn-sm">
                        <i class="fas fa-undo me-2"></i>Reset Votes
                    </a>
                </div>
            </div>
        </div>

        <!-- System Status -->
        <div class="card">
            <div class="card-header">
                <h4 class="card-title mb-0">System Status</h4>
            </div>
            <div class="card-body">
                <div class="row g-0">
                    <div class="col-6">
                        <div class="text-center p-2 border-end">
                            <h5 class="text-success mb-1"><?= $stats['active_sessions'] ?></h5>
                            <p class="text-muted mb-0 small">Active Sessions</p>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="text-center p-2">
                            <h5 class="text-info mb-1"><?= $stats['votes_today'] ?></h5>
                            <p class="text-muted mb-0 small">Votes Today</p>
                        </div>
                    </div>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">Server Status</small>
                    <span class="badge bg-success">Online</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <small class="text-muted">Database</small>
                    <span class="badge bg-success">Connected</span>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <small class="text-muted">Last Backup</small>
                    <small class="text-muted"><?= date('M d, H:i') ?></small>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <!-- Election Distribution -->
    <div class="col-xl-6 col-lg-6">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title mb-0">Elections by Type</h4>
            </div>
            <div class="card-body">
                <?php if (!empty($election_by_type)): ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Total</th>
                                <th>Active</th>
                                <th>Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($election_by_type as $type): ?>
                            <tr>
                                <td><?= sanitize($type['election_type']) ?></td>
                                <td><?= $type['count'] ?></td>
                                <td>
                                    <?php if ($type['active_count'] > 0): ?>
                                    <span class="badge bg-success"><?= $type['active_count'] ?></span>
                                    <?php else: ?>
                                    <span class="text-muted">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($type['count'] > 0): ?>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-primary" style="width: <?= ($type['active_count'] / max($type['count'], 1)) * 100 ?>%"></div>
                                    </div>
                                    <?php else: ?>
                                    <small class="text-muted">No data</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-3">
                    <i class="fas fa-chart-pie fa-2x text-muted mb-2"></i>
                    <p class="text-muted">No election data available</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Student Distribution -->
    <div class="col-xl-6 col-lg-6">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title mb-0">Students by Program</h4>
            </div>
            <div class="card-body">
                <?php if (!empty($students_by_program)): ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Program</th>
                                <th>Total</th>
                                <th>Verified</th>
                                <th>Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students_by_program as $program): ?>
                            <tr>
                                <td><?= sanitize($program['program_name']) ?></td>
                                <td><?= $program['count'] ?></td>
                                <td><?= $program['verified_count'] ?></td>
                                <td>
                                    <?php if ($program['count'] > 0): ?>
                                    <?php $rate = ($program['verified_count'] / $program['count']) * 100; ?>
                                    <small class="text-<?= $rate >= 80 ? 'success' : ($rate >= 50 ? 'warning' : 'danger') ?>">
                                        <?= number_format($rate, 1) ?>%
                                    </small>
                                    <?php else: ?>
                                    <small class="text-muted">N/A</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-center mt-3">
                    <a href="<?= SITE_URL ?>/admin/students/" class="btn btn-sm btn-outline-primary">Manage Students</a>
                </div>
                <?php else: ?>
                <div class="text-center py-3">
                    <i class="fas fa-users fa-2x text-muted mb-2"></i>
                    <p class="text-muted">No student data available</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title mb-0">Recent Activity</h4>
            </div>
            <div class="card-body">
                <?php if (!empty($recent_activities)): ?>
                <div class="table-responsive">
                    <table class="table table-borderless">
                        <thead class="table-light">
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>IP Address</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_activities as $activity): ?>
                            <tr>
                                <td>
                                    <?php if ($activity['first_name']): ?>
                                    <?= sanitize($activity['first_name'] . ' ' . $activity['last_name']) ?>
                                    <small class="text-muted d-block"><?= sanitize($activity['username']) ?></small>
                                    <?php else: ?>
                                    <span class="text-muted">System</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= sanitize($activity['action']) ?></td>
                                <td><code><?= sanitize($activity['ip_address']) ?></code></td>
                                <td>
                                    <small class="text-muted">
                                        <?= date('M d, Y H:i', strtotime($activity['timestamp'])) ?>
                                    </small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-center mt-3">
                    <a href="<?= SITE_URL ?>/admin/reports/audit-logs.php" class="btn btn-sm btn-outline-secondary">View All Activity</a>
                </div>
                <?php else: ?>
                <div class="text-center py-4">
                    <i class="fas fa-history fa-3x text-muted mb-3"></i>
                    <p class="text-muted">No recent activity</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.avatar-sm {
    width: 40px;
    height: 40px;
}

.avatar-title {
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.bg-soft {
    background-color: rgba(var(--bs-primary-rgb), 0.1) !important;
}

.card {
    border: 1px solid #e3e6f0;
    box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
    border-radius: 0.35rem;
}

.card-header {
    background: #f8f9fc;
    border-bottom: 1px solid #e3e6f0;
}

.progress {
    background-color: #e9ecef;
}

.table-borderless td,
.table-borderless th {
    border: 0;
}

.text-decoration-none {
    text-decoration: none !important;
}

.text-decoration-none:hover {
    text-decoration: underline !important;
}
</style>

<?php require_once '../includes/footer.php'; ?>