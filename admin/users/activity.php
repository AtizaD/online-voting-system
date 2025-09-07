<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../config/database.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

// Handle success/error messages from session
$success = '';
$error = '';

if (isset($_SESSION['activity_success'])) {
    $success = $_SESSION['activity_success'];
    unset($_SESSION['activity_success']);
}

if (isset($_SESSION['activity_error'])) {
    $error = $_SESSION['activity_error'];
    unset($_SESSION['activity_error']);
}

// Get filter parameters
$user_filter = $_GET['user_id'] ?? '';
$date_filter = $_GET['date'] ?? '';
$action_filter = $_GET['action'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;

try {
    $db = Database::getInstance()->getConnection();
    
    // Build WHERE clause for filtering
    $where_conditions = [];
    $params = [];
    
    if ($user_filter) {
        $where_conditions[] = "al.user_id = ?";
        $params[] = (int)$user_filter;
    }
    
    if ($date_filter) {
        $where_conditions[] = "DATE(al.timestamp) = ?";
        $params[] = $date_filter;
    }
    
    if ($action_filter) {
        $where_conditions[] = "al.action LIKE ?";
        $params[] = '%' . $action_filter . '%';
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Get total count for pagination
    $count_query = "
        SELECT COUNT(*) as total
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        $where_clause
    ";
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_records / $limit);
    
    // Get activity logs with user information
    $activity_query = "
        SELECT al.*, 
               u.first_name, u.last_name, u.email,
               CASE 
                   WHEN u.role_id = 1 THEN 'Admin'
                   WHEN u.role_id = 2 THEN 'Election Officer'
                   WHEN u.role_id = 3 THEN 'Staff'
                   WHEN u.role_id = 4 THEN 'Student'
                   ELSE 'Unknown'
               END as role_name,
               u.role_id
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        $where_clause
        ORDER BY al.timestamp DESC
        LIMIT ? OFFSET ?
    ";
    
    $activity_params = array_merge($params, [$limit, $offset]);
    $activity_stmt = $db->prepare($activity_query);
    $activity_stmt->execute($activity_params);
    $activities = $activity_stmt->fetchAll();
    
    // Get users for filter dropdown
    $users_stmt = $db->query("
        SELECT user_id, first_name, last_name, email 
        FROM users 
        ORDER BY first_name, last_name
    ");
    $users = $users_stmt->fetchAll();
    
    // Get activity statistics
    $stats_stmt = $db->query("
        SELECT 
            COUNT(*) as total_activities,
            COUNT(DISTINCT user_id) as active_users,
            COUNT(CASE WHEN timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 END) as last_24h,
            COUNT(CASE WHEN timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as last_7d
        FROM audit_logs
    ");
    $stats = $stats_stmt->fetch();
    
    // Get most common actions
    $actions_stmt = $db->query("
        SELECT action, COUNT(*) as count
        FROM audit_logs
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY action
        ORDER BY count DESC
        LIMIT 10
    ");
    $common_actions = $actions_stmt->fetchAll();
    
} catch (Exception $e) {
    logError("User activity fetch error: " . $e->getMessage());
    $error = "Unable to load activity data";
    $activities = [];
    $users = [];
    $stats = ['total_activities' => 0, 'active_users' => 0, 'last_24h' => 0, 'last_7d' => 0];
    $common_actions = [];
    $total_pages = 1;
}

$page_title = 'User Activity';
require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- Main Content -->
        <main class="col-12 px-md-4">
            <!-- Page Header -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-chart-line me-2"></i>User Activity
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="index" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Users
                        </a>
                        <a href="manage" class="btn btn-sm btn-primary">
                            <i class="fas fa-users-cog me-1"></i>Manage Users
                        </a>
                    </div>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?= sanitize($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?= sanitize($success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="row mb-4">
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card bg-primary text-white h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-white-75">Total Activities</div>
                                    <div class="h3 mb-0"><?= number_format($stats['total_activities']) ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-chart-bar fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card bg-success text-white h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-white-75">Active Users</div>
                                    <div class="h3 mb-0"><?= number_format($stats['active_users']) ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-users fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card bg-warning text-white h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-white-75">Last 24 Hours</div>
                                    <div class="h3 mb-0"><?= number_format($stats['last_24h']) ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-clock fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-3 col-md-6 mb-3">
                    <div class="card bg-info text-white h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-white-75">Last 7 Days</div>
                                    <div class="h3 mb-0"><?= number_format($stats['last_7d']) ?></div>
                                </div>
                                <div class="align-self-center">
                                    <i class="fas fa-calendar-week fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Activity Log -->
                <div class="col-lg-9">
                    <div class="card">
                        <div class="card-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-list me-2"></i>Activity Log
                                    <?php if ($total_records > 0): ?>
                                        <span class="badge bg-secondary"><?= number_format($total_records) ?> records</span>
                                    <?php endif; ?>
                                </h5>
                                
                                <!-- Filters -->
                                <form method="GET" class="d-flex gap-2">
                                    <select name="user_id" class="form-select form-select-sm" style="width: auto;">
                                        <option value="">All Users</option>
                                        <?php foreach ($users as $user): ?>
                                            <option value="<?= $user['user_id'] ?>" <?= $user_filter == $user['user_id'] ? 'selected' : '' ?>>
                                                <?= sanitize($user['first_name'] . ' ' . $user['last_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    
                                    <input type="date" name="date" class="form-control form-control-sm" 
                                           style="width: auto;" value="<?= sanitize($date_filter) ?>">
                                    
                                    <input type="text" name="action" class="form-control form-control-sm" 
                                           placeholder="Search actions..." style="width: 150px;" value="<?= sanitize($action_filter) ?>">
                                    
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-search"></i>
                                    </button>
                                    
                                    <a href="activity" class="btn btn-sm btn-outline-secondary">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (empty($activities)): ?>
                                <div class="text-center py-5">
                                    <i class="fas fa-chart-line text-muted" style="font-size: 4rem;"></i>
                                    <h4 class="text-muted mt-3">No Activity Found</h4>
                                    <p class="text-muted">
                                        <?php if ($user_filter || $date_filter || $action_filter): ?>
                                            No activities match your current filters.
                                        <?php else: ?>
                                            User activities will appear here as they occur.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Time</th>
                                                <th>User</th>
                                                <th>Action</th>
                                                <th>IP Address</th>
                                                <th>Details</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($activities as $activity): ?>
                                                <tr>
                                                    <td>
                                                        <div class="text-nowrap">
                                                            <strong><?= date('M j, Y', strtotime($activity['timestamp'])) ?></strong>
                                                            <br><small class="text-muted"><?= date('g:i A', strtotime($activity['timestamp'])) ?></small>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <?php if ($activity['user_id']): ?>
                                                            <div>
                                                                <strong><?= sanitize($activity['first_name'] . ' ' . $activity['last_name']) ?></strong>
                                                                <br><small class="text-muted"><?= sanitize($activity['email']) ?></small>
                                                                <span class="badge bg-<?= getRoleColor($activity['role_id']) ?> badge-sm">
                                                                    <?= $activity['role_name'] ?>
                                                                </span>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted">System</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-<?= getActionBadgeColor($activity['action']) ?>">
                                                            <?= sanitize($activity['action']) ?>
                                                        </span>
                                                    </td>
                                                    <td>
                                                        <code class="text-muted"><?= sanitize($activity['ip_address']) ?></code>
                                                    </td>
                                                    <td>
                                                        <?php if ($activity['user_agent']): ?>
                                                            <small class="text-muted" title="<?= sanitize($activity['user_agent']) ?>">
                                                                <?= truncateText(sanitize($activity['user_agent']), 50) ?>
                                                            </small>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <!-- Pagination -->
                                <?php if ($total_pages > 1): ?>
                                    <nav aria-label="Activity pagination" class="mt-4">
                                        <ul class="pagination justify-content-center">
                                            <?php if ($page > 1): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?= $page - 1 ?><?= $user_filter ? '&user_id=' . $user_filter : '' ?><?= $date_filter ? '&date=' . $date_filter : '' ?><?= $action_filter ? '&action=' . $action_filter : '' ?>">
                                                        Previous
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                            
                                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                                    <a class="page-link" href="?page=<?= $i ?><?= $user_filter ? '&user_id=' . $user_filter : '' ?><?= $date_filter ? '&date=' . $date_filter : '' ?><?= $action_filter ? '&action=' . $action_filter : '' ?>">
                                                        <?= $i ?>
                                                    </a>
                                                </li>
                                            <?php endfor; ?>
                                            
                                            <?php if ($page < $total_pages): ?>
                                                <li class="page-item">
                                                    <a class="page-link" href="?page=<?= $page + 1 ?><?= $user_filter ? '&user_id=' . $user_filter : '' ?><?= $date_filter ? '&date=' . $date_filter : '' ?><?= $action_filter ? '&action=' . $action_filter : '' ?>">
                                                        Next
                                                    </a>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </nav>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Sidebar -->
                <div class="col-lg-3">
                    <!-- Common Actions -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-fire me-2"></i>Common Actions (30 days)
                            </h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($common_actions)): ?>
                                <p class="text-muted text-center">No activity data</p>
                            <?php else: ?>
                                <?php foreach ($common_actions as $action): ?>
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <span class="badge bg-<?= getActionBadgeColor($action['action']) ?>">
                                            <?= sanitize($action['action']) ?>
                                        </span>
                                        <strong><?= number_format($action['count']) ?></strong>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Export Options -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="card-title mb-0">
                                <i class="fas fa-download me-2"></i>Export Options
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="?export=csv<?= $user_filter ? '&user_id=' . $user_filter : '' ?><?= $date_filter ? '&date=' . $date_filter : '' ?><?= $action_filter ? '&action=' . $action_filter : '' ?>" 
                                   class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-file-csv me-2"></i>Export CSV
                                </a>
                                <a href="../reports/audit-logs" class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-file-alt me-2"></i>Full Audit Report
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<style>
.opacity-75 {
    opacity: 0.75;
}

.text-white-75 {
    color: rgba(255, 255, 255, 0.75) !important;
}

.badge-sm {
    font-size: 0.7rem;
}

.table th {
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    color: #495057;
}

.page-link {
    color: #6c757d;
}

.page-item.active .page-link {
    background-color: #007bff;
    border-color: #007bff;
}

.table-responsive {
    border-radius: 0.375rem;
}

code {
    font-size: 0.8rem;
    color: #6c757d;
}
</style>

<?php 
// Helper functions
function getRoleColor($roleId) {
    switch ($roleId) {
        case 1: return 'danger';   // Admin
        case 2: return 'warning';  // Election Officer
        case 3: return 'info';     // Staff
        case 4: return 'primary';  // Student
        default: return 'secondary';
    }
}

function getActionBadgeColor($action) {
    $action = strtolower($action);
    if (strpos($action, 'login') !== false) return 'success';
    if (strpos($action, 'logout') !== false) return 'warning';
    if (strpos($action, 'create') !== false || strpos($action, 'add') !== false) return 'success';
    if (strpos($action, 'update') !== false || strpos($action, 'edit') !== false) return 'info';
    if (strpos($action, 'delete') !== false || strpos($action, 'remove') !== false) return 'danger';
    if (strpos($action, 'vote') !== false) return 'primary';
    return 'secondary';
}

function truncateText($text, $length = 50) {
    return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
}

require_once '../../includes/footer.php'; 
?>