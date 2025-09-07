<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Class Management';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Class Management']
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

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get levels
    $stmt = $db->prepare("SELECT * FROM levels ORDER BY level_name ASC");
    $stmt->execute();
    $levels = $stmt->fetchAll();
    
    // Get programs
    $stmt = $db->prepare("SELECT * FROM programs ORDER BY program_name ASC");
    $stmt->execute();
    $programs = $stmt->fetchAll();
    
    // Get classes with level and program info
    $stmt = $db->prepare("
        SELECT c.*, l.level_name, p.program_name,
               COUNT(s.student_id) as student_count
        FROM classes c
        JOIN levels l ON c.level_id = l.level_id
        JOIN programs p ON c.program_id = p.program_id
        LEFT JOIN students s ON c.class_id = s.class_id
        GROUP BY c.class_id, c.class_name, c.program_id, c.level_id, c.capacity, c.is_active, 
                 c.created_at, c.updated_at, l.level_name, p.program_name
        ORDER BY l.level_name ASC, p.program_name ASC, c.class_name ASC
    ");
    $stmt->execute();
    $classes = $stmt->fetchAll();
    
    // Get statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT l.level_id) as total_levels,
            COUNT(DISTINCT p.program_id) as total_programs,
            COUNT(DISTINCT c.class_id) as total_classes,
            COUNT(DISTINCT CASE WHEN c.is_active = 1 THEN c.class_id END) as active_classes,
            COUNT(DISTINCT s.student_id) as total_students
        FROM levels l
        CROSS JOIN programs p
        LEFT JOIN classes c ON (l.level_id = c.level_id AND p.program_id = c.program_id)
        LEFT JOIN students s ON c.class_id = s.class_id
        WHERE l.is_active = 1 AND p.is_active = 1
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    logError("Class management data fetch error: " . $e->getMessage());
    $_SESSION['classes_error'] = "Unable to load class management data";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include '../../includes/header.php';
?>

<!-- Class Management Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-layer-group me-2"></i>Class Management
        </h4>
        <small class="text-muted">Manage levels, programs, and classes</small>
    </div>
    <div class="d-flex gap-2">
        <a href="levels" class="btn btn-info">
            <i class="fas fa-list-ol me-2"></i>Manage Levels
        </a>
        <a href="programs" class="btn btn-success">
            <i class="fas fa-graduation-cap me-2"></i>Manage Programs
        </a>
        <a href="classes" class="btn btn-primary">
            <i class="fas fa-users-class me-2"></i>Manage Classes
        </a>
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

<!-- Overview Cards -->
<div class="row mb-4">
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-info">
                <i class="fas fa-list-ol"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_levels'] ?></h3>
                <p>Levels</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-success">
                <i class="fas fa-graduation-cap"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_programs'] ?></h3>
                <p>Programs</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-primary">
                <i class="fas fa-users-class"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_classes'] ?></h3>
                <p>Classes</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-warning">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['active_classes'] ?></h3>
                <p>Active</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-secondary">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_students'] ?></h3>
                <p>Students</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-danger">
                <i class="fas fa-plus-circle"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_classes'] - $stats['active_classes'] ?></h3>
                <p>Inactive</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Quick Actions -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-tachometer-alt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="levels" class="btn btn-outline-info">
                        <i class="fas fa-list-ol me-2"></i>Manage Levels
                    </a>
                    <a href="programs" class="btn btn-outline-success">
                        <i class="fas fa-graduation-cap me-2"></i>Manage Programs
                    </a>
                    <a href="classes" class="btn btn-outline-primary">
                        <i class="fas fa-users-class me-2"></i>Manage Classes
                    </a>
                    <a href="generator" class="btn btn-outline-warning">
                        <i class="fas fa-magic me-2"></i>Class Generator
                    </a>
                </div>
                
                <hr class="my-3">
                
                <h6 class="fw-bold mb-2">System Overview</h6>
                <div class="small">
                    <div class="d-flex justify-content-between py-1">
                        <span>Total Combinations:</span>
                        <strong><?= $stats['total_levels'] * $stats['total_programs'] ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span>Classes Created:</span>
                        <strong><?= $stats['total_classes'] ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span>Completion Rate:</span>
                        <strong><?= ($stats['total_levels'] * $stats['total_programs']) > 0 ? round(($stats['total_classes'] / ($stats['total_levels'] * $stats['total_programs'])) * 100) : 0 ?>%</strong>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Quick Class Generator -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-magic me-2"></i>Quick Generator
                </h5>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">Generate classes for a specific level-program combination</p>
                <form action="generator" method="GET">
                    <div class="mb-2">
                        <select class="form-select form-select-sm" name="level_id" required>
                            <option value="">Select Level...</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?= $level['level_id'] ?>"><?= sanitize($level['level_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <select class="form-select form-select-sm" name="program_id" required>
                            <option value="">Select Program...</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= $program['program_id'] ?>"><?= sanitize($program['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm w-100">
                        <i class="fas fa-arrow-right me-1"></i>Generate
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Classes Overview -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list me-2"></i>All Classes
                    </h5>
                    <div class="d-flex gap-2">
                        <select class="form-select form-select-sm" id="levelFilter" style="width: auto;">
                            <option value="">All Levels</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?= $level['level_id'] ?>"><?= sanitize($level['level_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select form-select-sm" id="programFilter" style="width: auto;">
                            <option value="">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= $program['program_id'] ?>"><?= sanitize($program['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($classes)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users-class text-muted" style="font-size: 4rem;"></i>
                        <h4 class="text-muted mt-3">No Classes Found</h4>
                        <p class="text-muted">Create levels and programs first, then generate classes.</p>
                        <div class="d-flex gap-2 justify-content-center">
                            <a href="levels" class="btn btn-info">
                                <i class="fas fa-list-ol me-2"></i>Add Levels
                            </a>
                            <a href="programs" class="btn btn-success">
                                <i class="fas fa-graduation-cap me-2"></i>Add Programs
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="classesTable">
                            <thead>
                                <tr>
                                    <th>Class</th>
                                    <th>Level</th>
                                    <th>Program</th>
                                    <th>Students</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classes as $class): ?>
                                    <tr data-level-id="<?= $class['level_id'] ?>" data-program-id="<?= $class['program_id'] ?>">
                                        <td>
                                            <div>
                                                <strong><?= sanitize($class['class_name']) ?></strong>
                                                <br><small class="text-muted">Capacity: <?= $class['capacity'] ?> students</small>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?= sanitize($class['level_name']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?= sanitize($class['program_name']) ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($class['student_count'] > 0): ?>
                                                <span class="badge bg-primary"><?= $class['student_count'] ?> students</span>
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
                                                <a href="classes?class_id=<?= $class['class_id'] ?>" class="btn btn-outline-primary" title="Edit Class">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="../students?class_id=<?= $class['class_id'] ?>" class="btn btn-outline-info" title="View Students">
                                                    <i class="fas fa-users"></i>
                                                </a>
                                                <a href="generator?level_id=<?= $class['level_id'] ?>&program_id=<?= $class['program_id'] ?>" class="btn btn-outline-warning" title="Generate More">
                                                    <i class="fas fa-magic"></i>
                                                </a>
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
    </div>
</div>

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

/* Responsive adjustments */
@media (max-width: 768px) {
    .stats-card {
        flex-direction: column;
        text-align: center;
    }
    
    .table-responsive {
        font-size: 0.875rem;
    }
}
</style>

<script>
// Class Management JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Filter functionality
    const levelFilter = document.getElementById('levelFilter');
    const programFilter = document.getElementById('programFilter');
    const table = document.getElementById('classesTable');
    
    function filterTable() {
        if (!table) return;
        
        const selectedLevel = levelFilter.value;
        const selectedProgram = programFilter.value;
        const rows = table.querySelectorAll('tbody tr');
        
        rows.forEach(row => {
            const levelId = row.dataset.levelId;
            const programId = row.dataset.programId;
            
            const levelMatch = !selectedLevel || levelId === selectedLevel;
            const programMatch = !selectedProgram || programId === selectedProgram;
            
            if (levelMatch && programMatch) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }
    
    if (levelFilter && programFilter) {
        levelFilter.addEventListener('change', filterTable);
        programFilter.addEventListener('change', filterTable);
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