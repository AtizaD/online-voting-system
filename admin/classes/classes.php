<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Manage Classes';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Class Management', 'url' => './index'],
    ['title' => 'Manage Classes']
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

// Get class ID if editing
$editing_class = null;
$class_id = intval($_GET['class_id'] ?? 0);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'add_class') {
            $class_name = sanitize($_POST['class_name'] ?? '');
            $level_id = intval($_POST['level_id'] ?? 0);
            $program_id = intval($_POST['program_id'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($class_name) || !$level_id || !$program_id) {
                throw new Exception('Class name, level, and program are required');
            }
            
            // Check if class name already exists for this level-program combination
            $stmt = $db->prepare("SELECT class_id FROM classes WHERE class_name = ? AND level_id = ? AND program_id = ?");
            $stmt->execute([$class_name, $level_id, $program_id]);
            if ($stmt->fetch()) {
                throw new Exception('Class name already exists for this level-program combination');
            }
            
            // Verify level and program exist and are active
            $stmt = $db->prepare("SELECT level_name FROM levels WHERE level_id = ? AND is_active = 1");
            $stmt->execute([$level_id]);
            $level = $stmt->fetch();
            
            $stmt = $db->prepare("SELECT program_name FROM programs WHERE program_id = ? AND is_active = 1");
            $stmt->execute([$program_id]);
            $program = $stmt->fetch();
            
            if (!$level || !$program) {
                throw new Exception('Selected level or program is not available');
            }
            
            $stmt = $db->prepare("
                INSERT INTO classes (class_name, level_id, program_id, is_active, created_at) 
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$class_name, $level_id, $program_id, $is_active]);
            
            logActivity('class_add', "Added class: {$class_name} for {$level['level_name']} - {$program['program_name']}", $current_user['id']);
            $_SESSION['classes_success'] = 'Class added successfully';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'update_class') {
            $class_id = intval($_POST['class_id'] ?? 0);
            $class_name = sanitize($_POST['class_name'] ?? '');
            $level_id = intval($_POST['level_id'] ?? 0);
            $program_id = intval($_POST['program_id'] ?? 0);
            $is_active = isset($_POST['is_active']) ? 1 : 0;
            
            if (!$class_id || empty($class_name) || !$level_id || !$program_id) {
                throw new Exception('Class ID, name, level, and program are required');
            }
            
            // Check if class name exists for another class in this level-program combination
            $stmt = $db->prepare("SELECT class_id FROM classes WHERE class_name = ? AND level_id = ? AND program_id = ? AND class_id != ?");
            $stmt->execute([$class_name, $level_id, $program_id, $class_id]);
            if ($stmt->fetch()) {
                throw new Exception('Class name already exists for this level-program combination');
            }
            
            $stmt = $db->prepare("
                UPDATE classes 
                SET class_name = ?, level_id = ?, program_id = ?, is_active = ?, updated_at = NOW()
                WHERE class_id = ?
            ");
            $stmt->execute([$class_name, $level_id, $program_id, $is_active, $class_id]);
            
            logActivity('class_update', "Updated class: {$class_name}", $current_user['id']);
            $_SESSION['classes_success'] = 'Class updated successfully';
            header('Location: index');
            exit;
            
        } elseif ($action === 'delete_class') {
            $class_id = intval($_POST['class_id'] ?? 0);
            
            if (!$class_id) {
                throw new Exception('Invalid class ID');
            }
            
            // Check if class has students
            $stmt = $db->prepare("SELECT COUNT(*) as student_count FROM students WHERE class_id = ?");
            $stmt->execute([$class_id]);
            $usage = $stmt->fetch();
            
            if ($usage['student_count'] > 0) {
                throw new Exception('Cannot delete class that has students');
            }
            
            // Get class info for logging
            $stmt = $db->prepare("SELECT class_name FROM classes WHERE class_id = ?");
            $stmt->execute([$class_id]);
            $class = $stmt->fetch();
            
            if (!$class) {
                throw new Exception('Class not found');
            }
            
            $stmt = $db->prepare("DELETE FROM classes WHERE class_id = ?");
            $stmt->execute([$class_id]);
            
            logActivity('class_delete', "Deleted class: {$class['class_name']}", $current_user['id']);
            $_SESSION['classes_success'] = 'Class deleted successfully';
            header('Location: index');
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['classes_error'] = $e->getMessage();
        logError("Class management error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF'] . ($class_id ? "?class_id={$class_id}" : ''));
        exit;
    }
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get levels with class counts
    $stmt = $db->prepare("
        SELECT l.*, 
               COUNT(CASE WHEN c.is_active = 1 THEN 1 END) as active_classes,
               COUNT(CASE WHEN c.is_active = 0 THEN 1 END) as inactive_classes,
               COUNT(c.class_id) as total_classes
        FROM levels l
        LEFT JOIN classes c ON l.level_id = c.level_id
        WHERE l.is_active = 1
        GROUP BY l.level_id, l.level_name, l.is_active
        ORDER BY l.level_name ASC
    ");
    $stmt->execute();
    $levels = $stmt->fetchAll();
    
    // Get programs with class counts
    $stmt = $db->prepare("
        SELECT p.*, 
               COUNT(CASE WHEN c.is_active = 1 THEN 1 END) as active_classes,
               COUNT(CASE WHEN c.is_active = 0 THEN 1 END) as inactive_classes,
               COUNT(c.class_id) as total_classes
        FROM programs p
        LEFT JOIN classes c ON p.program_id = c.program_id
        WHERE p.is_active = 1
        GROUP BY p.program_id, p.program_name, p.is_active
        ORDER BY p.program_name ASC
    ");
    $stmt->execute();
    $programs = $stmt->fetchAll();
    
    // Get all classes with level and program info (removed program_code reference)
    $stmt = $db->prepare("
        SELECT c.*, l.level_name, p.program_name,
               COUNT(s.student_id) as student_count
        FROM classes c
        JOIN levels l ON c.level_id = l.level_id
        JOIN programs p ON c.program_id = p.program_id
        LEFT JOIN students s ON c.class_id = s.class_id
        GROUP BY c.class_id, c.class_name, c.level_id, c.program_id, c.capacity, c.is_active, 
                 c.created_at, c.updated_at, l.level_name, p.program_name
        ORDER BY l.level_name ASC, p.program_name ASC, c.class_name ASC
    ");
    $stmt->execute();
    $classes = $stmt->fetchAll();
    
    // If editing, get class details
    if ($class_id) {
        $stmt = $db->prepare("
            SELECT c.*, l.level_name, p.program_name
            FROM classes c
            JOIN levels l ON c.level_id = l.level_id
            JOIN programs p ON c.program_id = p.program_id
            WHERE c.class_id = ?
        ");
        $stmt->execute([$class_id]);
        $editing_class = $stmt->fetch();
        
        if (!$editing_class) {
            $_SESSION['classes_error'] = 'Class not found';
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
    
    // Get class statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_classes,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_classes,
            COUNT(CASE WHEN is_active = 0 THEN 1 END) as inactive_classes
        FROM classes
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    logError("Classes manage data fetch error: " . $e->getMessage());
    $_SESSION['classes_error'] = "Unable to load classes management data";
    header('Location: index');
    exit;
}

include '../../includes/header.php';
?>

<!-- Manage Classes Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-users-class me-2"></i><?= $editing_class ? 'Edit Class' : 'Manage Classes' ?>
        </h4>
        <small class="text-muted"><?= $editing_class ? 'Update class information' : 'Add, edit, and manage individual classes' ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="index" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Overview
        </a>
        <?php if ($editing_class): ?>
            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-success">
                <i class="fas fa-plus me-2"></i>Add New
            </a>
        <?php else: ?>
            <a href="generator" class="btn btn-warning">
                <i class="fas fa-magic me-2"></i>Class Generator
            </a>
        <?php endif; ?>
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

<?php if (!$editing_class): ?>
<!-- Class Statistics -->
<div class="row mb-4">
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-primary">
                <i class="fas fa-users-class"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_classes'] ?></h3>
                <p>Total Classes</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['active_classes'] ?></h3>
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
                <h3><?= $stats['inactive_classes'] ?></h3>
                <p>Inactive</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-info">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-info">
                <h3><?= array_sum(array_column($classes, 'student_count')) ?></h3>
                <p>Total Students</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row">
    <!-- Main Form -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-<?= $editing_class ? 'edit' : 'plus' ?> me-2"></i>
                    <?= $editing_class ? 'Edit Class' : 'Add New Class' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="<?= $editing_class ? 'update_class' : 'add_class' ?>">
                    <?php if ($editing_class): ?>
                        <input type="hidden" name="class_id" value="<?= $editing_class['class_id'] ?>">
                    <?php endif; ?>
                    
                    <div class="mb-3">
                        <label for="class_name" class="form-label">Class Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="class_name" name="class_name" 
                               value="<?= $editing_class ? sanitize($editing_class['class_name']) : '' ?>" 
                               required placeholder="e.g., 1B1, Class-A, Science-1">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="level_id" class="form-label">Level <span class="text-danger">*</span></label>
                                <select class="form-select" id="level_id" name="level_id" required>
                                    <option value="">Choose level...</option>
                                    <?php foreach ($levels as $level): ?>
                                        <option value="<?= $level['level_id'] ?>" 
                                                <?= ($editing_class && $editing_class['level_id'] == $level['level_id']) ? 'selected' : '' ?>>
                                            <?= sanitize($level['level_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="program_id" class="form-label">Program <span class="text-danger">*</span></label>
                                <select class="form-select" id="program_id" name="program_id" required>
                                    <option value="">Choose program...</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?= $program['program_id'] ?>" 
                                                <?= ($editing_class && $editing_class['program_id'] == $program['program_id']) ? 'selected' : '' ?>>
                                            <?= sanitize($program['program_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                               <?= ($editing_class ? ($editing_class['is_active'] ? 'checked' : '') : 'checked') ?>>
                        <label class="form-check-label" for="is_active">
                            Class is active
                        </label>
                    </div>
                    
                    <div class="d-flex gap-2 justify-content-end">
                        <a href="<?= $editing_class ? 'index' : $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-<?= $editing_class ? 'save' : 'plus' ?> me-2"></i>
                            <?= $editing_class ? 'Update Class' : 'Add Class' ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <?php if (!$editing_class): ?>
        <!-- Classes List -->
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list me-2"></i>All Classes
                        <span class="badge bg-secondary ms-2" id="classCount"><?= count($classes) ?></span>
                    </h5>
                </div>
                
                <!-- Enhanced Filter Controls -->
                <div class="border-top pt-3">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="searchFilter" placeholder="Search class names...">
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <select class="form-select form-select-sm" id="levelFilter">
                                <option value="">All Levels</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= $level['level_id'] ?>">
                                        <?= sanitize($level['level_name']) ?> (<?= $level['total_classes'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <select class="form-select form-select-sm" id="programFilter">
                                <option value="">All Programs</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?= $program['program_id'] ?>">
                                        <?= sanitize($program['program_name']) ?> (<?= $program['total_classes'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-2">
                            <select class="form-select form-select-sm" id="statusFilter">
                                <option value="">All Status</option>
                                <option value="active">Active Only</option>
                                <option value="inactive">Inactive Only</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($classes)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users-class text-muted" style="font-size: 4rem;"></i>
                        <h4 class="text-muted mt-3">No Classes Found</h4>
                        <p class="text-muted">Add classes manually or use the class generator.</p>
                        <div class="d-flex gap-2 justify-content-center">
                            <button type="button" class="btn btn-primary" onclick="document.getElementById('class_name').focus()">
                                <i class="fas fa-plus me-2"></i>Add Class
                            </button>
                            <a href="generator" class="btn btn-warning">
                                <i class="fas fa-magic me-2"></i>Use Generator
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="classesTable">
                            <thead>
                                <tr>
                                    <th>Class Name</th>
                                    <th>Level</th>
                                    <th>Program</th>
                                    <th>Students</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <tr data-level-id="<?= $class['level_id'] ?>" 
                                        data-program-id="<?= $class['program_id'] ?>"
                                        data-status="<?= $class['is_active'] ? 'active' : 'inactive' ?>"
                                        data-class-name="<?= strtolower($class['class_name']) ?>">
                                        <td>
                                            <strong><?= sanitize($class['class_name']) ?></strong>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= sanitize($class['level_name']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?= sanitize($class['program_name']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($class['student_count'] > 0): ?>
                                                <a href="../students?class_id=<?= $class['class_id'] ?>" class="badge bg-primary text-decoration-none">
                                                    <?= $class['student_count'] ?> students
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">No students</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($class['is_active']): ?>
                                                <span class="badge bg-success">Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="?class_id=<?= $class['class_id'] ?>" class="btn btn-outline-primary" title="Edit Class">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="../students?class_id=<?= $class['class_id'] ?>" class="btn btn-outline-info" title="View Students">
                                                    <i class="fas fa-users"></i>
                                                </a>
                                                <?php if ($class['student_count'] == 0): ?>
                                                    <button type="button" class="btn btn-outline-danger" onclick="deleteClass(<?= $class['class_id'] ?>, '<?= addslashes($class['class_name']) ?>')" title="Delete Class">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="btn btn-outline-secondary disabled" title="Cannot delete - has students">
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
        <?php endif; ?>
    </div>    <div class="col-lg-4">
        <!-- Quick Actions -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-bolt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <?php if ($editing_class): ?>
                        <a href="../students/manage?class_id=<?= $editing_class['class_id'] ?>" class="btn btn-outline-success btn-sm">
                            <i class="fas fa-user-plus me-2"></i>Add Students
                        </a>
                        <a href="../students?class_id=<?= $editing_class['class_id'] ?>" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-users me-2"></i>View Students
                        </a>
                    <?php endif; ?>
                    <a href="generator" class="btn btn-outline-warning btn-sm">
                        <i class="fas fa-magic me-2"></i>Class Generator
                    </a>
                    <a href="levels" class="btn btn-outline-info btn-sm">
                        <i class="fas fa-list-ol me-2"></i>Manage Levels
                    </a>
                    <a href="programs" class="btn btn-outline-success btn-sm">
                        <i class="fas fa-graduation-cap me-2"></i>Manage Programs
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Guidelines -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-info-circle me-2"></i>Guidelines
                </h5>
            </div>
            <div class="card-body">
                <div class="small">
                    <div class="mb-3">
                        <h6 class="fw-bold">Class Naming:</h6>
                        <ul class="mb-0">
                            <li>Use consistent naming patterns</li>
                            <li>Include level and program identifiers</li>
                            <li>Keep names short but descriptive</li>
                            <li>Examples: 1B1, SHS1-BUS-A, Class-2A</li>
                        </ul>
                    </div>
                    
                    <div class="mb-3">
                        <h6 class="fw-bold">Management Tips:</h6>
                        <ul class="mb-0">
                            <li>Use the generator for multiple classes</li>
                            <li>Ensure level and program are active</li>
                            <li>Cannot delete classes with students</li>
                            <li>Inactive classes won't appear in student forms</li>
                        </ul>
                    </div>
                    
                    <div>
                        <h6 class="fw-bold">Best Practices:</h6>
                        <ul class="mb-0">
                            <li>Create all needed levels first</li>
                            <li>Add all programs before classes</li>
                            <li>Use generator for consistent naming</li>
                            <li>Regular cleanup of unused classes</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Class Modal -->
<?php if (!$editing_class): ?>
<div class="modal fade" id="deleteClassModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Class
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete "<strong id="deleteClassName"></strong>"?</p>
                <p class="text-warning"><small><i class="fas fa-info-circle me-1"></i>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteClassForm">
                    <input type="hidden" name="action" value="delete_class">
                    <input type="hidden" name="class_id" id="delete_class_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Class</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Class Section (for editing mode) -->
<?php if ($editing_class): ?>
<div class="card mt-4">
    <div class="card-header bg-danger text-white">
        <h5 class="card-title mb-0">
            <i class="fas fa-exclamation-triangle me-2"></i>Danger Zone
        </h5>
    </div>
    <div class="card-body">
        <p class="text-muted">Remove this class from the system. This action cannot be undone.</p>
        <button type="button" class="btn btn-danger" onclick="deleteClass(<?= $editing_class['class_id'] ?>, '<?= addslashes($editing_class['class_name']) ?>')">
            <i class="fas fa-trash me-2"></i>Delete Class
        </button>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteClassModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger">
                    <i class="fas fa-exclamation-triangle me-2"></i>Delete Class
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete "<strong id="deleteClassName"></strong>"?</p>
                <p class="text-warning"><small><i class="fas fa-info-circle me-1"></i>This action cannot be undone.</small></p>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteClassForm">
                    <input type="hidden" name="action" value="delete_class">
                    <input type="hidden" name="class_id" id="delete_class_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Class</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
/* Class Management Styles */

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

.card-header.bg-danger {
    border-bottom: 1px solid rgba(255,255,255,0.2);
}
</style>

<script>
// Class Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Delete class function
    window.deleteClass = function(classId, className) {
        document.getElementById('delete_class_id').value = classId;
        document.getElementById('deleteClassName').textContent = className;
        
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteClassModal'));
        deleteModal.show();
    };
    
    // Enhanced filter functionality
    const searchFilter = document.getElementById('searchFilter');
    const levelFilter = document.getElementById('levelFilter');
    const programFilter = document.getElementById('programFilter');
    const statusFilter = document.getElementById('statusFilter');
    const table = document.getElementById('classesTable');
    const classCount = document.getElementById('classCount');
    
    // Store original counts for filters
    const originalCounts = {
        levels: {},
        programs: {},
        status: { active: 0, inactive: 0 }
    };
    
    // Initialize original counts from dropdown options
    function initializeOriginalCounts() {
        // Get level counts
        if (levelFilter) {
            Array.from(levelFilter.options).forEach(option => {
                if (option.value) {
                    const match = option.textContent.match(/\((\d+)\)$/);
                    if (match) {
                        originalCounts.levels[option.value] = {
                            name: option.textContent.replace(/\s*\(\d+\)$/, ''),
                            count: parseInt(match[1])
                        };
                    }
                }
            });
        }
        
        // Get program counts
        if (programFilter) {
            Array.from(programFilter.options).forEach(option => {
                if (option.value) {
                    const match = option.textContent.match(/\((\d+)\)$/);
                    if (match) {
                        originalCounts.programs[option.value] = {
                            name: option.textContent.replace(/\s*\(\d+\)$/, ''),
                            count: parseInt(match[1])
                        };
                    }
                }
            });
        }
        
        // Count initial active/inactive
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const status = row.dataset.status;
            originalCounts.status[status]++;
        });
    }
    
    function updateFilterCounts(visibleRows) {
        // Count visible items by category
        const visibleCounts = {
            levels: {},
            programs: {},
            status: { active: 0, inactive: 0 }
        };
        
        visibleRows.forEach(row => {
            const levelId = row.dataset.levelId;
            const programId = row.dataset.programId;
            const status = row.dataset.status;
            
            // Count by level
            if (levelId) {
                visibleCounts.levels[levelId] = (visibleCounts.levels[levelId] || 0) + 1;
            }
            
            // Count by program
            if (programId) {
                visibleCounts.programs[programId] = (visibleCounts.programs[programId] || 0) + 1;
            }
            
            // Count by status
            visibleCounts.status[status]++;
        });
        
        // Update level filter options
        if (levelFilter) {
            const selectedLevel = levelFilter.value;
            Array.from(levelFilter.options).forEach(option => {
                if (option.value && originalCounts.levels[option.value]) {
                    const original = originalCounts.levels[option.value];
                    const visible = visibleCounts.levels[option.value] || 0;
                    const isCurrentlySelected = option.value === selectedLevel;
                    
                    // Show original count if this level is selected, otherwise show visible count
                    const displayCount = isCurrentlySelected ? original.count : visible;
                    option.textContent = `${original.name} (${displayCount})`;
                    
                    // Disable option if no visible classes (unless it's currently selected)
                    option.disabled = !isCurrentlySelected && visible === 0;
                }
            });
        }
        
        // Update program filter options
        if (programFilter) {
            const selectedProgram = programFilter.value;
            Array.from(programFilter.options).forEach(option => {
                if (option.value && originalCounts.programs[option.value]) {
                    const original = originalCounts.programs[option.value];
                    const visible = visibleCounts.programs[option.value] || 0;
                    const isCurrentlySelected = option.value === selectedProgram;
                    
                    // Show original count if this program is selected, otherwise show visible count
                    const displayCount = isCurrentlySelected ? original.count : visible;
                    option.textContent = `${original.name} (${displayCount})`;
                    
                    // Disable option if no visible classes (unless it's currently selected)
                    option.disabled = !isCurrentlySelected && visible === 0;
                }
            });
        }
        
        // Update status filter options
        if (statusFilter) {
            const selectedStatus = statusFilter.value;
            Array.from(statusFilter.options).forEach(option => {
                if (option.value) {
                    const status = option.value;
                    const visible = visibleCounts.status[status] || 0;
                    const isCurrentlySelected = option.value === selectedStatus;
                    
                    const displayCount = isCurrentlySelected ? originalCounts.status[status] : visible;
                    const statusText = status === 'active' ? 'Active Only' : 'Inactive Only';
                    option.textContent = `${statusText} (${displayCount})`;
                    
                    // Disable option if no visible classes (unless it's currently selected)
                    option.disabled = !isCurrentlySelected && visible === 0;
                }
            });
        }
    }
    
    function filterTable() {
        if (!table) return;
        
        const searchTerm = searchFilter ? searchFilter.value.toLowerCase().trim() : '';
        const selectedLevel = levelFilter ? levelFilter.value : '';
        const selectedProgram = programFilter ? programFilter.value : '';
        const selectedStatus = statusFilter ? statusFilter.value : '';
        const rows = table.querySelectorAll('tbody tr');
        
        let visibleCount = 0;
        const visibleRows = [];
        
        rows.forEach(row => {
            const levelId = row.dataset.levelId;
            const programId = row.dataset.programId;
            const status = row.dataset.status;
            const className = row.dataset.className;
            
            // Apply all filters
            const searchMatch = !searchTerm || className.includes(searchTerm);
            const levelMatch = !selectedLevel || levelId === selectedLevel;
            const programMatch = !selectedProgram || programId === selectedProgram;
            const statusMatch = !selectedStatus || status === selectedStatus;
            
            if (searchMatch && levelMatch && programMatch && statusMatch) {
                row.style.display = '';
                visibleCount++;
                visibleRows.push(row);
            } else {
                row.style.display = 'none';
            }
        });
        
        // Update visible count
        if (classCount) {
            classCount.textContent = visibleCount;
            classCount.className = visibleCount === <?= count($classes) ?> 
                ? 'badge bg-secondary ms-2' 
                : 'badge bg-info ms-2';
        }
        
        // Update filter counts based on visible rows
        updateFilterCounts(visibleRows);
    }
    
    // Add event listeners with debouncing for search
    let searchTimeout;
    if (searchFilter) {
        searchFilter.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(filterTable, 300); // 300ms debounce
        });
    }
    
    if (levelFilter) {
        levelFilter.addEventListener('change', filterTable);
    }
    
    if (programFilter) {
        programFilter.addEventListener('change', filterTable);
    }
    
    if (statusFilter) {
        statusFilter.addEventListener('change', filterTable);
    }
    
    // Initialize original counts on page load
    initializeOriginalCounts();
    
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