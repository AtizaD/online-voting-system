<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Users Management';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Users Management']
];

// Handle success/error messages from session
$success = '';
$error = '';

if (isset($_SESSION['users_success'])) {
    $success = $_SESSION['users_success'];
    unset($_SESSION['users_success']);
}

if (isset($_SESSION['users_error'])) {
    $error = $_SESSION['users_error'];
    unset($_SESSION['users_error']);
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get all users with role information
    $stmt = $db->prepare("
        SELECT u.*, 
               CASE 
                   WHEN u.role_id = 1 THEN 'Admin'
                   WHEN u.role_id = 2 THEN 'Election Officer'
                   WHEN u.role_id = 3 THEN 'Staff'
                   WHEN u.role_id = 4 THEN 'Student'
                   ELSE 'Unknown'
               END as role_name,
               u.last_login,
               DATE(u.created_at) as join_date
        FROM users u
        ORDER BY u.role_id ASC, u.created_at DESC
    ");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    // Get user statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_users,
            COUNT(CASE WHEN role_id = 1 THEN 1 END) as admin_count,
            COUNT(CASE WHEN role_id = 2 THEN 1 END) as election_officer_count,
            COUNT(CASE WHEN role_id = 3 THEN 1 END) as staff_count,
            COUNT(CASE WHEN role_id = 4 THEN 1 END) as student_count,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_users,
            COUNT(CASE WHEN last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as recent_logins
        FROM users
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
    // Get recent user activity
    $stmt = $db->prepare("
        SELECT u.user_id, u.first_name, u.last_name, u.email, u.last_login, u.role_id,
               CASE 
                   WHEN u.role_id = 1 THEN 'Admin'
                   WHEN u.role_id = 2 THEN 'Election Officer'
                   WHEN u.role_id = 3 THEN 'Staff'
                   WHEN u.role_id = 4 THEN 'Student'
                   ELSE 'Unknown'
               END as role_name
        FROM users u
        WHERE u.last_login IS NOT NULL
        ORDER BY u.last_login DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_activity = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError("Users data fetch error: " . $e->getMessage());
    $_SESSION['users_error'] = "Unable to load users data";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include '../../includes/header.php';
?>

<!-- Users Management Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-users-cog me-2"></i>Users Management
        </h4>
        <small class="text-muted">Monitor and manage system users</small>
    </div>
    <div class="d-flex gap-2">
        <a href="manage" class="btn btn-primary">
            <i class="fas fa-user-plus me-2"></i>Manage Users
        </a>
        <a href="roles" class="btn btn-success">
            <i class="fas fa-user-shield me-2"></i>Manage Roles
        </a>
        <a href="activity" class="btn btn-info">
            <i class="fas fa-history me-2"></i>User Activity
        </a>
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

<!-- Overview Cards -->
<div class="row mb-4">
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-primary">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_users'] ?></h3>
                <p>Total Users</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-danger">
                <i class="fas fa-user-shield"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['admin_count'] ?></h3>
                <p>Admins</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-warning">
                <i class="fas fa-user-tie"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['election_officer_count'] ?></h3>
                <p>Officers</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-info">
                <i class="fas fa-user-friends"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['staff_count'] ?></h3>
                <p>Staff</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-success">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['active_users'] ?></h3>
                <p>Active</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-secondary">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['recent_logins'] ?></h3>
                <p>This Week</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Quick Actions Panel -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-tachometer-alt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="manage" class="btn btn-outline-primary">
                        <i class="fas fa-user-plus me-2"></i>Add New User
                    </a>
                    <a href="roles" class="btn btn-outline-success">
                        <i class="fas fa-user-shield me-2"></i>Manage Roles
                    </a>
                    <a href="activity" class="btn btn-outline-info">
                        <i class="fas fa-history me-2"></i>View Activity
                    </a>
                </div>
                
                <hr class="my-3">
                
                <h6 class="fw-bold mb-2">System Stats</h6>
                <div class="small">
                    <div class="d-flex justify-content-between py-1">
                        <span>Active Rate:</span>
                        <strong><?= $stats['total_users'] > 0 ? round(($stats['active_users'] / $stats['total_users']) * 100) : 0 ?>%</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span>Recent Activity:</span>
                        <strong><?= $stats['recent_logins'] ?> users</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span>Admin Ratio:</span>
                        <strong><?= $stats['total_users'] > 0 ? round(($stats['admin_count'] / $stats['total_users']) * 100) : 0 ?>%</strong>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-history me-2"></i>Recent Activity
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($recent_activity)): ?>
                    <p class="text-muted text-center">No recent activity</p>
                <?php else: ?>
                    <?php foreach ($recent_activity as $user): ?>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <div>
                                <strong><?= sanitize($user['first_name']) ?> <?= sanitize($user['last_name']) ?></strong>
                                <br><small class="text-muted"><?= sanitize($user['email']) ?></small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?= getRoleBadgeColor($user['role_id']) ?>"><?= $user['role_name'] ?></span>
                                <br><small class="text-muted">
                                    <?= $user['last_login'] ? date('M j, g:i A', strtotime($user['last_login'])) : 'Never' ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="text-center mt-3">
                        <a href="activity" class="btn btn-sm btn-outline-primary">View All →</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Users List -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list me-2"></i>All Users
                    </h5>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" id="roleFilter" style="width: auto;">
                            <option value="">All Roles</option>
                            <option value="1">Admin</option>
                            <option value="2">Election Officer</option>
                            <option value="3">Staff</option>
                            <option value="4">Student</option>
                        </select>
                        <select class="form-select form-select-sm" id="statusFilter" style="width: auto;">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($users)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users text-muted" style="font-size: 4rem;"></i>
                        <h4 class="text-muted mt-3">No Users Found</h4>
                        <p class="text-muted">Add users to the system to get started.</p>
                        <a href="manage" class="btn btn-primary">
                            <i class="fas fa-user-plus me-2"></i>Add First User
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="usersTable">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th>Last Login</th>
                                    <th>Join Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr data-role-id="<?= $user['role_id'] ?>" 
                                        data-status="<?= $user['is_active'] ? 'active' : 'inactive' ?>">
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="user-avatar me-3">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                                <div>
                                                    <strong><?= sanitize($user['first_name']) ?> <?= sanitize($user['last_name']) ?></strong>
                                                    <br><small class="text-muted"><?= sanitize($user['email']) ?></small>
                                                    <?php if ($user['username']): ?>
                                                        <br><small class="text-muted">@<?= sanitize($user['username']) ?></small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?= getRoleBadgeColor($user['role_id']) ?>">
                                                <?= $user['role_name'] ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($user['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($user['last_login']): ?>
                                                <small class="text-muted">
                                                    <?= date('M j, Y g:i A', strtotime($user['last_login'])) ?>
                                                </small>
                                            <?php else: ?>
                                                <small class="text-muted">Never logged in</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?= date('M j, Y', strtotime($user['created_at'])) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="manage?user_id=<?= $user['user_id'] ?>" class="btn btn-outline-primary" title="Edit User">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="activity?user_id=<?= $user['user_id'] ?>" class="btn btn-outline-info" title="View Activity">
                                                    <i class="fas fa-history"></i>
                                                </a>
                                                <?php if ($user['user_id'] != $current_user['id']): ?>
                                                    <button type="button" class="btn btn-outline-<?= $user['is_active'] ? 'warning' : 'success' ?>" 
                                                            onclick="toggleUserStatus(<?= $user['user_id'] ?>, '<?= addslashes($user['first_name'] . ' ' . $user['last_name']) ?>', <?= $user['is_active'] ?>)" 
                                                            title="<?= $user['is_active'] ? 'Deactivate' : 'Activate' ?> User">
                                                        <i class="fas fa-<?= $user['is_active'] ? 'ban' : 'check' ?>"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="btn btn-outline-secondary disabled" title="Cannot modify your own account">
                                                        <i class="fas fa-lock"></i>
                                                    </span>
                                                <?php endif; ?>
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
</div>

<!-- Toggle User Status Modal -->
<div class="modal fade" id="toggleUserStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="toggleModalTitle">
                    <i class="fas fa-exclamation-triangle me-2"></i>Toggle User Status
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="toggleModalMessage"></p>
                <p class="text-warning"><small><i class="fas fa-info-circle me-1"></i>This will affect the user's ability to access the system.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="manage.php">
                    <input type="hidden" name="action" value="toggle_user_status">
                    <input type="hidden" name="user_id" id="toggle_user_id">
                    <input type="hidden" name="new_status" id="toggle_new_status">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn" id="toggleConfirmBtn">Confirm</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Users Management Styles */

.stats-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.stats-icon {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.stats-info h3 {
    font-size: 2rem;
    font-weight: 700;
    margin: 0;
    color: #212529;
}

.stats-info p {
    margin: 0;
    color: #6c757d;
    font-size: 0.9rem;
    font-weight: 500;
}

.user-avatar {
    width: 40px;
    height: 40px;
    background: #f8f9fa;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #6c757d;
}

.table th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    color: #495057;
    font-size: 0.875rem;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .stats-card {
        flex-direction: column;
        text-align: center;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
    
    .user-avatar {
        width: 35px;
        height: 35px;
        font-size: 1rem;
    }
}
</style>

<script>
// Users Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Toggle user status function
    window.toggleUserStatus = function(userId, userName, currentStatus) {
        const newStatus = currentStatus ? 0 : 1;
        const action = newStatus ? 'activate' : 'deactivate';
        const actionCap = action.charAt(0).toUpperCase() + action.slice(1);
        
        document.getElementById('toggle_user_id').value = userId;
        document.getElementById('toggle_new_status').value = newStatus;
        document.getElementById('toggleModalTitle').innerHTML = `<i class="fas fa-exclamation-triangle me-2"></i>${actionCap} User`;
        document.getElementById('toggleModalMessage').textContent = `Are you sure you want to ${action} "${userName}"?`;
        
        const confirmBtn = document.getElementById('toggleConfirmBtn');
        confirmBtn.textContent = actionCap;
        confirmBtn.className = `btn btn-${newStatus ? 'success' : 'warning'}`;
        
        const toggleModal = new bootstrap.Modal(document.getElementById('toggleUserStatusModal'));
        toggleModal.show();
    };
    
    // Filter functionality
    const roleFilter = document.getElementById('roleFilter');
    const statusFilter = document.getElementById('statusFilter');
    const table = document.getElementById('usersTable');
    
    function filterTable() {
        if (!table) return;
        
        const selectedRole = roleFilter.value;
        const selectedStatus = statusFilter.value;
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const roleId = row.dataset.roleId;
            const status = row.dataset.status;
            
            const roleMatch = !selectedRole || roleId === selectedRole;
            const statusMatch = !selectedStatus || status === selectedStatus;
            
            if (roleMatch && statusMatch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    if (roleFilter && statusFilter) {
        roleFilter.addEventListener('change', filterTable);
        statusFilter.addEventListener('change', filterTable);
    }
    
    // Auto-dismiss alerts
    setTimeout(function() {
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(function(alert) {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 500);
        });
    }, 5000);
});
</script>

<?php 
// Helper function for role badge colors
function getRoleBadgeColor($roleId) {
    switch ($roleId) {
        case 1: return 'danger';   // Admin
        case 2: return 'warning';  // Election Officer
        case 3: return 'info';     // Staff
        case 4: return 'primary';  // Student
        default: return 'secondary';
    }
}

include '../../includes/footer.php'; 
?>