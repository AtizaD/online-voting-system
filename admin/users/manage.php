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

if (isset($_SESSION['users_success'])) {
    $success = $_SESSION['users_success'];
    unset($_SESSION['users_success']);
}

if (isset($_SESSION['users_error'])) {
    $error = $_SESSION['users_error'];
    unset($_SESSION['users_error']);
}

try {
    $db = Database::getInstance()->getConnection();
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'add_user') {
            $username = sanitize($_POST['username'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $first_name = sanitize($_POST['first_name'] ?? '');
            $last_name = sanitize($_POST['last_name'] ?? '');
            $role_id = (int)($_POST['role_id'] ?? 0);
            $phone = sanitize($_POST['phone'] ?? '');
            
            // Validation
            if (empty($username) || empty($email) || empty($password) || empty($first_name) || empty($last_name) || !$role_id) {
                throw new Exception('All required fields must be filled');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Invalid email format');
            }
            
            if (strlen($password) < 6) {
                throw new Exception('Password must be at least 6 characters long');
            }
            
            // Check if username or email already exists
            $check_stmt = $db->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
            $check_stmt->execute([$username, $email]);
            if ($check_stmt->fetch()) {
                throw new Exception('Username or email already exists');
            }
            
            // Hash password
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $insert_stmt = $db->prepare("
                INSERT INTO users (username, email, password_hash, first_name, last_name, role_id, phone, is_active, is_verified, created_by, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, ?, NOW())
            ");
            $insert_stmt->execute([$username, $email, $password_hash, $first_name, $last_name, $role_id, $phone, $current_user['id']]);
            
            logActivity('user_add', "Added new user: $first_name $last_name ($username)", $current_user['id']);
            $_SESSION['users_success'] = 'User added successfully';
            header('Location: manage');
            exit;
            
        } elseif ($action === 'edit_user') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $username = sanitize($_POST['username'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $first_name = sanitize($_POST['first_name'] ?? '');
            $last_name = sanitize($_POST['last_name'] ?? '');
            $role_id = (int)($_POST['role_id'] ?? 0);
            $phone = sanitize($_POST['phone'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (!$user_id || empty($username) || empty($email) || empty($first_name) || empty($last_name) || !$role_id) {
                throw new Exception('All required fields must be filled');
            }
            
            // Check if username or email exists for other users
            $check_stmt = $db->prepare("SELECT user_id FROM users WHERE (username = ? OR email = ?) AND user_id != ?");
            $check_stmt->execute([$username, $email, $user_id]);
            if ($check_stmt->fetch()) {
                throw new Exception('Username or email already exists');
            }
            
            // Update user
            $update_stmt = $db->prepare("
                UPDATE users 
                SET username = ?, email = ?, first_name = ?, last_name = ?, role_id = ?, phone = ?, is_active = ?, updated_at = NOW()
                WHERE user_id = ?
            ");
            $update_stmt->execute([$username, $email, $first_name, $last_name, $role_id, $phone, $is_active, $user_id]);
            
            logActivity('user_update', "Updated user: $first_name $last_name ($username)", $current_user['id']);
            $_SESSION['users_success'] = 'User updated successfully';
            header('Location: manage');
            exit;
            
        } elseif ($action === 'change_password') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (!$user_id || empty($new_password) || empty($confirm_password)) {
                throw new Exception('All password fields are required');
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception('Passwords do not match');
            }
            
            if (strlen($new_password) < 6) {
                throw new Exception('Password must be at least 6 characters long');
            }
            
            // Get user info for logging
            $user_stmt = $db->prepare("SELECT username, first_name, last_name FROM users WHERE user_id = ?");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch();
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Update password
            $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update_stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE user_id = ?");
            $update_stmt->execute([$password_hash, $user_id]);
            
            logActivity('password_change', "Changed password for user: {$user['first_name']} {$user['last_name']} ({$user['username']})", $current_user['id']);
            $_SESSION['users_success'] = 'Password changed successfully';
            header('Location: manage');
            exit;
            
        } elseif ($action === 'toggle_user_status') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $new_status = (int)($_POST['new_status'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('Invalid user ID');
            }
            
            if ($user_id == $current_user['id']) {
                throw new Exception('Cannot modify your own account status');
            }
            
            // Get user info
            $user_stmt = $db->prepare("SELECT username, first_name, last_name FROM users WHERE user_id = ?");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch();
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Update status
            $update_stmt = $db->prepare("UPDATE users SET is_active = ?, updated_at = NOW() WHERE user_id = ?");
            $update_stmt->execute([$new_status, $user_id]);
            
            $status_text = $new_status ? 'activated' : 'deactivated';
            logActivity('user_status_change', "User $status_text: {$user['first_name']} {$user['last_name']} ({$user['username']})", $current_user['id']);
            $_SESSION['users_success'] = "User $status_text successfully";
            header('Location: manage');
            exit;
            
        } elseif ($action === 'delete_user') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            
            if (!$user_id) {
                throw new Exception('Invalid user ID');
            }
            
            if ($user_id == $current_user['id']) {
                throw new Exception('Cannot delete your own account');
            }
            
            // Get user info
            $user_stmt = $db->prepare("SELECT username, first_name, last_name FROM users WHERE user_id = ?");
            $user_stmt->execute([$user_id]);
            $user = $user_stmt->fetch();
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            // Check if user has related records (could expand this)
            // For now, just delete the user
            $delete_stmt = $db->prepare("DELETE FROM users WHERE user_id = ?");
            $delete_stmt->execute([$user_id]);
            
            logActivity('user_delete', "Deleted user: {$user['first_name']} {$user['last_name']} ({$user['username']})", $current_user['id']);
            $_SESSION['users_success'] = 'User deleted successfully';
            header('Location: manage');
            exit;
        }
    }
    
    // Get filter parameters
    $search = $_GET['search'] ?? '';
    $role_filter = $_GET['role'] ?? '';
    $status_filter = $_GET['status'] ?? '';
    
    // Build WHERE clause
    $where_conditions = [];
    $params = [];
    
    if ($search) {
        $where_conditions[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)";
        $search_param = '%' . $search . '%';
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    if ($role_filter) {
        $where_conditions[] = "u.role_id = ?";
        $params[] = (int)$role_filter;
    }
    
    if ($status_filter === 'active') {
        $where_conditions[] = "u.is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $where_conditions[] = "u.is_active = 0";
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Get users with role information
    $users_query = "
        SELECT u.*, 
               CASE 
                   WHEN u.role_id = 1 THEN 'Admin'
                   WHEN u.role_id = 2 THEN 'Election Officer'
                   WHEN u.role_id = 3 THEN 'Staff'
                   WHEN u.role_id = 4 THEN 'Student'
                   ELSE 'Unknown'
               END as role_name
        FROM users u
        $where_clause
        ORDER BY u.role_id ASC, u.created_at DESC
    ";
    
    $users_stmt = $db->prepare($users_query);
    $users_stmt->execute($params);
    $users = $users_stmt->fetchAll();
    
    // Get user for editing if specified
    $edit_user = null;
    if (isset($_GET['edit'])) {
        $edit_id = (int)$_GET['edit'];
        $edit_stmt = $db->prepare("SELECT * FROM users WHERE user_id = ?");
        $edit_stmt->execute([$edit_id]);
        $edit_user = $edit_stmt->fetch();
    }
    
    // Check if showing add form
    $show_add_form = isset($_GET['add']);
    
} catch (Exception $e) {
    logError("Users management error: " . $e->getMessage());
    $_SESSION['users_error'] = $e->getMessage();
    header('Location: manage');
    exit;
}

$page_title = 'Manage Users';
require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- Main Content -->
        <main class="col-12 px-md-4">
            <!-- Page Header -->
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-users-cog me-2"></i>Manage Users
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="index" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                        </a>
                        <?php if (!$show_add_form && !$edit_user): ?>
                            <a href="manage?add=1" class="btn btn-sm btn-success">
                                <i class="fas fa-plus me-1"></i>Add User
                            </a>
                        <?php endif; ?>
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

            <?php if ($show_add_form || $edit_user): ?>
                <!-- Add/Edit User Form -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="fas fa-<?= $edit_user ? 'edit' : 'plus' ?> me-2"></i>
                                    <?= $edit_user ? 'Edit User' : 'Add New User' ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <input type="hidden" name="action" value="<?= $edit_user ? 'edit_user' : 'add_user' ?>">
                                    <?php if ($edit_user): ?>
                                        <input type="hidden" name="user_id" value="<?= $edit_user['user_id'] ?>">
                                    <?php endif; ?>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="first_name" name="first_name" 
                                                       value="<?= $edit_user ? sanitize($edit_user['first_name']) : '' ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="last_name" name="last_name" 
                                                       value="<?= $edit_user ? sanitize($edit_user['last_name']) : '' ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                                <input type="text" class="form-control" id="username" name="username" 
                                                       value="<?= $edit_user ? sanitize($edit_user['username']) : '' ?>" required>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                                <input type="email" class="form-control" id="email" name="email" 
                                                       value="<?= $edit_user ? sanitize($edit_user['email']) : '' ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="role_id" class="form-label">Role <span class="text-danger">*</span></label>
                                                <select class="form-select" id="role_id" name="role_id" required>
                                                    <option value="">Choose role...</option>
                                                    <option value="1" <?= ($edit_user && $edit_user['role_id'] == 1) ? 'selected' : '' ?>>Admin</option>
                                                    <option value="2" <?= ($edit_user && $edit_user['role_id'] == 2) ? 'selected' : '' ?>>Election Officer</option>
                                                    <option value="3" <?= ($edit_user && $edit_user['role_id'] == 3) ? 'selected' : '' ?>>Staff</option>
                                                    <option value="4" <?= ($edit_user && $edit_user['role_id'] == 4) ? 'selected' : '' ?>>Student</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="mb-3">
                                                <label for="phone" class="form-label">Phone</label>
                                                <input type="text" class="form-control" id="phone" name="phone" 
                                                       value="<?= $edit_user ? sanitize($edit_user['phone']) : '' ?>">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if (!$edit_user): ?>
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="mb-3">
                                                    <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                                    <input type="password" class="form-control" id="password" name="password" required minlength="6">
                                                    <div class="form-text">Minimum 6 characters</div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($edit_user): ?>
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="mb-3">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                                               <?= $edit_user['is_active'] ? 'checked' : '' ?>>
                                                        <label class="form-check-label" for="is_active">
                                                            User is active
                                                        </label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-<?= $edit_user ? 'save' : 'plus' ?> me-2"></i>
                                            <?= $edit_user ? 'Update User' : 'Add User' ?>
                                        </button>
                                        <a href="manage" class="btn btn-secondary">Cancel</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-4">
                        <?php if ($edit_user): ?>
                            <!-- Change Password -->
                            <div class="card mb-4">
                                <div class="card-header">
                                    <h6 class="card-title mb-0">
                                        <i class="fas fa-key me-2"></i>Change Password
                                    </h6>
                                </div>
                                <div class="card-body">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="change_password">
                                        <input type="hidden" name="user_id" value="<?= $edit_user['user_id'] ?>">
                                        
                                        <div class="mb-3">
                                            <label for="new_password" class="form-label">New Password</label>
                                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label for="confirm_password" class="form-label">Confirm Password</label>
                                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="6">
                                        </div>
                                        
                                        <button type="submit" class="btn btn-warning btn-sm">
                                            <i class="fas fa-key me-2"></i>Change Password
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- Danger Zone -->
                            <?php if ($edit_user['user_id'] != $current_user['id']): ?>
                                <div class="card border-danger">
                                    <div class="card-header bg-danger text-white">
                                        <h6 class="card-title mb-0">
                                            <i class="fas fa-exclamation-triangle me-2"></i>Danger Zone
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <p class="text-muted small">Permanently delete this user account. This action cannot be undone.</p>
                                        <button type="button" class="btn btn-outline-danger btn-sm" 
                                                onclick="confirmDeleteUser(<?= $edit_user['user_id'] ?>, '<?= addslashes($edit_user['first_name'] . ' ' . $edit_user['last_name']) ?>')">
                                            <i class="fas fa-trash me-2"></i>Delete User
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php else: ?>
                <!-- Users List -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h5 class="card-title mb-0">
                                        <i class="fas fa-users me-2"></i>Users
                                        <span class="badge bg-secondary"><?= count($users) ?></span>
                                    </h5>
                                    
                                    <!-- Search and Filters -->
                                    <form method="GET" class="d-flex gap-2">
                                        <input type="text" name="search" class="form-control form-control-sm" 
                                               placeholder="Search users..." style="width: 200px;" value="<?= sanitize($search) ?>">
                                        
                                        <select name="role" class="form-select form-select-sm" style="width: auto;">
                                            <option value="">All Roles</option>
                                            <option value="1" <?= $role_filter == '1' ? 'selected' : '' ?>>Admin</option>
                                            <option value="2" <?= $role_filter == '2' ? 'selected' : '' ?>>Election Officer</option>
                                            <option value="3" <?= $role_filter == '3' ? 'selected' : '' ?>>Staff</option>
                                            <option value="4" <?= $role_filter == '4' ? 'selected' : '' ?>>Student</option>
                                        </select>
                                        
                                        <select name="status" class="form-select form-select-sm" style="width: auto;">
                                            <option value="">All Status</option>
                                            <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                                            <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                        </select>
                                        
                                        <button type="submit" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-search"></i>
                                        </button>
                                        
                                        <a href="manage" class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-times"></i>
                                        </a>
                                    </form>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (empty($users)): ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-users text-muted" style="font-size: 4rem;"></i>
                                        <h4 class="text-muted mt-3">No Users Found</h4>
                                        <p class="text-muted">
                                            <?php if ($search || $role_filter || $status_filter): ?>
                                                No users match your current filters.
                                            <?php else: ?>
                                                Start by adding users to the system.
                                            <?php endif; ?>
                                        </p>
                                        <a href="manage?add=1" class="btn btn-primary">
                                            <i class="fas fa-plus me-2"></i>Add First User
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>User</th>
                                                    <th>Role</th>
                                                    <th>Status</th>
                                                    <th>Last Login</th>
                                                    <th>Created</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($users as $user): ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="user-avatar me-3">
                                                                    <i class="fas fa-user"></i>
                                                                </div>
                                                                <div>
                                                                    <strong><?= sanitize($user['first_name'] . ' ' . $user['last_name']) ?></strong>
                                                                    <br><small class="text-muted"><?= sanitize($user['email']) ?></small>
                                                                    <br><small class="text-muted">@<?= sanitize($user['username']) ?></small>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?= getRoleColor($user['role_id']) ?>">
                                                                <?= $user['role_name'] ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-<?= $user['is_active'] ? 'success' : 'secondary' ?>">
                                                                <?= $user['is_active'] ? 'Active' : 'Inactive' ?>
                                                            </span>
                                                        </td>
                                                        <td>
                                                            <small class="text-muted">
                                                                <?= $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never' ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <small class="text-muted">
                                                                <?= date('M j, Y', strtotime($user['created_at'])) ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <a href="manage?edit=<?= $user['user_id'] ?>" class="btn btn-outline-primary" title="Edit User">
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
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Toggle User Status Modal -->
<div class="modal fade" id="toggleUserStatusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i>Toggle User Status
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p id="toggleStatusMessage"></p>
                <p class="text-warning"><small><i class="fas fa-info-circle me-1"></i>This will affect the user's ability to access the system.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST">
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

<!-- Delete User Modal -->
<div class="modal fade" id="deleteUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-trash me-2"></i>Delete User
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete "<strong id="deleteUserName"></strong>"?</p>
                <p class="text-danger"><small><i class="fas fa-exclamation-triangle me-1"></i>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" id="delete_user_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete User</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
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
    background-color: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    color: #495057;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
</style>

<script>
// Toggle user status
function toggleUserStatus(userId, userName, currentStatus) {
    const newStatus = currentStatus ? 0 : 1;
    const action = newStatus ? 'activate' : 'deactivate';
    const actionCap = action.charAt(0).toUpperCase() + action.slice(1);
    
    document.getElementById('toggle_user_id').value = userId;
    document.getElementById('toggle_new_status').value = newStatus;
    document.getElementById('toggleStatusMessage').textContent = `Are you sure you want to ${action} "${userName}"?`;
    
    const confirmBtn = document.getElementById('toggleConfirmBtn');
    confirmBtn.textContent = actionCap;
    confirmBtn.className = `btn btn-${newStatus ? 'success' : 'warning'}`;
    
    const modal = new bootstrap.Modal(document.getElementById('toggleUserStatusModal'));
    modal.show();
}

// Delete user confirmation
function confirmDeleteUser(userId, userName) {
    document.getElementById('delete_user_id').value = userId;
    document.getElementById('deleteUserName').textContent = userName;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteUserModal'));
    modal.show();
}

// Password confirmation validation
document.addEventListener('DOMContentLoaded', function() {
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    
    if (newPassword && confirmPassword) {
        function validatePasswords() {
            if (confirmPassword.value && newPassword.value !== confirmPassword.value) {
                confirmPassword.setCustomValidity('Passwords do not match');
            } else {
                confirmPassword.setCustomValidity('');
            }
        }
        
        newPassword.addEventListener('input', validatePasswords);
        confirmPassword.addEventListener('input', validatePasswords);
    }
});
</script>

<?php 
// Helper function for role colors
function getRoleColor($roleId) {
    switch ($roleId) {
        case 1: return 'danger';   // Admin
        case 2: return 'warning';  // Election Officer
        case 3: return 'info';     // Staff
        case 4: return 'primary';  // Student
        default: return 'secondary';
    }
}

require_once '../../includes/footer.php'; 
?>