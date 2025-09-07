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

if (isset($_SESSION['roles_success'])) {
    $success = $_SESSION['roles_success'];
    unset($_SESSION['roles_success']);
}

if (isset($_SESSION['roles_error'])) {
    $error = $_SESSION['roles_error'];
    unset($_SESSION['roles_error']);
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Check if roles table exists, if not create basic role data
    $stmt = $db->query("SHOW TABLES LIKE 'roles'");
    $roles_table_exists = $stmt->rowCount() > 0;
    
    if (!$roles_table_exists) {
        // Create basic roles array since no roles table exists
        $roles = [
            ['role_id' => 1, 'role_name' => 'admin', 'description' => 'System Administrator with full access'],
            ['role_id' => 2, 'role_name' => 'election_officer', 'description' => 'Election Officer with voting management access'],
            ['role_id' => 3, 'role_name' => 'staff', 'description' => 'Staff member with limited access'],
            ['role_id' => 4, 'role_name' => 'student', 'description' => 'Student with voting access only']
        ];
    } else {
        // Get roles from database
        $stmt = $db->query("SELECT * FROM roles ORDER BY role_id");
        $roles = $stmt->fetchAll();
    }
    
    // Get user count for each role
    $role_user_counts = [];
    foreach ($roles as $role) {
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE role_id = ?");
        $stmt->execute([$role['role_id']]);
        $role_user_counts[$role['role_id']] = $stmt->fetch()['count'];
    }

} catch (Exception $e) {
    logError("Roles data fetch error: " . $e->getMessage());
    $error = "Unable to load roles data";
    $roles = [];
    $role_user_counts = [];
}

$page_title = 'Manage Roles';
require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- Main Content -->
        <main class="col-12 px-md-4">
            <!-- Page Header -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-user-tag me-2"></i>Manage User Roles
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

            <!-- Roles Overview -->
            <div class="row mb-4">
                <?php foreach ($roles as $role): ?>
                    <div class="col-lg-3 col-md-6 mb-4">
                        <div class="card h-100 role-card" data-role-id="<?= $role['role_id'] ?>">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-3">
                                    <div class="role-icon bg-<?= getRoleColor($role['role_id']) ?>">
                                        <i class="fas fa-<?= getRoleIcon($role['role_id']) ?>"></i>
                                    </div>
                                    <span class="badge bg-<?= getRoleColor($role['role_id']) ?>">
                                        <?= $role_user_counts[$role['role_id']] ?? 0 ?> users
                                    </span>
                                </div>
                                
                                <h5 class="card-title text-capitalize">
                                    <?= str_replace('_', ' ', sanitize($role['role_name'])) ?>
                                </h5>
                                
                                <p class="card-text text-muted small">
                                    <?= sanitize($role['description'] ?? getDefaultRoleDescription($role['role_name'])) ?>
                                </p>
                                
                                <!-- Role Permissions -->
                                <div class="mt-3">
                                    <h6 class="small text-muted mb-2">Key Permissions:</h6>
                                    <div class="permissions-list">
                                        <?php $permissions = getRolePermissions($role['role_name']); ?>
                                        <?php foreach (array_slice($permissions, 0, 3) as $permission): ?>
                                            <span class="badge bg-light text-dark me-1 mb-1">
                                                <?= str_replace('_', ' ', ucfirst($permission)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                        <?php if (count($permissions) > 3): ?>
                                            <span class="badge bg-secondary">+<?= count($permissions) - 3 ?> more</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="mt-3 d-flex gap-2">
                                    <button class="btn btn-sm btn-outline-primary" onclick="viewRoleDetails(<?= $role['role_id'] ?>, '<?= addslashes($role['role_name']) ?>')">
                                        <i class="fas fa-eye me-1"></i>View Details
                                    </button>
                                    <a href="manage?role=<?= $role['role_id'] ?>" class="btn btn-sm btn-outline-success">
                                        <i class="fas fa-users me-1"></i>View Users
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Role Management Information -->
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-info-circle me-2"></i>Role Management Information
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="text-primary">Role Hierarchy</h6>
                                    <ul class="list-group list-group-flush">
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="fas fa-crown text-danger me-2"></i>
                                                <strong>Administrator</strong>
                                                <small class="d-block text-muted">Highest level access</small>
                                            </div>
                                            <span class="badge bg-danger rounded-pill"><?= $role_user_counts[1] ?? 0 ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="fas fa-user-tie text-warning me-2"></i>
                                                <strong>Election Officer</strong>
                                                <small class="d-block text-muted">Manages elections and voting</small>
                                            </div>
                                            <span class="badge bg-warning rounded-pill"><?= $role_user_counts[2] ?? 0 ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="fas fa-user-friends text-info me-2"></i>
                                                <strong>Staff</strong>
                                                <small class="d-block text-muted">Assists with student management</small>
                                            </div>
                                            <span class="badge bg-info rounded-pill"><?= $role_user_counts[3] ?? 0 ?></span>
                                        </li>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="fas fa-graduation-cap text-primary me-2"></i>
                                                <strong>Student</strong>
                                                <small class="d-block text-muted">Can participate in voting</small>
                                            </div>
                                            <span class="badge bg-primary rounded-pill"><?= $role_user_counts[4] ?? 0 ?></span>
                                        </li>
                                    </ul>
                                </div>
                                
                                <div class="col-md-6">
                                    <h6 class="text-primary">Permission Matrix</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm">
                                            <thead>
                                                <tr>
                                                    <th>Permission</th>
                                                    <th><i class="fas fa-crown text-danger" title="Admin"></i></th>
                                                    <th><i class="fas fa-user-tie text-warning" title="Officer"></i></th>
                                                    <th><i class="fas fa-user-friends text-info" title="Staff"></i></th>
                                                    <th><i class="fas fa-graduation-cap text-primary" title="Student"></i></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr>
                                                    <td>Manage Users</td>
                                                    <td><i class="fas fa-check text-success"></i></td>
                                                    <td><i class="fas fa-times text-danger"></i></td>
                                                    <td><i class="fas fa-times text-danger"></i></td>
                                                    <td><i class="fas fa-times text-danger"></i></td>
                                                </tr>
                                                <tr>
                                                    <td>Manage Elections</td>
                                                    <td><i class="fas fa-check text-success"></i></td>
                                                    <td><i class="fas fa-check text-success"></i></td>
                                                    <td><i class="fas fa-times text-danger"></i></td>
                                                    <td><i class="fas fa-times text-danger"></i></td>
                                                </tr>
                                                <tr>
                                                    <td>Manage Students</td>
                                                    <td><i class="fas fa-check text-success"></i></td>
                                                    <td><i class="fas fa-check text-success"></i></td>
                                                    <td><i class="fas fa-check text-success"></i></td>
                                                    <td><i class="fas fa-times text-danger"></i></td>
                                                </tr>
                                                <tr>
                                                    <td>Vote</td>
                                                    <td><i class="fas fa-times text-muted"></i></td>
                                                    <td><i class="fas fa-times text-muted"></i></td>
                                                    <td><i class="fas fa-times text-muted"></i></td>
                                                    <td><i class="fas fa-check text-success"></i></td>
                                                </tr>
                                                <tr>
                                                    <td>View Reports</td>
                                                    <td><i class="fas fa-check text-success"></i></td>
                                                    <td><i class="fas fa-check text-success"></i></td>
                                                    <td><i class="fas fa-times text-danger"></i></td>
                                                    <td><i class="fas fa-times text-danger"></i></td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-chart-pie me-2"></i>Role Distribution
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php 
                            $total_users = array_sum($role_user_counts);
                            ?>
                            <?php if ($total_users > 0): ?>
                                <?php foreach ($roles as $role): ?>
                                    <?php 
                                    $count = $role_user_counts[$role['role_id']] ?? 0;
                                    $percentage = $total_users > 0 ? round(($count / $total_users) * 100, 1) : 0;
                                    ?>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <div>
                                            <strong><?= str_replace('_', ' ', ucfirst(sanitize($role['role_name']))) ?></strong>
                                            <small class="d-block text-muted"><?= $count ?> users</small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-<?= getRoleColor($role['role_id']) ?>"><?= $percentage ?>%</span>
                                        </div>
                                    </div>
                                    <div class="progress mb-3" style="height: 6px;">
                                        <div class="progress-bar bg-<?= getRoleColor($role['role_id']) ?>" 
                                             style="width: <?= $percentage ?>%"></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p class="text-muted text-center">No users in the system yet.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="fas fa-shield-alt me-2"></i>Security Notes
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="small text-muted">
                                <ul class="mb-0">
                                    <li>Admin role has full system access</li>
                                    <li>Election Officers can manage elections and students</li>
                                    <li>Staff can only assist with student management</li>
                                    <li>Students can only vote and view results</li>
                                    <li>Role changes take effect immediately</li>
                                    <li>Always maintain at least one admin user</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Role Details Modal -->
<div class="modal fade" id="roleDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-tag me-2"></i>Role Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="roleDetailsContent">
                    <!-- Content will be loaded dynamically -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
.role-card {
    transition: transform 0.2s, box-shadow 0.2s;
}

.role-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}

.role-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.permissions-list .badge {
    font-size: 0.7rem;
}

.progress {
    border-radius: 10px;
}

.table th {
    font-size: 0.8rem;
    padding: 0.5rem 0.75rem;
}

.table td {
    font-size: 0.8rem;
    padding: 0.5rem 0.75rem;
}
</style>

<script>
// Role Details Modal
function viewRoleDetails(roleId, roleName) {
    const modal = new bootstrap.Modal(document.getElementById('roleDetailsModal'));
    const content = document.getElementById('roleDetailsContent');
    
    // Show loading
    content.innerHTML = '<div class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    modal.show();
    
    // Get role permissions
    const permissions = getRolePermissions(roleName);
    
    // Generate content
    const roleColor = getRoleColorJs(roleId);
    const roleIcon = getRoleIconJs(roleId);
    
    content.innerHTML = `
        <div class="row">
            <div class="col-md-4">
                <div class="text-center mb-4">
                    <div class="role-icon bg-${roleColor} mx-auto mb-3" style="width: 80px; height: 80px; font-size: 2rem;">
                        <i class="fas fa-${roleIcon}"></i>
                    </div>
                    <h4 class="text-capitalize">${roleName.replace('_', ' ')}</h4>
                    <p class="text-muted">${getDefaultRoleDescriptionJs(roleName)}</p>
                </div>
            </div>
            <div class="col-md-8">
                <h6>Permissions</h6>
                <div class="row">
                    ${permissions.map(permission => `
                        <div class="col-md-6 mb-2">
                            <span class="badge bg-light text-dark">
                                <i class="fas fa-check text-success me-1"></i>
                                ${permission.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase())}
                            </span>
                        </div>
                    `).join('')}
                </div>
            </div>
        </div>
    `;
}

// Helper functions for JavaScript
function getRolePermissions(roleName) {
    const permissions = {
        'admin': ['manage_users', 'manage_students', 'manage_elections', 'manage_candidates', 'view_results', 'manage_system'],
        'election_officer': ['manage_elections', 'manage_candidates', 'monitor_voting', 'view_results', 'verify_students'],
        'staff': ['manage_students', 'verify_students', 'assist_voting'],
        'student': ['vote', 'view_candidates', 'view_results']
    };
    return permissions[roleName] || [];
}

function getRoleColorJs(roleId) {
    const colors = {1: 'danger', 2: 'warning', 3: 'info', 4: 'primary'};
    return colors[roleId] || 'secondary';
}

function getRoleIconJs(roleId) {
    const icons = {1: 'crown', 2: 'user-tie', 3: 'user-friends', 4: 'graduation-cap'};
    return icons[roleId] || 'user';
}

function getDefaultRoleDescriptionJs(roleName) {
    const descriptions = {
        'admin': 'System Administrator with full access',
        'election_officer': 'Election Officer with voting management access',
        'staff': 'Staff member with limited access',
        'student': 'Student with voting access only'
    };
    return descriptions[roleName] || 'User role';
}
</script>

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

function getRoleIcon($roleId) {
    switch ($roleId) {
        case 1: return 'crown';      // Admin
        case 2: return 'user-tie';   // Election Officer
        case 3: return 'user-friends'; // Staff
        case 4: return 'graduation-cap'; // Student
        default: return 'user';
    }
}

function getDefaultRoleDescription($roleName) {
    switch ($roleName) {
        case 'admin': return 'System Administrator with full access';
        case 'election_officer': return 'Election Officer with voting management access';
        case 'staff': return 'Staff member with limited access';
        case 'student': return 'Student with voting access only';
        default: return 'User role';
    }
}

function getRolePermissions($roleName) {
    global $user_permissions;
    return $user_permissions[$roleName] ?? [];
}

require_once '../../includes/footer.php'; 
?>