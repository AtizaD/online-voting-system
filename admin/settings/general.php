<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isLoggedIn() || !hasPermission('manage_settings')) {
    redirectTo('auth/index.php');
}

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
            case 'update_general':
                $site_name = trim($_POST['site_name']);
                $session_timeout = (int)$_POST['session_timeout'];
                $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
                $debug_mode = isset($_POST['debug_mode']) ? 1 : 0;
                
                if (empty($site_name)) {
                    $_SESSION['error'] = 'Site name is required.';
                } elseif ($session_timeout < 300 || $session_timeout > 86400) {
                    $_SESSION['error'] = 'Session timeout must be between 5 minutes (300) and 24 hours (86400) seconds.';
                } else {
                    // Update configuration file
                    $config_file = '../../config/config.php';
                    $config_content = file_get_contents($config_file);
                    
                    // Update constants
                    $config_content = preg_replace(
                        "/define\('SITE_NAME',\s*'[^']*'\);/",
                        "define('SITE_NAME', '$site_name');",
                        $config_content
                    );
                    
                    $config_content = preg_replace(
                        "/define\('SESSION_TIMEOUT',\s*\d+\);/",
                        "define('SESSION_TIMEOUT', $session_timeout);",
                        $config_content
                    );
                    
                    $config_content = preg_replace(
                        "/define\('MAINTENANCE_MODE',\s*(true|false)\);/",
                        "define('MAINTENANCE_MODE', " . ($maintenance_mode ? 'true' : 'false') . ");",
                        $config_content
                    );
                    
                    $config_content = preg_replace(
                        "/define\('DEBUG_MODE',\s*(true|false)\);/",
                        "define('DEBUG_MODE', " . ($debug_mode ? 'true' : 'false') . ");",
                        $config_content
                    );
                    
                    if (file_put_contents($config_file, $config_content)) {
                        Logger::log('INFO', "General settings updated", $_SESSION['user_id']);
                        $_SESSION['success'] = 'General settings updated successfully. Changes will take effect on next page load.';
                    } else {
                        $_SESSION['error'] = 'Failed to update configuration file. Please check file permissions.';
                    }
                }
                break;
                
            case 'update_email':
                $smtp_host = trim($_POST['smtp_host']);
                $smtp_port = (int)$_POST['smtp_port'];
                $smtp_username = trim($_POST['smtp_username']);
                $smtp_password = $_POST['smtp_password'];
                $from_email = trim($_POST['from_email']);
                $from_name = trim($_POST['from_name']);
                $smtp_secure = $_POST['smtp_secure'];
                
                // Validate email settings
                if (!empty($smtp_host) && !filter_var($from_email, FILTER_VALIDATE_EMAIL)) {
                    $_SESSION['error'] = 'Please enter a valid sender email address.';
                } else {
                    // Create email settings array
                    $email_settings = [
                        'smtp_host' => $smtp_host,
                        'smtp_port' => $smtp_port,
                        'smtp_username' => $smtp_username,
                        'smtp_password' => $smtp_password,
                        'from_email' => $from_email,
                        'from_name' => $from_name,
                        'smtp_secure' => $smtp_secure
                    ];
                    
                    // Save to file or database
                    $settings_file = '../../config/email_settings.json';
                    if (file_put_contents($settings_file, json_encode($email_settings, JSON_PRETTY_PRINT))) {
                        Logger::log('INFO', "Email settings updated", $_SESSION['user_id']);
                        $_SESSION['success'] = 'Email settings updated successfully.';
                    } else {
                        $_SESSION['error'] = 'Failed to save email settings.';
                    }
                }
                break;
        }
        
        header('Location: general.php');
        exit();
    }
}

// Load current settings
$current_settings = [
    'site_name' => defined('SITE_NAME') ? SITE_NAME : 'E-Voting System',
    'session_timeout' => SESSION_TIMEOUT,
    'maintenance_mode' => defined('MAINTENANCE_MODE') ? MAINTENANCE_MODE : false,
    'debug_mode' => defined('DEBUG_MODE') ? DEBUG_MODE : false
];

// Load email settings
$email_settings = [
    'smtp_host' => '',
    'smtp_port' => 587,
    'smtp_username' => '',
    'smtp_password' => '',
    'from_email' => '',
    'from_name' => '',
    'smtp_secure' => 'tls'
];

$email_settings_file = '../../config/email_settings.json';
if (file_exists($email_settings_file)) {
    $loaded_settings = json_decode(file_get_contents($email_settings_file), true);
    if ($loaded_settings) {
        $email_settings = array_merge($email_settings, $loaded_settings);
    }
}

$page_title = 'General Settings';
require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Main Content -->
    <main class="px-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-sliders-h"></i> General Settings
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Overview
                        </a>
                        <a href="security.php" class="btn btn-outline-warning">
                            <i class="fas fa-shield-alt"></i> Security Settings
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

            <!-- General System Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-cog"></i> System Configuration</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_general">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="site_name" class="form-label">Site Name</label>
                                    <input type="text" class="form-control" id="site_name" name="site_name" 
                                           value="<?php echo htmlspecialchars($current_settings['site_name']); ?>" required>
                                    <div class="form-text">The name of your voting system displayed to users</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="session_timeout" class="form-label">Session Timeout (seconds)</label>
                                    <input type="number" class="form-control" id="session_timeout" name="session_timeout" 
                                           value="<?php echo $current_settings['session_timeout']; ?>" min="300" max="86400" required>
                                    <div class="form-text">User session timeout in seconds (300-86400)</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="maintenance_mode" name="maintenance_mode" 
                                               <?php echo $current_settings['maintenance_mode'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="maintenance_mode">
                                            Maintenance Mode
                                        </label>
                                    </div>
                                    <div class="form-text">Enable to put the system in maintenance mode</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="debug_mode" name="debug_mode" 
                                               <?php echo $current_settings['debug_mode'] ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="debug_mode">
                                            Debug Mode
                                        </label>
                                    </div>
                                    <div class="form-text">Enable debug mode for development (disable in production)</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update General Settings
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Email Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-envelope"></i> Email Configuration</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_email">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="smtp_host" class="form-label">SMTP Host</label>
                                    <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                           value="<?php echo htmlspecialchars($email_settings['smtp_host']); ?>"
                                           placeholder="smtp.gmail.com">
                                    <div class="form-text">SMTP server hostname</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="smtp_port" class="form-label">SMTP Port</label>
                                    <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                           value="<?php echo $email_settings['smtp_port']; ?>" min="1" max="65535">
                                    <div class="form-text">Usually 587 or 465</div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="smtp_secure" class="form-label">Security</label>
                                    <select class="form-select" id="smtp_secure" name="smtp_secure">
                                        <option value="none" <?php echo $email_settings['smtp_secure'] == 'none' ? 'selected' : ''; ?>>None</option>
                                        <option value="tls" <?php echo $email_settings['smtp_secure'] == 'tls' ? 'selected' : ''; ?>>TLS</option>
                                        <option value="ssl" <?php echo $email_settings['smtp_secure'] == 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="smtp_username" class="form-label">SMTP Username</label>
                                    <input type="text" class="form-control" id="smtp_username" name="smtp_username" 
                                           value="<?php echo htmlspecialchars($email_settings['smtp_username']); ?>"
                                           placeholder="your-email@domain.com">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="smtp_password" class="form-label">SMTP Password</label>
                                    <input type="password" class="form-control" id="smtp_password" name="smtp_password" 
                                           value="<?php echo htmlspecialchars($email_settings['smtp_password']); ?>"
                                           placeholder="Leave blank to keep current password">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="from_email" class="form-label">From Email</label>
                                    <input type="email" class="form-control" id="from_email" name="from_email" 
                                           value="<?php echo htmlspecialchars($email_settings['from_email']); ?>"
                                           placeholder="noreply@yourdomain.com">
                                    <div class="form-text">Email address used as sender</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="from_name" class="form-label">From Name</label>
                                    <input type="text" class="form-control" id="from_name" name="from_name" 
                                           value="<?php echo htmlspecialchars($email_settings['from_name']); ?>"
                                           placeholder="E-Voting System">
                                    <div class="form-text">Display name for outgoing emails</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Update Email Settings
                            </button>
                            <button type="button" class="btn btn-outline-info" onclick="testEmail()">
                                <i class="fas fa-paper-plane"></i> Test Email
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Time Zone Settings -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-clock"></i> Time Zone & Format</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="timezone" class="form-label">Time Zone</label>
                                <select class="form-select" id="timezone" name="timezone">
                                    <option value="UTC" selected>UTC - Coordinated Universal Time</option>
                                    <option value="Africa/Accra">GMT - Ghana Mean Time</option>
                                    <option value="America/New_York">EST - Eastern Time</option>
                                    <option value="Europe/London">GMT - Greenwich Mean Time</option>
                                    <option value="Asia/Tokyo">JST - Japan Standard Time</option>
                                </select>
                                <div class="form-text">Current server time: <?php echo date('Y-m-d H:i:s T'); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="date_format" class="form-label">Date Format</label>
                                <select class="form-select" id="date_format" name="date_format">
                                    <option value="Y-m-d" selected>YYYY-MM-DD (<?php echo date('Y-m-d'); ?>)</option>
                                    <option value="d/m/Y">DD/MM/YYYY (<?php echo date('d/m/Y'); ?>)</option>
                                    <option value="m/d/Y">MM/DD/YYYY (<?php echo date('m/d/Y'); ?>)</option>
                                    <option value="F j, Y">Month DD, YYYY (<?php echo date('F j, Y'); ?>)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <button type="button" class="btn btn-primary">
                            <i class="fas fa-save"></i> Update Time Settings
                        </button>
                    </div>
                </div>
            </div>
    </main>
</div>

<script>
function testEmail() {
    // This would be implemented to send a test email
    alert('Test email functionality would be implemented here.');
}
</script>

<?php require_once '../../includes/footer.php'; ?>