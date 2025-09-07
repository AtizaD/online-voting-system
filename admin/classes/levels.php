<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Manage Levels';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Class Management', 'url' => './index'],
    ['title' => 'Manage Levels']
];

// Handle success/error messages from session
$success = '';
$error = '';

if (isset($_SESSION['classes_success'])) {
    $success = $_SESSION['classes_success'];
    unset($_SESSION['classes_success']);
}

if (isset($_SESSION['classes_error'])) {
    $error = $_SESSION['classes_error'];
    unset($_SESSION['classes_error']);
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'add_level') {
            $level_name = sanitize($_POST['level_name'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($level_name)) {
                throw new Exception('Level name is required');
            }
            
            // Check if level name already exists
            $stmt = $db->prepare("SELECT level_id FROM levels WHERE level_name = ?");
            $stmt->execute([$level_name]);
            if ($stmt->fetch()) {
                throw new Exception('Level name already exists');
            }
            
            $stmt = $db->prepare("
                INSERT INTO levels (level_name, is_active, created_at) 
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$level_name, $is_active]);
            
            logActivity('level_add', "Added level: {$level_name}", $current_user['id']);
            $_SESSION['classes_success'] = 'Level added successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'update_level') {
            $level_id = intval($_POST['level_id'] ?? 0);
            $level_name = sanitize($_POST['level_name'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (!$level_id || empty($level_name)) {
                throw new Exception('Level ID and name are required');
            }
            
            // Check if level name exists for another level
            $stmt = $db->prepare("SELECT level_id FROM levels WHERE level_name = ? AND level_id != ?");
            $stmt->execute([$level_name, $level_id]);
            if ($stmt->fetch()) {
                throw new Exception('Level name already exists');
            }
            
            $stmt = $db->prepare("
                UPDATE levels 
                SET level_name = ?, is_active = ?, updated_at = NOW()
                WHERE level_id = ?
            ");
            $stmt->execute([$level_name, $is_active, $level_id]);
            
            logActivity('level_update', "Updated level: {$level_name}", $current_user['id']);
            $_SESSION['classes_success'] = 'Level updated successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'delete_level') {
            $level_id = intval($_POST['level_id'] ?? 0);
            
            if (!$level_id) {
                throw new Exception('Invalid level ID');
            }
            
            // Check if level is used in classes
            $stmt = $db->prepare("SELECT COUNT(*) as class_count FROM classes WHERE level_id = ?");
            $stmt->execute([$level_id]);
            $usage = $stmt->fetch();
            
            if ($usage['class_count'] > 0) {
                throw new Exception('Cannot delete level that is used in classes');
            }
            
            // Get level info for logging
            $stmt = $db->prepare("SELECT level_name FROM levels WHERE level_id = ?");
            $stmt->execute([$level_id]);
            $level = $stmt->fetch();
            
            if (!$level) {
                throw new Exception('Level not found');
            }
            
            $stmt = $db->prepare("DELETE FROM levels WHERE level_id = ?");
            $stmt->execute([$level_id]);
            
            logActivity('level_delete', "Deleted level: {$level['level_name']}", $current_user['id']);
            $_SESSION['classes_success'] = 'Level deleted successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['classes_error'] = $e->getMessage();
        logError("Level management error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get all levels with usage count
    $stmt = $db->prepare("
        SELECT l.*, COUNT(c.class_id) as class_count
        FROM levels l
        LEFT JOIN classes c ON l.level_id = c.level_id
        GROUP BY l.level_id, l.level_name, l.is_active, l.created_at, l.updated_at
        ORDER BY l.level_name ASC
    ");
    $stmt->execute();
    $levels = $stmt->fetchAll();
    
    // Get level statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_levels,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_levels,
            COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_levels
        FROM levels
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    logError("Levels data fetch error: " . $e->getMessage());
    $_SESSION['classes_error'] = "Unable to load levels data";
    header('Location: index');
    exit;
}

include '../../includes/header.php';
?>

<!-- Manage Levels Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-list-ol me-2"></i>Manage Levels
        </h4>
        <small class="text-muted">Add, edit, and manage education levels</small>
    </div>
    <div class="d-flex gap-2">
        <a href="index" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Overview
        </a>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLevelModal">
            <i class="fas fa-plus me-2"></i>Add Level
        </button>
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

<!-- Level Statistics -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-primary">
                <i class="fas fa-list-ol"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_levels'] ?></h3>
                <p>Total Levels</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['active_levels'] ?></h3>
                <p>Active</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-warning">
                <i class="fas fa-pause-circle"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['inactive_levels'] ?></h3>
                <p>Inactive</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-info">
                <i class="fas fa-users-class"></i>
            </div>
            <div class="stats-info">
                <h3><?= array_sum(array_column($levels, 'class_count')) ?></h3>
                <p>Total Classes</p>
            </div>
        </div>
    </div>
</div>

<!-- Levels List -->
<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>All Levels
            </h5>
            <div class="d-flex gap-2">
                <select class="form-select form-select-sm" id="statusFilter" style="width: auto;">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($levels)): ?>
            <div class="text-center py-5">
                <i class="fas fa-list-ol text-muted" style="font-size: 4rem;"></i>
                <h4 class="text-muted mt-3">No Levels Found</h4>
                <p class="text-muted">Add education levels to organize your classes.</p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addLevelModal">
                    <i class="fas fa-plus me-2"></i>Add First Level
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover" id="levelsTable">
                    <thead>
                        <tr>
                            <th>Level Name</th>
                            <th>Classes</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($levels as $level): ?>
                            <tr data-status="<?= $level['is_active'] ? 'active' : 'inactive' ?>">
                                <td>
                                    <div>
                                        <strong><?= sanitize($level['level_name']) ?></strong>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($level['class_count'] > 0): ?>
                                        <span class="badge bg-primary"><?= $level['class_count'] ?> classes</span>
                                    <?php else: ?>
                                        <span class="text-muted">No classes</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($level['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted"><?= date('M j, Y', strtotime($level['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="editLevel(<?= htmlspecialchars(json_encode($level)) ?>)" title="Edit Level">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($level['class_count'] == 0): ?>
                                            <button type="button" class="btn btn-outline-danger" onclick="deleteLevel(<?= $level['level_id'] ?>, '<?= addslashes($level['level_name']) ?>')" title="Delete Level">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        <?php else: ?>
                                            <span class="btn btn-outline-secondary disabled" title="Cannot delete - has classes">
                                                <i class="fas fa-lock"></i>
                                            </span>
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

<!-- Add Level Modal -->
<div class="modal fade" id="addLevelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_level">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Add New Level
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="level_name" class="form-label">Level Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="level_name" name="level_name" required placeholder="e.g., SHS 1, SHS 2, SHS 3">
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">
                            Level is active
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Level</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Level Modal -->
<div class="modal fade" id="editLevelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_level">
                <input type="hidden" name="level_id" id="edit_level_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Level
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_level_name" class="form-label">Level Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_level_name" name="level_name" required>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                        <label class="form-check-label" for="edit_is_active">
                            Level is active
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Level</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Level Modal -->
<div class="modal fade" id="deleteLevelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Level
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete "<strong id="deleteLevelName"></strong>"?</p>
                <p class="text-warning"><small><i class="fas fa-info-circle me-1"></i>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteLevelForm">
                    <input type="hidden" name="action" value="delete_level">
                    <input type="hidden" name="level_id" id="delete_level_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Level</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Level Management Styles */

.stats-card {
    background: white;
    border-radius: 8px;
    padding: 1.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    border: 1px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.stats-icon {
    width: 60px;
    height: 60px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    color: white;
}

.stats-info h3 {
    font-size: 2rem;
    font-weight: 700;
    margin: 0;
    color: #212529;
}

.stats-info p {
    margin: 0;
    color: #6c757d;
    font-size: 0.9rem;
    font-weight: 500;
}

.table th {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
    font-weight: 600;
    color: #495057;
    font-size: 0.875rem;
}

.btn-group-sm .btn {
    padding: 0.25rem 0.5rem;
    font-size: 0.875rem;
}
</style>

<script>
// Level Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Edit level function
    window.editLevel = function(level) {
        document.getElementById('edit_level_id').value = level.level_id;
        document.getElementById('edit_level_name').value = level.level_name;
        document.getElementById('edit_is_active').checked = level.is_active == 1;
        
        const editModal = new bootstrap.Modal(document.getElementById('editLevelModal'));
        editModal.show();
    };
    
    // Delete level function
    window.deleteLevel = function(levelId, levelName) {
        document.getElementById('delete_level_id').value = levelId;
        document.getElementById('deleteLevelName').textContent = levelName;
        
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteLevelModal'));
        deleteModal.show();
    };
    
    // Filter functionality
    const statusFilter = document.getElementById('statusFilter');
    const table = document.getElementById('levelsTable');
    
    function filterTable() {
        if (!table) return;
        
        const selectedStatus = statusFilter.value;
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const status = row.dataset.status;
            
            let statusMatch = true;
            if (selectedStatus === 'active') statusMatch = status === 'active';
            else if (selectedStatus === 'inactive') statusMatch = status === 'inactive';
            
            if (statusMatch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    if (statusFilter) {
        statusFilter.addEventListener('change', filterTable);
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

<?php include '../../includes/footer.php'; ?>