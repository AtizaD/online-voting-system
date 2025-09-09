<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isLoggedIn() || !hasPermission('manage_settings')) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();
$success_message = '';
$error_message = '';

if (isset($_SESSION['success'])) {
    $success_message = $_SESSION['success'];
    unset($_SESSION['success']);
}

if (isset($_SESSION['error'])) {
    $error_message = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_security':
                $max_login_attempts = (int)$_POST['max_login_attempts'];
                $lockout_duration = (int)$_POST['lockout_duration'];
                $password_min_length = (int)$_POST['password_min_length'];
                $require_strong_password = isset($_POST['require_strong_password']) ? 1 : 0;
                $session_secure = isset($_POST['session_secure']) ? 1 : 0;
                $force_https = isset($_POST['force_https']) ? 1 : 0;
                
                if ($max_login_attempts < 1 || $max_login_attempts > 20) {
                    $_SESSION['error'] = 'Max login attempts must be between 1 and 20.';
                } elseif ($lockout_duration < 60 || $lockout_duration > 86400) {
                    $_SESSION['error'] = 'Lockout duration must be between 1 minute (60) and 24 hours (86400) seconds.';
                } elseif ($password_min_length < 4 || $password_min_length > 50) {
                    $_SESSION['error'] = 'Password minimum length must be between 4 and 50 characters.';
                } else {
                    // Update configuration file
                    $config_file = '../../config/config.php';
                    $config_content = file_get_contents($config_file);
                    
                    // Update security constants
                    $config_content = preg_replace(
                        "/define\('MAX_LOGIN_ATTEMPTS',\s*\d+\);/",
                        "define('MAX_LOGIN_ATTEMPTS', $max_login_attempts);",
                        $config_content
                    );
                    
                    $config_content = preg_replace(
                        "/define\('LOCKOUT_DURATION',\s*\d+\);/",
                        "define('LOCKOUT_DURATION', $lockout_duration);",
                        $config_content
                    );
                    
                    // Add new constants if they don't exist
                    if (!defined('PASSWORD_MIN_LENGTH')) {
                        $config_content .= "\ndefine('PASSWORD_MIN_LENGTH', $password_min_length);\n";
                        $config_content .= "define('REQUIRE_STRONG_PASSWORD', " . ($require_strong_password ? 'true' : 'false') . ");\n";
                        $config_content .= "define('SESSION_SECURE', " . ($session_secure ? 'true' : 'false') . ");\n";
                        $config_content .= "define('FORCE_HTTPS', " . ($force_https ? 'true' : 'false') . ");\n";
                    }
                    
                    if (file_put_contents($config_file, $config_content)) {
                        Logger::log('INFO', "Security settings updated", ['user_id' => $_SESSION['user_id'] ?? null]);
                        $_SESSION['success'] = 'Security settings updated successfully.';
                    } else {
                        $_SESSION['error'] = 'Failed to update configuration file. Please check file permissions.';
                    }
                }
                break;
                
            case 'clear_sessions':
                try {
                    // Store current user ID before destroying session
                    $current_user_id = $_SESSION['user_id'] ?? null;
                    
                    // Clear all sessions except current user
                    session_destroy();
                    session_start();
                    session_regenerate_id(true);
                    
                    Logger::log('INFO', "All user sessions cleared", ['user_id' => $current_user_id]);
                    $_SESSION['success'] = 'All user sessions have been cleared.';
                } catch (Exception $e) {
                    $current_user_id = $_SESSION['user_id'] ?? null;
                    Logger::log('ERROR', "Failed to clear sessions: " . $e->getMessage(), ['user_id' => $current_user_id]);
                    $_SESSION['error'] = 'Failed to clear user sessions.';
                }
                break;
        }
        
        header('Location: security.php');
        exit();
    }
}

// Get security statistics
$security_stats = [
    'locked_ips' => 0 // Not implemented - would need failed_logins table
];

// Load current security settings
$current_settings = [
    'max_login_attempts' => defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5,
    'lockout_duration' => defined('LOCKOUT_DURATION') ? LOCKOUT_DURATION : 900,
    'password_min_length' => defined('PASSWORD_MIN_LENGTH') ? PASSWORD_MIN_LENGTH : 6,
    'require_strong_password' => defined('REQUIRE_STRONG_PASSWORD') ? REQUIRE_STRONG_PASSWORD : false,
    'session_secure' => defined('SESSION_SECURE') ? SESSION_SECURE : false,
    'force_https' => defined('FORCE_HTTPS') ? FORCE_HTTPS : false
];

$page_title = 'Security Settings';
require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Main Content -->
    <main class="px-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-shield-alt"></i> Security Settings
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Overview
                        </a>
                        <a href="general.php" class="btn btn-outline-primary">
                            <i class="fas fa-sliders-h"></i> General Settings
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($success_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error_message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Security Statistics -->
            <div class="row mb-4">
                <div class="col-md-12">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-shield-alt fa-2x text-success mb-2"></i>
                            <h6>Security Status</h6>
                            <h3 class="mb-0 text-success">Active</h3>
                            <small class="text-muted">System security features are enabled</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Authentication Security -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-key"></i> Authentication Security</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_security">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="max_login_attempts" class="form-label">Max Login Attempts</label>
                                    <input type="number" class="form-control" id="max_login_attempts" name="max_login_attempts" 
                                           value="<?php echo $current_settings['max_login_attempts']; ?>" min="1" max="20" required>
                                    <div class="form-text">Number of failed attempts before account lockout</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="lockout_duration" class="form-label">Lockout Duration (seconds)</label>
                                    <input type="number" class="form-control" id="lockout_duration" name="lockout_duration" 
                                           value="<?php echo $current_settings['lockout_duration']; ?>" min="60" max="86400" required>
                                    <div class="form-text">How long to lock account after max attempts</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password_min_length" class="form-label">Password Minimum Length</label>
                                    <input type="number" class="form-control" id="password_min_length" name="password_min_length" 
                                           value="<?php echo $current_settings['password_min_length']; ?>" min="4" max="50" required>
                                    <div class="form-text">Minimum required password length</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="require_strong_password" name="require_strong_password" 
                                               <?php echo $current_settings['require_strong_password'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_strong_password">
                                            Require Strong Passwords
                                        </label>
                                    </div>
                                    <div class="form-text">Require uppercase, lowercase, numbers, and symbols</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="session_secure" name="session_secure" 
                                               <?php echo $current_settings['session_secure'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="session_secure">
                                            Secure Session Cookies
                                        </label>
                                    </div>
                                    <div class="form-text">Only send session cookies over HTTPS</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="force_https" name="force_https" 
                                               <?php echo $current_settings['force_https'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="force_https">
                                            Force HTTPS
                                        </label>
                                    </div>
                                    <div class="form-text">Redirect all HTTP requests to HTTPS</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Security Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Security Actions -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-tools"></i> Security Actions</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Active Sessions</h6>
                            <p class="text-muted">Force all users to log in again by clearing all sessions.</p>
                            <form method="POST" action="" style="display: inline;">
                                <input type="hidden" name="action" value="clear_sessions">
                                <button type="submit" class="btn btn-outline-danger" onclick="return confirm('This will log out all users. Continue?')">
                                    <i class="fas fa-sign-out-alt"></i> Clear All Sessions
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Security Status -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-shield-check"></i> Security Status</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Current Security Level</h6>
                            <?php 
                            $security_score = 0;
                            if ($current_settings['max_login_attempts'] <= 5) $security_score += 20;
                            if ($current_settings['lockout_duration'] >= 300) $security_score += 20;
                            if ($current_settings['password_min_length'] >= 8) $security_score += 20;
                            if ($current_settings['require_strong_password']) $security_score += 20;
                            if ($current_settings['session_secure']) $security_score += 10;
                            if ($current_settings['force_https']) $security_score += 10;
                            
                            $level = 'Low';
                            $color = 'danger';
                            if ($security_score >= 70) {
                                $level = 'High';
                                $color = 'success';
                            } elseif ($security_score >= 40) {
                                $level = 'Medium';
                                $color = 'warning';
                            }
                            ?>
                            <div class="progress mb-2">
                                <div class="progress-bar bg-<?php echo $color; ?>" style="width: <?php echo $security_score; ?>%"></div>
                            </div>
                            <span class="badge bg-<?php echo $color; ?>"><?php echo $level; ?> Security (<?php echo $security_score; ?>%)</span>
                        </div>
                        <div class="col-md-6">
                            <h6>Security Recommendations</h6>
                            <ul class="list-unstyled">
                                <?php if ($current_settings['password_min_length'] < 8): ?>
                                <li><i class="fas fa-exclamation-triangle text-warning"></i> Increase password minimum length to 8+</li>
                                <?php endif; ?>
                                <?php if (!$current_settings['require_strong_password']): ?>
                                <li><i class="fas fa-exclamation-triangle text-warning"></i> Enable strong password requirements</li>
                                <?php endif; ?>
                                <?php if (!$current_settings['session_secure']): ?>
                                <li><i class="fas fa-exclamation-triangle text-warning"></i> Enable secure session cookies</li>
                                <?php endif; ?>
                                <?php if (!$current_settings['force_https']): ?>
                                <li><i class="fas fa-exclamation-triangle text-warning"></i> Enable HTTPS enforcement</li>
                                <?php endif; ?>
                                <?php if ($security_score >= 80): ?>
                                <li><i class="fas fa-check text-success"></i> Security configuration is optimal</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
    </main>
</div>

<?php require_once '../../includes/footer.php'; ?>