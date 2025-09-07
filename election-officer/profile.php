<?php
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth(['election_officer']);

$page_title = 'Profile Management';
$breadcrumbs = [
    ['title' => 'Election Officer', 'url' => SITE_URL . 'election-officer/'],
    ['title' => 'Profile']
];

$db = Database::getInstance()->getConnection();
$user = getCurrentUser();
$message = '';
$error = '';

// Get full user profile
$stmt = $db->prepare("
    SELECT u.*, ur.role_name
    FROM users u
    JOIN user_roles ur ON u.role_id = ur.role_id
    WHERE u.user_id = ?
");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_profile') {
        $first_name = sanitize($_POST['first_name'] ?? '');
        $last_name = sanitize($_POST['last_name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $phone = sanitize($_POST['phone'] ?? '');
        
        // Validation
        $errors = [];
        if (empty($first_name)) $errors[] = 'First name is required';
        if (empty($last_name)) $errors[] = 'Last name is required';
        if (empty($email)) $errors[] = 'Email is required';
        if (!validateEmail($email)) $errors[] = 'Invalid email format';
        
        // Check if email exists for other users
        $stmt = $db->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) {
            $errors[] = 'Email already exists for another user';
        }
        
        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    UPDATE users 
                    SET first_name = ?, last_name = ?, email = ?, phone = ?, updated_at = NOW()
                    WHERE user_id = ?
                ");
                $stmt->execute([$first_name, $last_name, $email, $phone, $user['id']]);
                
                // Update session data
                $_SESSION['user']['first_name'] = $first_name;
                $_SESSION['user']['last_name'] = $last_name;
                $_SESSION['user']['email'] = $email;
                
                logActivity('profile_update', 'Profile information updated');
                $message = 'Profile updated successfully!';
                
                // Refresh profile data
                $stmt = $db->prepare("
                    SELECT u.*, ur.role_name
                    FROM users u
                    JOIN user_roles ur ON u.role_id = ur.role_id
                    WHERE u.user_id = ?
                ");
                $stmt->execute([$user['id']]);
                $profile = $stmt->fetch();
                
            } catch (Exception $e) {
                $error = 'Failed to update profile. Please try again.';
                error_log("Profile update error: " . $e->getMessage());
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
    
    elseif ($action === 'change_password') {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validation
        $errors = [];
        if (empty($current_password)) $errors[] = 'Current password is required';
        if (empty($new_password)) $errors[] = 'New password is required';
        if (strlen($new_password) < 6) $errors[] = 'New password must be at least 6 characters';
        if ($new_password !== $confirm_password) $errors[] = 'Password confirmation does not match';
        
        if (empty($errors)) {
            // Verify current password
            if (password_verify($current_password, $profile['password'])) {
                try {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET password = ?, updated_at = NOW()
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$hashed_password, $user['id']]);
                    
                    logActivity('password_change', 'Password changed successfully');
                    $message = 'Password changed successfully!';
                    
                } catch (Exception $e) {
                    $error = 'Failed to change password. Please try again.';
                    error_log("Password change error: " . $e->getMessage());
                }
            } else {
                $error = 'Current password is incorrect';
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.profile-card {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    overflow: hidden;
}

.profile-header {
    background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
    color: white;
    padding: 2rem;
    text-align: center;
}

.profile-avatar {
    width: 80px;
    height: 80px;
    background: rgba(255,255,255,0.2);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2rem;
    font-weight: 600;
    margin: 0 auto 1rem;
    border: 3px solid rgba(255,255,255,0.3);
}

.profile-name {
    font-size: 1.5rem;
    font-weight: 600;
    margin: 0;
}

.profile-role {
    opacity: 0.9;
    margin: 0.5rem 0 0;
}

.form-section {
    padding: 2rem;
}

.form-section-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
    margin-bottom: 1.5rem;
    padding-bottom: 0.75rem;
    border-bottom: 2px solid #f1f5f9;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.info-item {
    background: #f8fafc;
    padding: 1rem;
    border-radius: 0.375rem;
}

.info-label {
    font-size: 0.875rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 0.25rem;
}

.info-value {
    color: #1e293b;
    font-weight: 500;
}
</style>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $message ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $error ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- Profile Information -->
    <div class="col-lg-4 mb-4">
        <div class="profile-card">
            <div class="profile-header">
                <div class="profile-avatar">
                    <?= substr($profile['first_name'], 0, 1) . substr($profile['last_name'], 0, 1) ?>
                </div>
                <h2 class="profile-name"><?= sanitize($profile['first_name'] . ' ' . $profile['last_name']) ?></h2>
                <p class="profile-role"><?= sanitize($profile['role_name']) ?></p>
            </div>
            <div class="form-section">
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?= sanitize($profile['email']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Phone</div>
                        <div class="info-value"><?= sanitize($profile['phone'] ?? 'Not provided') ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Username</div>
                        <div class="info-value"><?= sanitize($profile['username']) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Last Login</div>
                        <div class="info-value">
                            <?= $profile['last_login'] ? date('M j, Y g:i A', strtotime($profile['last_login'])) : 'Never' ?>
                        </div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Member Since</div>
                        <div class="info-value"><?= date('M j, Y', strtotime($profile['created_at'])) ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Account Status</div>
                        <div class="info-value">
                            <span class="badge bg-success">Active</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Edit Forms -->
    <div class="col-lg-8">
        <!-- Edit Profile -->
        <div class="profile-card mb-4">
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-user-edit me-2"></i>Edit Profile Information
                </h3>
                
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" class="form-control" id="first_name" name="first_name" 
                                   value="<?= sanitize($profile['first_name']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" 
                                   value="<?= sanitize($profile['last_name']) ?>" required>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   value="<?= sanitize($profile['email']) ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="phone" class="form-label">Phone</label>
                            <input type="tel" class="form-control" id="phone" name="phone" 
                                   value="<?= sanitize($profile['phone'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Update Profile
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Change Password -->
        <div class="profile-card">
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-key me-2"></i>Change Password
                </h3>
                
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="mb-3">
                        <label for="current_password" class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" 
                                   minlength="6" required>
                            <div class="form-text">Password must be at least 6 characters long.</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                   minlength="6" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-lock me-2"></i>Change Password
                    </button>
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

document.getElementById('new_password').addEventListener('input', function() {
    const confirmPassword = document.getElementById('confirm_password');
    if (confirmPassword.value) {
        confirmPassword.dispatchEvent(new Event('input'));
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>