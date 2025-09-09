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
            case 'create_backup':
                $backup_name = trim($_POST['backup_name']);
                $include_data = isset($_POST['include_data']);
                
                if (empty($backup_name)) {
                    $backup_name = 'backup_' . date('Y-m-d_H-i-s');
                }
                
                // Sanitize backup name
                $backup_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $backup_name);
                
                try {
                    $backup_dir = '../../backups/';
                    if (!is_dir($backup_dir)) {
                        mkdir($backup_dir, 0755, true);
                    }
                    
                    $backup_file = $backup_dir . $backup_name . '.sql';
                    
                    // Get database configuration
                    $host = DB_HOST;
                    $username = DB_USERNAME;
                    $password = DB_PASSWORD;
                    $database = DB_NAME;
                    
                    // Create mysqldump command
                    $command = "mysqldump -h{$host} -u{$username}";
                    if (!empty($password)) {
                        $command .= " -p{$password}";
                    }
                    
                    if (!$include_data) {
                        $command .= " --no-data";
                    }
                    
                    $command .= " {$database} > " . escapeshellarg($backup_file);
                    
                    // Execute backup
                    $output = [];
                    $return_var = 0;
                    exec($command, $output, $return_var);
                    
                    if ($return_var === 0 && file_exists($backup_file)) {
                        Logger::log('INFO', "Database backup created: {$backup_name}", ['user_id' => $_SESSION['user_id'] ?? null]);
                        $_SESSION['success'] = "Backup '{$backup_name}' created successfully.";
                    } else {
                        $_SESSION['error'] = 'Failed to create database backup. Please check server configuration.';
                    }
                } catch (Exception $e) {
                    Logger::log('ERROR', "Backup failed: " . $e->getMessage(), ['user_id' => $_SESSION['user_id'] ?? null]);
                    $_SESSION['error'] = 'Backup failed: ' . $e->getMessage();
                }
                break;
                
            case 'restore_backup':
                $backup_file = $_POST['backup_file'];
                $backup_path = '../../backups/' . basename($backup_file);
                
                if (!file_exists($backup_path)) {
                    $_SESSION['error'] = 'Backup file not found.';
                } else {
                    try {
                        // Get database configuration
                        $host = DB_HOST;
                        $username = DB_USERNAME;
                        $password = DB_PASSWORD;
                        $database = DB_NAME;
                        
                        // Create mysql restore command
                        $command = "mysql -h{$host} -u{$username}";
                        if (!empty($password)) {
                            $command .= " -p{$password}";
                        }
                        $command .= " {$database} < " . escapeshellarg($backup_path);
                        
                        // Execute restore
                        $output = [];
                        $return_var = 0;
                        exec($command, $output, $return_var);
                        
                        if ($return_var === 0) {
                            Logger::log('INFO', "Database restored from: {$backup_file}", ['user_id' => $_SESSION['user_id'] ?? null]);
                            $_SESSION['success'] = "Database restored from '{$backup_file}' successfully.";
                        } else {
                            $_SESSION['error'] = 'Failed to restore database. Please check the backup file.';
                        }
                    } catch (Exception $e) {
                        Logger::log('ERROR', "Restore failed: " . $e->getMessage(), ['user_id' => $_SESSION['user_id'] ?? null]);
                        $_SESSION['error'] = 'Restore failed: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'delete_backup':
                $backup_file = $_POST['backup_file'];
                $backup_path = '../../backups/' . basename($backup_file);
                
                if (file_exists($backup_path)) {
                    if (unlink($backup_path)) {
                        Logger::log('INFO', "Backup deleted: {$backup_file}", ['user_id' => $_SESSION['user_id'] ?? null]);
                        $_SESSION['success'] = "Backup '{$backup_file}' deleted successfully.";
                    } else {
                        $_SESSION['error'] = 'Failed to delete backup file.';
                    }
                } else {
                    $_SESSION['error'] = 'Backup file not found.';
                }
                break;
        }
        
        header('Location: backup.php');
        exit();
    }
}

// Get existing backups
$backup_dir = '../../backups/';
$backups = [];
if (is_dir($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $filepath = $backup_dir . $file;
            $backups[] = [
                'name' => $file,
                'size' => filesize($filepath),
                'date' => filemtime($filepath)
            ];
        }
    }
    // Sort by date (newest first)
    usort($backups, function($a, $b) {
        return $b['date'] - $a['date'];
    });
}

// Get database statistics
$db_stats = [];
try {
    $stmt = $db->query("SHOW TABLE STATUS");
    $tables = $stmt->fetchAll();
    
    $total_size = 0;
    $total_rows = 0;
    $table_count = 0;
    
    foreach ($tables as $table) {
        $total_size += $table['Data_length'] + $table['Index_length'];
        $total_rows += $table['Rows'];
        $table_count++;
    }
    
    $db_stats = [
        'tables' => $table_count,
        'rows' => $total_rows,
        'size' => $total_size
    ];
} catch (Exception $e) {
    $db_stats = ['tables' => 0, 'rows' => 0, 'size' => 0];
}

// formatBytes() function is available from includes/functions.php

$page_title = 'Database Backup';
require_once '../../includes/header.php';
?>

<div class="container-fluid">
    <!-- Main Content -->
    <main class="px-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">
                    <i class="fas fa-database"></i> Database Backup & Restore
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="index.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Overview
                        </a>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBackupModal">
                            <i class="fas fa-plus"></i> Create Backup
                        </button>
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

            <!-- Database Overview -->
            <div class="row mb-4">
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-table fa-2x text-primary mb-2"></i>
                            <h6>Database Tables</h6>
                            <h3 class="mb-0"><?php echo $db_stats['tables']; ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-list-ol fa-2x text-info mb-2"></i>
                            <h6>Total Records</h6>
                            <h3 class="mb-0"><?php echo number_format($db_stats['rows']); ?></h3>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-body text-center">
                            <i class="fas fa-hdd fa-2x text-success mb-2"></i>
                            <h6>Database Size</h6>
                            <h3 class="mb-0"><?php echo formatBytes($db_stats['size']); ?></h3>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Backup Options -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5><i class="fas fa-cog"></i> Backup Configuration</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Important:</strong> Database backups are created using mysqldump. Make sure the MySQL client tools are installed and accessible from the command line.
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Backup Types</h6>
                            <ul class="list-unstyled">
                                <li><i class="fas fa-check text-success"></i> <strong>Full Backup:</strong> Includes structure and data</li>
                                <li><i class="fas fa-check text-success"></i> <strong>Structure Only:</strong> Database schema without data</li>
                                <li><i class="fas fa-check text-success"></i> <strong>Scheduled Backups:</strong> Automatic daily backups (if configured)</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6>Backup Location</h6>
                            <p class="text-muted">Backups are stored in: <code>/backups/</code></p>
                            <p class="text-muted">Current backups: <strong><?php echo count($backups); ?></strong></p>
                            <p class="text-muted">
                                Total backup size: <strong>
                                    <?php 
                                    $total_backup_size = array_sum(array_column($backups, 'size'));
                                    echo formatBytes($total_backup_size);
                                    ?>
                                </strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Existing Backups -->
            <div class="card">
                <div class="card-header">
                    <h5><i class="fas fa-archive"></i> Existing Backups</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($backups)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5>No Backups Found</h5>
                            <p class="text-muted">Create your first database backup to get started.</p>
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBackupModal">
                                <i class="fas fa-plus"></i> Create First Backup
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Backup Name</th>
                                        <th>Size</th>
                                        <th>Created</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $backup): ?>
                                    <tr>
                                        <td>
                                            <i class="fas fa-file-archive text-info"></i>
                                            <?php echo htmlspecialchars($backup['name']); ?>
                                        </td>
                                        <td><?php echo formatBytes($backup['size']); ?></td>
                                        <td><?php echo date('M j, Y H:i', $backup['date']); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="../../backups/<?php echo urlencode($backup['name']); ?>" class="btn btn-outline-primary" download>
                                                    <i class="fas fa-download"></i> Download
                                                </a>
                                                <button type="button" class="btn btn-outline-warning" 
                                                        onclick="restoreBackup('<?php echo htmlspecialchars($backup['name']); ?>')">
                                                    <i class="fas fa-undo"></i> Restore
                                                </button>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        onclick="deleteBackup('<?php echo htmlspecialchars($backup['name']); ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
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
    </main>
</div>

<!-- Create Backup Modal -->
<div class="modal fade" id="createBackupModal" tabindex="-1" aria-labelledby="createBackupModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="createBackupModalLabel">
                    <i class="fas fa-plus"></i> Create Database Backup
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="create_backup">
                    
                    <div class="mb-3">
                        <label for="backup_name" class="form-label">Backup Name</label>
                        <input type="text" class="form-control" id="backup_name" name="backup_name" 
                               placeholder="Leave empty for auto-generated name">
                        <div class="form-text">Only letters, numbers, underscores, and hyphens allowed</div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="include_data" name="include_data" checked>
                            <label class="form-check-label" for="include_data">
                                Include Data
                            </label>
                        </div>
                        <div class="form-text">Uncheck to backup structure only (schema)</div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Warning:</strong> Creating a backup may take several minutes for large databases. Do not close this window during the process.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Create Backup
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Forms for Actions -->
<form id="restoreForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="action" value="restore_backup">
    <input type="hidden" name="backup_file" id="restore_backup_file">
</form>

<form id="deleteForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="action" value="delete_backup">
    <input type="hidden" name="backup_file" id="delete_backup_file">
</form>

<script>
function restoreBackup(filename) {
    if (confirm('Are you sure you want to restore from "' + filename + '"? This will overwrite the current database!')) {
        document.getElementById('restore_backup_file').value = filename;
        document.getElementById('restoreForm').submit();
    }
}

function deleteBackup(filename) {
    if (confirm('Are you sure you want to delete backup "' + filename + '"? This action cannot be undone!')) {
        document.getElementById('delete_backup_file').value = filename;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>