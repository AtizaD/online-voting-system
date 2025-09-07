<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth(['staff']);

$page_title = 'My Profile';
$breadcrumbs = [
    ['title' => 'Staff Dashboard', 'url' => './'],
    ['title' => 'My Profile']
];

$current_user = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    try {
        $first_name = sanitize($_POST['first_name'] ?? '');
        $last_name = sanitize($_POST['last_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        
        if (empty($first_name) || empty($last_name) || empty($email)) {
            throw new Exception('All fields are required');
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Please enter a valid email address');
        }
        
        $stmt = $db->prepare("
            UPDATE users 
            SET first_name = ?, last_name = ?, email = ?, updated_at = NOW() 
            WHERE user_id = ?
        ");
        $stmt->execute([$first_name, $last_name, $email, $current_user['user_id']]);
        
        $success_message = 'Profile updated successfully';
        
        // Refresh user data
        $current_user = getCurrentUser();
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    try {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            throw new Exception('All password fields are required');
        }
        
        if ($new_password !== $confirm_password) {
            throw new Exception('New passwords do not match');
        }
        
        if (strlen($new_password) < 8) {
            throw new Exception('New password must be at least 8 characters long');
        }
        
        // Verify current password
        if (!password_verify($current_password, $current_user['password'])) {
            throw new Exception('Current password is incorrect');
        }
        
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE user_id = ?");
        $stmt->execute([$hashed_password, $current_user['user_id']]);
        
        $password_success = 'Password changed successfully';
        
    } catch (Exception $e) {
        $password_error = $e->getMessage();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.profile-card {
    background: white;
    border-radius: 0.75rem;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.profile-avatar {
    width: 120px;
    height: 120px;
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 3rem;
    margin: 0 auto 1rem;
}

.form-section {
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    padding: 1.5rem;
    background: #f8fafc;
}

.activity-item {
    display: flex;
    align-items: center;
    padding: 1rem 0;
    border-bottom: 1px solid #f1f5f9;
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 1rem;
    font-size: 0.875rem;
}

.activity-login { background: #dcfce7; color: #166534; }
.activity-update { background: #dbeafe; color: #1d4ed8; }
</style>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="row">
        <div class="col-12">
            <div class="profile-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="mb-1">My Profile</h2>
                        <p class="text-muted mb-0">Manage your account settings and preferences</p>
                    </div>
                    <div class="text-end">
                        <span class="badge bg-success">Staff Member</span>
                        <div class="small text-muted">Role: <?= ucfirst($current_user['role']) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (isset($success_message)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i><?= $success_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i><?= $error_message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($password_success)): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-lock me-2"></i><?= $password_success ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (isset($password_error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-triangle me-2"></i><?= $password_error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Profile Information -->
        <div class="col-md-4">
            <div class="profile-card">
                <div class="text-center">
                    <div class="profile-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <h5><?= sanitize($current_user['first_name'] . ' ' . $current_user['last_name']) ?></h5>
                    <p class="text-muted"><?= sanitize($current_user['email']) ?></p>
                    <span class="badge bg-primary"><?= ucfirst($current_user['role']) ?></span>
                </div>
                
                <hr>
                
                <div class="row text-center">
                    <div class="col-6">
                        <h6 class="mb-0"><?= $current_user['created_at'] ? date('M Y', strtotime($current_user['created_at'])) : 'N/A' ?></h6>
                        <small class="text-muted">Joined</small>
                    </div>
                    <div class="col-6">
                        <h6 class="mb-0"><?= $current_user['last_login'] ? date('M j', strtotime($current_user['last_login'])) : 'Never' ?></h6>
                        <small class="text-muted">Last Login</small>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="profile-card">
                <h6 class="mb-3">Recent Activity</h6>
                <div class="activity-item">
                    <div class="activity-icon activity-login">
                        <i class="fas fa-sign-in-alt"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-0">Logged in</h6>
                        <small class="text-muted"><?= $current_user['last_login'] ? date('M j, Y g:i A', strtotime($current_user['last_login'])) : 'First time login' ?></small>
                    </div>
                </div>
                
                <div class="activity-item">
                    <div class="activity-icon activity-update">
                        <i class="fas fa-user-edit"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h6 class="mb-0">Profile updated</h6>
                        <small class="text-muted"><?= $current_user['updated_at'] ? date('M j, Y g:i A', strtotime($current_user['updated_at'])) : 'Never updated' ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Settings -->
        <div class="col-md-8">
            <div class="profile-card">
                <h5 class="mb-3">Personal Information</h5>
                <form method="POST" class="form-section">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="first_name" class="form-label">First Name *</label>
                                <input type="text" class="form-control" id="first_name" name="first_name" 
                                       value="<?= sanitize($current_user['first_name']) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="last_name" class="form-label">Last Name *</label>
                                <input type="text" class="form-control" id="last_name" name="last_name" 
                                       value="<?= sanitize($current_user['last_name']) ?>" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address *</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?= sanitize($current_user['email']) ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?= ucfirst($current_user['role']) ?>" disabled>
                        <div class="form-text">Your role cannot be changed. Contact an administrator if needed.</div>
                    </div>
                    
                    <div class="text-end">
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Profile
                        </button>
                    </div>
                </form>
            </div>

            <!-- Change Password -->
            <div class="profile-card">
                <h5 class="mb-3">Change Password</h5>
                <form method="POST" class="form-section">
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password *</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="new_password" class="form-label">New Password *</label>
                                <input type="password" class="form-control" id="new_password" name="new_password" 
                                       minlength="8" required>
                                <div class="form-text">Minimum 8 characters</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label">Confirm New Password *</label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       minlength="8" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="text-end">
                        <button type="submit" name="change_password" class="btn btn-warning">
                            <i class="fas fa-key me-2"></i>Change Password
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Password confirmation validation
document.getElementById('confirm_password').addEventListener('input', function() {
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = this.value;
    
    if (confirmPassword && newPassword !== confirmPassword) {
        this.setCustomValidity('Passwords do not match');
    } else {
        this.setCustomValidity('');
    }
});

// Form submission confirmation
document.querySelector('form').addEventListener('submit', function(e) {
    if (this.querySelector('[name="change_password"]')) {
        if (!confirm('Are you sure you want to change your password?')) {
            e.preventDefault();
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>