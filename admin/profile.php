<?php
require_once '../config/config.php';
require_once '../auth/session.php';
require_once '../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Admin Profile';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => 'index'],
    ['title' => 'Profile']
];

// Handle success/error messages from session
$success = '';
$error = '';

if (isset($_SESSION['profile_success'])) {
    $success = $_SESSION['profile_success'];
    unset($_SESSION['profile_success']);
}

if (isset($_SESSION['profile_error'])) {
    $error = $_SESSION['profile_error'];
    unset($_SESSION['profile_error']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'update_profile') {
            $first_name = sanitize($_POST['first_name'] ?? '');
            $last_name = sanitize($_POST['last_name'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            
            if (empty($first_name) || empty($last_name) || empty($email)) {
                throw new Exception('All fields are required');
            }
            
            if (!validateEmail($email)) {
                throw new Exception('Invalid email format');
            }
            
            // Check if email is already used by another user
            $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $stmt->execute([$email, $current_user['id']]);
            if ($stmt->fetch()) {
                throw new Exception('Email is already in use by another user');
            }
            
            // Update profile
            $stmt = $db->prepare("
                UPDATE users 
                SET first_name = ?, last_name = ?, email = ? 
                WHERE user_id = ?
            ");
            $stmt->execute([$first_name, $last_name, $email, $current_user['id']]);
            
            // Update session data
            $_SESSION['user']['first_name'] = $first_name;
            $_SESSION['user']['last_name'] = $last_name;
            $_SESSION['user']['email'] = $email;
            $current_user = getCurrentUser();
            
            logActivity('profile_update', 'Profile updated', $current_user['id']);
            $_SESSION['profile_success'] = 'Profile updated successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'change_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                throw new Exception('All password fields are required');
            }
            
            if (strlen($new_password) < 8) {
                throw new Exception('New password must be at least 8 characters long');
            }
            
            if ($new_password !== $confirm_password) {
                throw new Exception('New passwords do not match');
            }
            
            // Verify current password
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE user_id = ?");
            $stmt->execute([$current_user['id']]);
            $user = $stmt->fetch();
            
            if (!$user || !password_verify($current_password, $user['password_hash'])) {
                throw new Exception('Current password is incorrect');
            }
            
            // Update password
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?");
            $stmt->execute([$new_hash, $current_user['id']]);
            
            logActivity('password_change', 'Password changed', $current_user['id']);
            logSecurityEvent('password_change', "Password changed for admin user: {$current_user['email']}", 'INFO');
            $_SESSION['profile_success'] = 'Password changed successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['profile_error'] = $e->getMessage();
        logError("Profile update error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get user data from database
try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare("
        SELECT u.*, ur.role_name, u.created_at, u.last_login 
        FROM users u 
        LEFT JOIN user_roles ur ON u.role_id = ur.role_id 
        WHERE u.user_id = ?
    ");
    $stmt->execute([$current_user['id']]);
    $user_data = $stmt->fetch();
} catch (Exception $e) {
    logError("Profile data fetch error: " . $e->getMessage());
    $_SESSION['profile_error'] = "Unable to load profile data";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include '../includes/header.php';
?>

<!-- Profile Header -->
<div class="profile-header mb-4">
    <div class="row align-items-center">
        <div class="col-md-8">
            <div class="d-flex align-items-center">
                <div class="profile-avatar me-3">
                    <?= strtoupper(substr($user_data['first_name'] ?? '', 0, 1) . substr($user_data['last_name'] ?? '', 0, 1)) ?>
                </div>
                <div>
                    <h3 class="mb-1"><?= sanitize($user_data['first_name'] ?? '') ?> <?= sanitize($user_data['last_name'] ?? '') ?></h3>
                    <div class="d-flex align-items-center gap-3">
                        <span class="badge bg-primary"><?= sanitize($user_data['role_name'] ?? 'Administrator') ?></span>
                        <small class="text-muted">ID: <?= $user_data['user_id'] ?? 'N/A' ?></small>
                        <small class="text-muted">
                            <i class="fas fa-calendar-alt me-1"></i>
                            Member since <?= date('M Y', strtotime($user_data['created_at'] ?? '')) ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4 text-md-end mt-3 mt-md-0">
            <div class="profile-stats">
                <div class="stat-item">
                    <i class="fas fa-clock text-success"></i>
                    <span class="ms-1">
                        Last login: <?= $user_data['last_login'] ? date('M j, g:i A', strtotime($user_data['last_login'])) : 'Never' ?>
                    </span>
                </div>
            </div>
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

<div class="row">
    <!-- Main Content -->
    <div class="col-lg-8">
        <!-- Personal Information -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-user me-2"></i>Personal Information
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="first_name" class="form-label">First Name</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" 
                                       value="<?= sanitize($user_data['first_name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="last_name" class="form-label">Last Name</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" 
                                       value="<?= sanitize($user_data['last_name'] ?? '') ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?= sanitize($user_data['email'] ?? '') ?>" required>
                        <div class="form-text">This email will be used for system notifications and account recovery</div>
                    </div>
                    
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Profile
                    </button>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-lock me-2"></i>Change Password
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" 
                                       minlength="8" required>
                                <div class="form-text">Minimum 8 characters</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       minlength="8" required>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-key me-2"></i>Change Password
                    </button>
                </form>
            </div>
        </div>
    </div>    <div class="col-lg-4">
        <!-- Account Summary -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-user-circle me-2"></i>Account Summary
                </h6>
            </div>
            <div class="card-body">
                <div class="account-info">
                    <div class="info-item">
                        <span class="info-label">Status</span>
                        <span class="badge bg-success">Active</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">User ID</span>
                        <span class="info-value"><?= $user_data['user_id'] ?? 'N/A' ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Email</span>
                        <span class="info-value text-truncate"><?= sanitize($user_data['email'] ?? '') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Account Type</span>
                        <span class="badge bg-primary"><?= sanitize($user_data['role_name'] ?? 'Administrator') ?></span>
                    </div>
                    <div class="info-item border-0">
                        <span class="info-label">Created</span>
                        <span class="info-value"><?= date('M j, Y', strtotime($user_data['created_at'] ?? '')) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Info -->
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-shield-alt me-2"></i>Security
                </h6>
            </div>
            <div class="card-body">
                <div class="security-info">
                    <div class="d-flex align-items-center mb-3">
                        <i class="fas fa-key text-primary me-3"></i>
                        <div>
                            <div class="fw-medium">Password</div>
                            <small class="text-muted">Last changed recently</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center mb-3">
                        <i class="fas fa-clock text-success me-3"></i>
                        <div>
                            <div class="fw-medium">Session</div>
                            <small class="text-muted">Active and secure</small>
                        </div>
                    </div>
                    <div class="d-flex align-items-center">
                        <i class="fas fa-globe text-info me-3"></i>
                        <div>
                            <div class="fw-medium">Access Level</div>
                            <small class="text-muted">Full system access</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Navigation -->
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="fas fa-compass me-2"></i>Quick Navigation
                </h6>
            </div>
            <div class="card-body">
                <div class="quick-links">
                    <a href="index.php" class="quick-link-item">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <a href="users/" class="quick-link-item">
                        <i class="fas fa-users-cog"></i>
                        <span>User Management</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <a href="elections/" class="quick-link-item">
                        <i class="fas fa-vote-yea"></i>
                        <span>Elections</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                    <a href="settings/" class="quick-link-item">
                        <i class="fas fa-cog"></i>
                        <span>System Settings</span>
                        <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Clean Profile Page Styles */
.profile-header {
    background: white;
    padding: 1.5rem;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e2e8f0;
}

.profile-avatar {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 600;
    flex-shrink: 0;
}

.profile-stats .stat-item {
    font-size: 0.9rem;
    color: #64748b;
}

/* Account Info Cards */
.account-info .info-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.account-info .info-item:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 500;
    color: #64748b;
    font-size: 0.9rem;
}

.info-value {
    color: #1e293b;
    font-weight: 500;
    font-size: 0.9rem;
}

/* Security Info */
.security-info .fw-medium {
    color: #1e293b;
    font-size: 0.95rem;
}

.security-info small {
    font-size: 0.825rem;
}

/* Quick Links */
.quick-links {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.quick-link-item {
    display: flex;
    align-items: center;
    padding: 0.75rem 1rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    text-decoration: none;
    color: #64748b;
    transition: all 0.2s ease;
}

.quick-link-item:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
    color: #1e293b;
    text-decoration: none;
}

.quick-link-item i:first-child {
    width: 20px;
    margin-right: 0.75rem;
    color: #94a3b8;
}

.quick-link-item span {
    flex: 1;
    font-weight: 500;
    font-size: 0.9rem;
}

.quick-link-item i:last-child {
    font-size: 0.8rem;
    opacity: 0.5;
}

.quick-link-item:hover i {
    color: #667eea;
}

/* Form Enhancements */
.card-header h5,
.card-header h6 {
    color: #1e293b;
    font-weight: 600;
}

.form-label {
    font-weight: 500;
    color: #374151;
    margin-bottom: 0.5rem;
}

.form-control {
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    padding: 0.625rem 0.875rem;
    font-size: 0.95rem;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.form-control:focus {
    border-color: #667eea;
    box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.15);
}

.form-text {
    font-size: 0.825rem;
    color: #6b7280;
    margin-top: 0.25rem;
}

/* Button Styles */
.btn {
    font-weight: 500;
    padding: 0.625rem 1.25rem;
    border-radius: 0.5rem;
    font-size: 0.9rem;
}

.btn-primary {
    background: #667eea;
    border-color: #667eea;
}

.btn-primary:hover {
    background: #5a67d8;
    border-color: #5a67d8;
}

.btn-warning {
    background: #f59e0b;
    border-color: #f59e0b;
}

.btn-warning:hover {
    background: #d97706;
    border-color: #d97706;
}

/* Alert Improvements */
.alert {
    border: none;
    border-radius: 0.5rem;
    padding: 1rem 1.25rem;
}

.alert-danger {
    background: #fef2f2;
    color: #dc2626;
    border-left: 4px solid #dc2626;
}

.alert-success {
    background: #f0fdf4;
    color: #059669;
    border-left: 4px solid #059669;
}

/* Responsive */
@media (max-width: 768px) {
    .profile-header {
        padding: 1rem;
    }
    
    .profile-avatar {
        width: 56px;
        height: 56px;
        font-size: 1.25rem;
    }
    
    .d-flex.align-items-center.gap-3 {
        flex-direction: column;
        align-items: flex-start !important;
        gap: 0.5rem !important;
    }
    
    .quick-link-item {
        padding: 1rem;
    }
    
    .info-value {
        text-align: right;
    }
}
</style>

<script>
// Simple Profile Page JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Password confirmation validation
    const confirmPasswordField = document.getElementById('confirm_password');
    const newPasswordField = document.getElementById('new_password');
    
    if (confirmPasswordField && newPasswordField) {
        function validatePasswordMatch() {
            const newPassword = newPasswordField.value;
            const confirmPassword = confirmPasswordField.value;
            
            if (confirmPassword && newPassword !== confirmPassword) {
                confirmPasswordField.setCustomValidity('Passwords do not match');
            } else {
                confirmPasswordField.setCustomValidity('');
            }
        }
        
        confirmPasswordField.addEventListener('input', validatePasswordMatch);
        newPasswordField.addEventListener('input', validatePasswordMatch);
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

<?php include '../includes/footer.php'; ?>