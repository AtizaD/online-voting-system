<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

if (!isLoggedIn() || !hasPermission('manage_settings')) {
    redirectTo('auth/index.php');
}

$db = Database::getInstance()->getConnection();

// Get current system settings
$current_settings = [
    'site_name' => defined('SITE_NAME') ? SITE_NAME : 'E-Voting System',
    'site_url' => SITE_URL,
    'session_timeout' => SESSION_TIMEOUT,
    'maintenance_mode' => defined('MAINTENANCE_MODE') ? MAINTENANCE_MODE : false,
    'debug_mode' => defined('DEBUG_MODE') ? DEBUG_MODE : false,
    'max_login_attempts' => defined('MAX_LOGIN_ATTEMPTS') ? MAX_LOGIN_ATTEMPTS : 5,
    'lockout_duration' => defined('LOCKOUT_DURATION') ? LOCKOUT_DURATION : 900
];

// Get database info
$db_info = [];
try {
    $stmt = $db->query("SELECT VERSION() as version");
    $db_info['mysql_version'] = $stmt->fetchColumn();
    
    $stmt = $db->query("SELECT DATABASE() as name");
    $db_info['database_name'] = $stmt->fetchColumn();
    
    $stmt = $db->query("SHOW TABLE STATUS");
    $tables = $stmt->fetchAll();
    $db_info['total_tables'] = count($tables);
    
    $total_size = 0;
    foreach ($tables as $table) {
        $total_size += $table['Data_length'] + $table['Index_length'];
    }
    $db_info['database_size'] = formatBytes($total_size);
} catch (Exception $e) {
    $db_info['error'] = 'Could not retrieve database information';
}

// Get system info
$system_info = [
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'document_root' => $_SERVER['DOCUMENT_ROOT'],
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time')
];

// Get log file sizes
$log_files = [
    'access.log' => '../../logs/access.log',
    'error.log' => '../../logs/error.log',
    'security.log' => '../../logs/security.log',
    'audit.log' => '../../logs/audit.log'
];

$log_stats = [];
foreach ($log_files as $name => $path) {
    if (file_exists($path)) {
        $log_stats[$name] = [
            'size' => formatBytes(filesize($path)),
            'lines' => count(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)),
            'last_modified' => date('M j, Y H:i', filemtime($path))
        ];
    } else {
        $log_stats[$name] = [
            'size' => '0 B',
            'lines' => 0,
            'last_modified' => 'Never'
        ];
    }
}

// formatBytes() function is available from includes/functions.php

$page_title = 'System Settings';
require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Main Content -->
    <main class="px-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-cogs"></i> System Settings
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="general.php" class="btn btn-primary">
                            <i class="fas fa-sliders-h"></i> General Settings
                        </a>
                        <a href="security.php" class="btn btn-outline-warning">
                            <i class="fas fa-shield-alt"></i> Security
                        </a>
                        <a href="backup.php" class="btn btn-outline-info">
                            <i class="fas fa-database"></i> Backup
                        </a>
                        <a href="logs.php" class="btn btn-outline-secondary">
                            <i class="fas fa-file-alt"></i> System Logs
                        </a>
                    </div>
                </div>
            </div>

            <!-- System Status Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-server fa-2x text-success mb-2"></i>
                            <h6>System Status</h6>
                            <span class="badge bg-success">Online</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-database fa-2x text-info mb-2"></i>
                            <h6>Database</h6>
                            <span class="badge bg-info"><?php echo $db_info['total_tables'] ?? 0; ?> Tables</span>
                            <br><small class="text-muted"><?php echo $db_info['database_size'] ?? '0 B'; ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-file-alt fa-2x text-warning mb-2"></i>
                            <h6>Log Files</h6>
                            <span class="badge bg-warning"><?php echo array_sum(array_column($log_stats, 'lines')); ?> Entries</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-<?php echo $current_settings['maintenance_mode'] ? 'tools text-danger' : 'check-circle text-success'; ?> fa-2x mb-2"></i>
                            <h6>Maintenance</h6>
                            <span class="badge bg-<?php echo $current_settings['maintenance_mode'] ? 'danger' : 'success'; ?>">
                                <?php echo $current_settings['maintenance_mode'] ? 'Active' : 'Normal'; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Current Settings Overview -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-sliders-h"></i> Current Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Site Name:</strong></td>
                                    <td><?php echo htmlspecialchars($current_settings['site_name']); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Site URL:</strong></td>
                                    <td><small><?php echo htmlspecialchars($current_settings['site_url']); ?></small></td>
                                </tr>
                                <tr>
                                    <td><strong>Session Timeout:</strong></td>
                                    <td><?php echo $current_settings['session_timeout']; ?> seconds</td>
                                </tr>
                                <tr>
                                    <td><strong>Debug Mode:</strong></td>
                                    <td>
                                        <span class="badge bg-<?php echo $current_settings['debug_mode'] ? 'warning' : 'success'; ?>">
                                            <?php echo $current_settings['debug_mode'] ? 'Enabled' : 'Disabled'; ?>
                                        </span>
                                    </td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Login Attempts:</strong></td>
                                    <td><?php echo $current_settings['max_login_attempts']; ?> max</td>
                                </tr>
                                <tr>
                                    <td><strong>Lockout Duration:</strong></td>
                                    <td><?php echo $current_settings['lockout_duration']; ?> seconds</td>
                                </tr>
                                <tr>
                                    <td><strong>PHP Version:</strong></td>
                                    <td><?php echo $system_info['php_version']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>MySQL Version:</strong></td>
                                    <td><?php echo $db_info['mysql_version'] ?? 'Unknown'; ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- System Information -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-info-circle"></i> System Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Server Environment</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Server Software:</strong></td>
                                    <td><small><?php echo htmlspecialchars($system_info['server_software']); ?></small></td>
                                </tr>
                                <tr>
                                    <td><strong>Document Root:</strong></td>
                                    <td><small><?php echo htmlspecialchars($system_info['document_root']); ?></small></td>
                                </tr>
                                <tr>
                                    <td><strong>Upload Max Size:</strong></td>
                                    <td><?php echo $system_info['upload_max_filesize']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>POST Max Size:</strong></td>
                                    <td><?php echo $system_info['post_max_size']; ?></td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>PHP Configuration</h6>
                            <table class="table table-sm">
                                <tr>
                                    <td><strong>Memory Limit:</strong></td>
                                    <td><?php echo $system_info['memory_limit']; ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Execution Time:</strong></td>
                                    <td><?php echo $system_info['max_execution_time']; ?> seconds</td>
                                </tr>
                                <tr>
                                    <td><strong>Database Name:</strong></td>
                                    <td><?php echo htmlspecialchars($db_info['database_name'] ?? 'Unknown'); ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Database Size:</strong></td>
                                    <td><?php echo $db_info['database_size'] ?? '0 B'; ?></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Log Files Status -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-file-alt"></i> Log Files Status</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($log_stats as $log_name => $stats): ?>
                        <div class="col-md-6 col-lg-3 mb-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-file-alt fa-2x text-<?php 
                                        echo strpos($log_name, 'error') !== false ? 'danger' : 
                                             (strpos($log_name, 'security') !== false ? 'warning' : 'info'); 
                                    ?> mb-2"></i>
                                    <h6><?php echo strtoupper(str_replace('.log', '', $log_name)); ?></h6>
                                    <p class="mb-1">
                                        <strong><?php echo $stats['lines']; ?></strong> lines<br>
                                        <small class="text-muted"><?php echo $stats['size']; ?></small>
                                    </p>
                                    <small class="text-muted">
                                        Updated: <?php echo $stats['last_modified']; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-center mt-3">
                        <a href="logs.php" class="btn btn-outline-primary">
                            <i class="fas fa-eye"></i> View All Logs
                        </a>
                    </div>
                </div>
            </div>
    </main>
</div>

<?php require_once '../../includes/footer.php'; ?>