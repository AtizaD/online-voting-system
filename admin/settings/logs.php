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

// Handle log actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'clear_log':
            $log_type = $_POST['log_type'];
            $log_file = "../../logs/{$log_type}.log";
            
            if (file_exists($log_file)) {
                try {
                    // Try multiple methods to clear the file
                    $cleared = false;
                    
                    // Method 1: Open file in write mode (this should truncate automatically)
                    $handle = fopen($log_file, 'w');
                    if ($handle !== false) {
                        fclose($handle);
                        clearstatcache(); // Clear file stat cache
                        $cleared = (filesize($log_file) === 0);
                    }
                    
                    // Method 2: If that failed, try file_put_contents with LOCK_EX
                    if (!$cleared) {
                        $cleared = file_put_contents($log_file, '', LOCK_EX) !== false;
                    }
                    
                    // Method 3: If still failed, try rename and create new (for locked files)
                    if (!$cleared) {
                        $backup_file = $log_file . '.backup.' . date('YmdHis');
                        if (rename($log_file, $backup_file)) {
                            // Create new empty file
                            $cleared = file_put_contents($log_file, '') !== false;
                            if ($cleared) {
                                // Remove backup file
                                @unlink($backup_file);
                            } else {
                                // Restore original file if failed
                                rename($backup_file, $log_file);
                            }
                        }
                    }
                    
                    if ($cleared) {
                        // Verify the file is actually empty
                        $file_size = filesize($log_file);
                        if ($file_size === 0) {
                            // Log the action (but not to the same file being cleared)
                            if ($log_type !== 'access' && $log_type !== 'audit') {
                                try {
                                    if (class_exists('Logger')) {
                                        Logger::info("Log file cleared: {$log_type}.log by user ID: " . ($_SESSION['user']['id'] ?? 'unknown'));
                                    }
                                } catch (Exception $e) {
                                    // Ignore logging errors
                                }
                            }
                            $_SESSION['success'] = "Log file '{$log_type}.log' cleared successfully.";
                        } else {
                            $_SESSION['error'] = "Log file '{$log_type}.log' was processed but still contains {$file_size} bytes.";
                        }
                    } else {
                        $current_size = file_exists($log_file) ? filesize($log_file) : 0;
                        $_SESSION['error'] = "Failed to clear log file '{$log_type}.log'. File may be in use or locked (current size: {$current_size} bytes).";
                    }
                } catch (Exception $e) {
                    $_SESSION['error'] = "Failed to clear log file '{$log_type}.log': " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = "Log file '{$log_type}.log' does not exist.";
            }
            break;
            
        case 'download_log':
            $log_type = $_POST['log_type'];
            $log_file = "../../logs/{$log_type}.log";
            
            if (file_exists($log_file)) {
                header('Content-Type: application/octet-stream');
                header('Content-Disposition: attachment; filename="' . $log_type . '_' . date('Y-m-d') . '.log"');
                header('Content-Length: ' . filesize($log_file));
                readfile($log_file);
                exit();
            }
            break;
    }
    
    header('Location: logs.php?log=' . ($_GET['log'] ?? 'access'));
    exit();
}

// Get selected log type
$selected_log = $_GET['log'] ?? 'access';
$valid_logs = ['access', 'error', 'security', 'audit'];

if (!in_array($selected_log, $valid_logs)) {
    $selected_log = 'access';
}

// Get log files info
$log_files = [];
foreach ($valid_logs as $log_type) {
    $log_file = "../../logs/{$log_type}.log";
    
    if (file_exists($log_file)) {
        $log_files[$log_type] = [
            'exists' => true,
            'size' => filesize($log_file),
            'lines' => count(file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)),
            'modified' => filemtime($log_file),
            'readable' => is_readable($log_file)
        ];
    } else {
        $log_files[$log_type] = [
            'exists' => false,
            'size' => 0,
            'lines' => 0,
            'modified' => 0,
            'readable' => false
        ];
    }
}

// Read selected log file
$log_entries = [];
$log_file_path = "../../logs/{$selected_log}.log";

if (file_exists($log_file_path) && is_readable($log_file_path)) {
    $lines = file($log_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    // Get last 100 lines
    $lines = array_slice($lines, -100);
    $lines = array_reverse($lines); // Show newest first
    
    foreach ($lines as $line) {
        if (!empty(trim($line))) {
            $log_entries[] = parseLogEntry($line);
        }
    }
}

function parseLogEntry($line) {
    // Parse log entry format: [timestamp] [level] [user info] [ip] [uri] - message
    $entry = [
        'raw' => $line,
        'timestamp' => '',
        'level' => 'INFO',
        'user' => '',
        'ip' => '',
        'uri' => '',
        'message' => $line
    ];
    
    // Extract timestamp
    if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
        $entry['timestamp'] = $matches[1];
    }
    
    // Extract log level
    if (preg_match('/\[(DEBUG|INFO|WARNING|ERROR)\]/', $line, $matches)) {
        $entry['level'] = $matches[1];
    }
    
    // Extract user info
    if (preg_match('/\[User: (\d+) - ([^\]]+)\]/', $line, $matches)) {
        $entry['user'] = "User {$matches[1]} - {$matches[2]}";
    }
    
    // Extract IP
    if (preg_match('/\[IP: ([^\]]+)\]/', $line, $matches)) {
        $entry['ip'] = $matches[1];
    }
    
    // Extract URI
    if (preg_match('/\[URI: ([^\]]+)\]/', $line, $matches)) {
        $entry['uri'] = $matches[1];
    }
    
    // Extract message (everything after the last '] - ')
    if (preg_match('/\] - (.+)$/', $line, $matches)) {
        $entry['message'] = $matches[1];
    }
    
    return $entry;
}

// formatBytes() function is available from includes/functions.php

function getLevelBadgeClass($level) {
    switch (strtoupper($level)) {
        case 'ERROR': return 'bg-danger';
        case 'WARNING': return 'bg-warning';
        case 'DEBUG': return 'bg-secondary';
        case 'INFO':
        default: return 'bg-info';
    }
}

$page_title = 'System Logs';
require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Main Content -->
    <main class="px-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-file-alt"></i> System Logs
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Overview
                        </a>
                        <?php if ($log_files[$selected_log]['exists']): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="download_log">
                            <input type="hidden" name="log_type" value="<?php echo $selected_log; ?>">
                            <button type="submit" class="btn btn-outline-primary">
                                <i class="fas fa-download"></i> Download
                            </button>
                        </form>
                        <button type="button" class="btn btn-outline-danger" onclick="clearLog('<?php echo $selected_log; ?>')">
                            <i class="fas fa-trash"></i> Clear Log
                        </button>
                        <?php endif; ?>
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

            <!-- Log File Tabs -->
            <div class="card mb-4">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" role="tablist">
                        <?php foreach ($valid_logs as $log_type): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $selected_log === $log_type ? 'active' : ''; ?>" 
                               href="?log=<?php echo $log_type; ?>">
                                <i class="fas fa-<?php 
                                    echo $log_type === 'error' ? 'exclamation-triangle' : 
                                         ($log_type === 'security' ? 'shield-alt' : 
                                          ($log_type === 'audit' ? 'history' : 'info-circle')); 
                                ?>"></i>
                                <?php echo ucfirst($log_type); ?>
                                <?php if ($log_files[$log_type]['exists']): ?>
                                <span class="badge bg-secondary ms-1"><?php echo $log_files[$log_type]['lines']; ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="card-body">
                    <!-- Log Stats -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-file text-muted me-2"></i>
                                <div>
                                    <small class="text-muted">File Size</small>
                                    <div><?php echo $log_files[$selected_log]['exists'] ? formatBytes($log_files[$selected_log]['size']) : 'N/A'; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-list-ol text-muted me-2"></i>
                                <div>
                                    <small class="text-muted">Total Lines</small>
                                    <div><?php echo $log_files[$selected_log]['lines']; ?></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-clock text-muted me-2"></i>
                                <div>
                                    <small class="text-muted">Last Modified</small>
                                    <div>
                                        <?php echo $log_files[$selected_log]['exists'] ? 
                                            date('M j, H:i', $log_files[$selected_log]['modified']) : 'Never'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="d-flex align-items-center">
                                <i class="fas fa-eye text-muted me-2"></i>
                                <div>
                                    <small class="text-muted">Showing</small>
                                    <div>Last <?php echo count($log_entries); ?> entries</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Log Entries -->
                    <?php if (empty($log_entries)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                            <h5>No Log Entries</h5>
                            <p class="text-muted">
                                <?php if (!$log_files[$selected_log]['exists']): ?>
                                    Log file does not exist yet.
                                <?php else: ?>
                                    The log file is empty or could not be read.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th width="130">Time</th>
                                        <th width="80">Level</th>
                                        <th width="150">User</th>
                                        <th width="120">IP</th>
                                        <th>Message</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($log_entries as $entry): ?>
                                    <tr>
                                        <td>
                                            <small class="font-monospace">
                                                <?php echo $entry['timestamp'] ? date('M j H:i:s', strtotime($entry['timestamp'])) : ''; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo getLevelBadgeClass($entry['level']); ?>">
                                                <?php echo htmlspecialchars($entry['level']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($entry['user'] ?: '-'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small class="font-monospace">
                                                <?php echo htmlspecialchars($entry['ip'] ?: '-'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small>
                                                <?php if ($entry['uri']): ?>
                                                    <span class="text-primary"><?php echo htmlspecialchars($entry['uri']); ?></span> - 
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($entry['message']); ?>
                                            </small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Log Management -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-cogs"></i> Log Management</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Log Rotation</h6>
                            <p class="text-muted">Logs are automatically rotated when they reach the maximum size limit.</p>
                            <ul class="list-unstyled">
                                <li><i class="fas fa-check text-success"></i> Max size: <?php echo defined('LOG_MAX_SIZE') ? formatBytes(LOG_MAX_SIZE) : '10 MB'; ?></li>
                                <li><i class="fas fa-check text-success"></i> Retention: 30 days</li>
                                <li><i class="fas fa-check text-success"></i> Compression: Enabled</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Storage Usage</h6>
                            <?php 
                            $total_log_size = array_sum(array_column($log_files, 'size'));
                            $max_size = defined('LOG_MAX_SIZE') ? LOG_MAX_SIZE * 4 : 40 * 1024 * 1024; // 4 log files
                            $usage_percent = $max_size > 0 ? min(100, ($total_log_size / $max_size) * 100) : 0;
                            ?>
                            <div class="progress mb-2">
                                <div class="progress-bar <?php echo $usage_percent > 80 ? 'bg-danger' : ($usage_percent > 60 ? 'bg-warning' : 'bg-success'); ?>" 
                                     style="width: <?php echo $usage_percent; ?>%"></div>
                            </div>
                            <small class="text-muted">
                                <?php echo formatBytes($total_log_size); ?> of <?php echo formatBytes($max_size); ?> used 
                                (<?php echo round($usage_percent, 1); ?>%)
                            </small>
                        </div>
                    </div>
                </div>
            </div>
    </main>
</div>

<!-- Hidden Form for Clear Log -->
<form id="clearLogForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="clear_log">
    <input type="hidden" name="log_type" id="clearLogType">
</form>

<script>
function clearLog(logType) {
    if (confirm('Are you sure you want to clear the ' + logType + ' log file? This action cannot be undone!')) {
        document.getElementById('clearLogType').value = logType;
        document.getElementById('clearLogForm').submit();
    }
}

// Auto-refresh every 30 seconds
setInterval(function() {
    if (!document.hidden) {
        window.location.reload();
    }
}, 30000);
</script>

<?php require_once '../../includes/footer.php'; ?>