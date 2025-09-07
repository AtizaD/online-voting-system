<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Manage Programs';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Class Management', 'url' => './index'],
    ['title' => 'Manage Programs']
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
        
        if ($action === 'add_program') {
            $program_name = sanitize($_POST['program_name'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($program_name)) {
                throw new Exception('Program name is required');
            }
            
            // Check if program name already exists
            $stmt = $db->prepare("SELECT program_id FROM programs WHERE program_name = ?");
            $stmt->execute([$program_name]);
            if ($stmt->fetch()) {
                throw new Exception('Program name already exists');
            }
            
            $stmt = $db->prepare("
                INSERT INTO programs (program_name, is_active, created_at) 
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$program_name, $is_active]);
            
            logActivity('program_add', "Added program: {$program_name}", $current_user['id']);
            $_SESSION['classes_success'] = 'Program added successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'update_program') {
            $program_id = intval($_POST['program_id'] ?? 0);
            $program_name = sanitize($_POST['program_name'] ?? '');
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (!$program_id || empty($program_name)) {
                throw new Exception('Program ID and name are required');
            }
            
            // Check if program name exists for another program
            $stmt = $db->prepare("SELECT program_id FROM programs WHERE program_name = ? AND program_id != ?");
            $stmt->execute([$program_name, $program_id]);
            if ($stmt->fetch()) {
                throw new Exception('Program name already exists');
            }
            
            $stmt = $db->prepare("
                UPDATE programs 
                SET program_name = ?, is_active = ?, updated_at = NOW()
                WHERE program_id = ?
            ");
            $stmt->execute([$program_name, $is_active, $program_id]);
            
            logActivity('program_update', "Updated program: {$program_name}", $current_user['id']);
            $_SESSION['classes_success'] = 'Program updated successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'delete_program') {
            $program_id = intval($_POST['program_id'] ?? 0);
            
            if (!$program_id) {
                throw new Exception('Invalid program ID');
            }
            
            // Check if program is used in classes
            $stmt = $db->prepare("SELECT COUNT(*) as class_count FROM classes WHERE program_id = ?");
            $stmt->execute([$program_id]);
            $usage = $stmt->fetch();
            
            if ($usage['class_count'] > 0) {
                throw new Exception('Cannot delete program that is used in classes');
            }
            
            // Get program info for logging
            $stmt = $db->prepare("SELECT program_name FROM programs WHERE program_id = ?");
            $stmt->execute([$program_id]);
            $program = $stmt->fetch();
            
            if (!$program) {
                throw new Exception('Program not found');
            }
            
            $stmt = $db->prepare("DELETE FROM programs WHERE program_id = ?");
            $stmt->execute([$program_id]);
            
            logActivity('program_delete', "Deleted program: {$program['program_name']}", $current_user['id']);
            $_SESSION['classes_success'] = 'Program deleted successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['classes_error'] = $e->getMessage();
        logError("Program management error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get all programs with usage count
    $stmt = $db->prepare("
        SELECT p.*, COUNT(c.class_id) as class_count, COUNT(s.student_id) as student_count
        FROM programs p
        LEFT JOIN classes c ON p.program_id = c.program_id
        LEFT JOIN students s ON c.class_id = s.class_id
        GROUP BY p.program_id, p.program_name, p.is_active, p.created_at, p.updated_at
        ORDER BY p.program_name ASC
    ");
    $stmt->execute();
    $programs = $stmt->fetchAll();
    
    // Get program statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_programs,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_programs,
            COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_programs
        FROM programs
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    logError("Programs data fetch error: " . $e->getMessage());
    $_SESSION['classes_error'] = "Unable to load programs data";
    header('Location: index');
    exit;
}

include '../../includes/header.php';
?>

<!-- Manage Programs Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-graduation-cap me-2"></i>Manage Programs
        </h4>
        <small class="text-muted">Add, edit, and manage academic programs</small>
    </div>
    <div class="d-flex gap-2">
        <a href="index" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Overview
        </a>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
            <i class="fas fa-plus me-2"></i>Add Program
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

<!-- Program Statistics -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-primary">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_programs'] ?></h3>
                <p>Total Programs</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['active_programs'] ?></h3>
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
                <h3><?= $stats['inactive_programs'] ?></h3>
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
                <h3><?= array_sum(array_column($programs, 'class_count')) ?></h3>
                <p>Total Classes</p>
            </div>
        </div>
    </div>
</div>

<!-- Programs List -->
<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>All Programs
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
        <?php if (empty($programs)): ?>
            <div class="text-center py-5">
                <i class="fas fa-graduation-cap text-muted" style="font-size: 4rem;"></i>
                <h4 class="text-muted mt-3">No Programs Found</h4>
                <p class="text-muted">Add academic programs to organize your classes.</p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                    <i class="fas fa-plus me-2"></i>Add First Program
                </button>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover" id="programsTable">
                    <thead>
                        <tr>
                            <th>Program Name</th>
                            <th>Classes</th>
                            <th>Students</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programs as $program): ?>
                            <tr data-status="<?= $program['is_active'] ? 'active' : 'inactive' ?>">
                                <td>
                                    <div>
                                        <strong><?= sanitize($program['program_name']) ?></strong>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($program['class_count'] > 0): ?>
                                        <span class="badge bg-info"><?= $program['class_count'] ?> classes</span>
                                    <?php else: ?>
                                        <span class="text-muted">No classes</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($program['student_count'] > 0): ?>
                                        <span class="badge bg-success"><?= $program['student_count'] ?> students</span>
                                    <?php else: ?>
                                        <span class="text-muted">No students</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($program['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted"><?= date('M j, Y', strtotime($program['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-primary" onclick="editProgram(<?= htmlspecialchars(json_encode($program)) ?>)" title="Edit Program">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <?php if ($program['class_count'] == 0): ?>
                                            <button type="button" class="btn btn-outline-danger" onclick="deleteProgram(<?= $program['program_id'] ?>, '<?= addslashes($program['program_name']) ?>')" title="Delete Program">
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

<!-- Add Program Modal -->
<div class="modal fade" id="addProgramModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_program">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Add New Program
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="program_name" class="form-label">Program Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="program_name" name="program_name" required placeholder="e.g., General Science, Business Studies">
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" checked>
                        <label class="form-check-label" for="is_active">
                            Program is active
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Program Modal -->
<div class="modal fade" id="editProgramModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_program">
                <input type="hidden" name="program_id" id="edit_program_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Program
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_program_name" class="form-label">Program Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="edit_program_name" name="program_name" required>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                        <label class="form-check-label" for="edit_is_active">
                            Program is active
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Program</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Program Modal -->
<div class="modal fade" id="deleteProgramModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Program
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete "<strong id="deleteProgramName"></strong>"?</p>
                <p class="text-warning"><small><i class="fas fa-info-circle me-1"></i>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteProgramForm">
                    <input type="hidden" name="action" value="delete_program">
                    <input type="hidden" name="program_id" id="delete_program_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Program</button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* Program Management Styles */

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
// Program Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Edit program function
    window.editProgram = function(program) {
        document.getElementById('edit_program_id').value = program.program_id;
        document.getElementById('edit_program_name').value = program.program_name;
        document.getElementById('edit_is_active').checked = program.is_active == 1;
        
        const editModal = new bootstrap.Modal(document.getElementById('editProgramModal'));
        editModal.show();
    };
    
    // Delete program function
    window.deleteProgram = function(programId, programName) {
        document.getElementById('delete_program_id').value = programId;
        document.getElementById('deleteProgramName').textContent = programName;
        
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteProgramModal'));
        deleteModal.show();
    };
    
    // Filter functionality
    const statusFilter = document.getElementById('statusFilter');
    const table = document.getElementById('programsTable');
    
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