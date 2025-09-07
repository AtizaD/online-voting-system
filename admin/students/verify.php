<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Verify Students';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Students', 'url' => './index'],
    ['title' => 'Verify Students']
];

// Handle success/error messages from session
$success = '';
$error = '';

if (isset($_SESSION['students_success'])) {
    $success = $_SESSION['students_success'];
    unset($_SESSION['students_success']);
}

if (isset($_SESSION['students_error'])) {
    $error = $_SESSION['students_error'];
    unset($_SESSION['students_error']);
}

// Get specific student ID if verifying individual student
$student_id = intval($_GET['student_id'] ?? 0);

// Pagination and filtering parameters (only if not viewing specific student)
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(10, min(100, intval($_GET['per_page'] ?? 25)));
$search = sanitize($_GET['search'] ?? '');
$program_filter = intval($_GET['program_filter'] ?? 0);
$class_filter = intval($_GET['class_filter'] ?? 0);
$status_filter = sanitize($_GET['status_filter'] ?? '');

$offset = ($page - 1) * $per_page;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'verify_student') {
            $student_id = intval($_POST['student_id'] ?? 0);
            $verify_status = intval($_POST['verify_status'] ?? 0);
            
            if (!$student_id) {
                throw new Exception('Invalid student ID');
            }
            
            // Get student info
            $stmt = $db->prepare("SELECT first_name, last_name, student_number FROM students WHERE student_id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();
            
            if (!$student) {
                throw new Exception('Student not found');
            }
            
            // Update verification status
            $stmt = $db->prepare("
                UPDATE students 
                SET is_verified = ?, verified_by = ?, verified_at = NOW()
                WHERE student_id = ?
            ");
            $stmt->execute([$verify_status, $current_user['id'], $student_id]);
            
            $student_name = $student['first_name'] . ' ' . $student['last_name'];
            $status_text = $verify_status ? 'verified' : 'unverified';
            logActivity('student_verify', "Set student {$student_name} ({$student['student_number']}) as {$status_text}", $current_user['id']);
            
            $_SESSION['students_success'] = "Student {$status_text} successfully";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
            
        } elseif ($action === 'bulk_verify') {
            $student_ids = $_POST['student_ids'] ?? [];
            $verify_status = intval($_POST['verify_status'] ?? 0);
            
            if (empty($student_ids)) {
                throw new Exception('No students selected');
            }
            
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
            $stmt = $db->prepare("
                UPDATE students 
                SET is_verified = ?, verified_by = ?, verified_at = NOW()
                WHERE student_id IN ($placeholders)
            ");
            $params = array_merge([$verify_status, $current_user['id']], $student_ids);
            $stmt->execute($params);
            
            $action_text = $verify_status ? 'verified' : 'unverified';
            $count = count($student_ids);
            logActivity('students_bulk_verify', "Bulk {$action_text} {$count} students", $current_user['id']);
            $_SESSION['students_success'] = "{$count} students {$action_text} successfully";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['students_error'] = $e->getMessage();
        logError("Student verification error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF'] . ($student_id ? "?student_id={$student_id}" : ''));
        exit;
    }
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get programs for filtering
    $stmt = $db->prepare("SELECT * FROM programs WHERE is_active = 1 ORDER BY program_name");
    $stmt->execute();
    $programs = $stmt->fetchAll();
    
    // Get classes for filtering
    $stmt = $db->prepare("
        SELECT c.*, l.level_name, p.program_name
        FROM classes c 
        JOIN levels l ON c.level_id = l.level_id 
        JOIN programs p ON c.program_id = p.program_id
        WHERE c.is_active = 1 
        ORDER BY p.program_name, l.level_name, c.class_name
    ");
    $stmt->execute();
    $classes = $stmt->fetchAll();
    
    // Build WHERE clause for filtering (only if not viewing specific student)
    $where_conditions = [];
    $params = [];
    
    if ($student_id) {
        $where_conditions[] = "s.student_id = ?";
        $params[] = $student_id;
    } else {
        // Add search and filter conditions
        if ($search) {
            $where_conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_number LIKE ?)";
            $search_param = '%' . $search . '%';
            $params = array_merge($params, [$search_param, $search_param, $search_param]);
        }
        
        if ($program_filter) {
            $where_conditions[] = "s.program_id = ?";
            $params[] = $program_filter;
        }
        
        if ($class_filter) {
            $where_conditions[] = "s.class_id = ?";
            $params[] = $class_filter;
        }
        
        if ($status_filter === 'verified') {
            $where_conditions[] = "s.is_verified = 1";
        } elseif ($status_filter === 'unverified') {
            $where_conditions[] = "s.is_verified = 0";
        }
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Get total count for pagination (only if not viewing specific student)
    if (!$student_id) {
        $count_query = "
            SELECT COUNT(DISTINCT s.student_id) as total
            FROM students s
            JOIN programs p ON s.program_id = p.program_id
            JOIN classes c ON s.class_id = c.class_id
            JOIN levels l ON c.level_id = l.level_id
            $where_clause
        ";
        $count_stmt = $db->prepare($count_query);
        $count_stmt->execute($params);
        $total_records = $count_stmt->fetch()['total'];
        $total_pages = ceil($total_records / $per_page);
    } else {
        $total_records = 0;
        $total_pages = 1;
    }

    // Get students for verification (prioritize unverified)
    $limit_clause = $student_id ? "" : "LIMIT $per_page OFFSET $offset";
    $students_query = "
        SELECT s.*, p.program_name, c.class_name, l.level_name,
               u_created.first_name as created_by_name,
               u_verified.first_name as verified_by_name
        FROM students s
        JOIN programs p ON s.program_id = p.program_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN levels l ON c.level_id = l.level_id
        LEFT JOIN users u_created ON s.created_by = u_created.user_id
        LEFT JOIN users u_verified ON s.verified_by = u_verified.user_id
        {$where_clause}
        ORDER BY s.is_verified ASC, s.created_at DESC
        {$limit_clause}
    ";
    
    $students_stmt = $db->prepare($students_query);
    $students_stmt->execute($params);
    $students = $students_stmt->fetchAll();
    
    // Get verification statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_students,
            COUNT(CASE WHEN is_verified = 1 THEN 1 END) as verified_students,
            COUNT(CASE WHEN is_verified = 0 THEN 1 END) as pending_students,
            COUNT(CASE WHEN verified_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as verified_this_week
        FROM students
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
} catch (Exception $e) {
    logError("Student verification data fetch error: " . $e->getMessage());
    $_SESSION['students_error'] = "Unable to load verification data";
    header('Location: index');
    exit;
}

include '../../includes/header.php';
?>

<!-- Verify Students Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-user-check me-2"></i>Verify Students
        </h4>
        <small class="text-muted"><?= $student_id ? 'Verify specific student' : 'Review and verify student eligibility' ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="index" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Overview
        </a>
        <a href="manage" class="btn btn-success">
            <i class="fas fa-user-plus me-2"></i>Add Student
        </a>
        <?php if (!$student_id): ?>
            <button type="button" class="btn btn-warning" onclick="showBulkActions()">
                <i class="fas fa-tasks me-2"></i>Bulk Actions
            </button>
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

<!-- Verification Statistics -->
<?php if (!$student_id): ?>
<div class="row mb-4">
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-primary">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_students'] ?></h3>
                <p>Total Students</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-success">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['verified_students'] ?></h3>
                <p>Verified</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-warning">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['pending_students'] ?></h3>
                <p>Pending</p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-md-6">
        <div class="stats-card">
            <div class="stats-icon bg-info">
                <i class="fas fa-calendar-week"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['verified_this_week'] ?></h3>
                <p>This Week</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bulk Actions Panel (Initially Hidden) -->
<?php if (!$student_id): ?>
<div class="card mb-4" id="bulkActionsPanel" style="display: none;">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                <i class="fas fa-tasks me-2"></i>Bulk Verification
            </h5>
            <button type="button" class="btn-close" onclick="hideBulkActions()"></button>
        </div>
    </div>
    <div class="card-body">
        <form method="POST" id="bulkVerifyForm">
            <input type="hidden" name="action" value="bulk_verify">
            <div class="row align-items-end">
                <div class="col-md-6">
                    <label class="form-label">Verification Status</label>
                    <select class="form-select" name="verify_status" required>
                        <option value="1">Verify Students</option>
                        <option value="0">Unverify Students</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <button type="submit" class="btn btn-warning" disabled id="bulkVerifyBtn">
                        <i class="fas fa-check-double me-2"></i>Apply to Selected (<span id="selectedCount">0</span>)
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Students Verification List -->
<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">
                <i class="fas fa-clipboard-list me-2"></i>
                <?= $student_id ? 'Student Verification' : 'Students Pending Verification' ?>
                <?php if (!$student_id && $total_records > 0): ?>
                    <span class="badge bg-secondary"><?= number_format($total_records) ?></span>
                <?php endif; ?>
            </h5>
            <?php if (!$student_id): ?>
                <!-- Search and Filters -->
                <form method="GET" class="d-flex gap-2" id="filterForm">
                    <input type="text" name="search" class="form-control form-control-sm" 
                           placeholder="Search students..." style="width: 200px;" value="<?= sanitize($search) ?>">
                    
                    <select name="program_filter" class="form-select form-select-sm" style="width: auto;" id="programFilter">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?= $program['program_id'] ?>" <?= $program_filter == $program['program_id'] ? 'selected' : '' ?>>
                                <?= sanitize($program['program_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="class_filter" class="form-select form-select-sm" style="width: auto;" id="classFilter">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= $class['class_id'] ?>" 
                                    data-program-id="<?= $class['program_id'] ?>"
                                    <?= $class_filter == $class['class_id'] ? 'selected' : '' ?>>
                                <?= sanitize($class['level_name']) ?> - <?= sanitize($class['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <select name="status_filter" class="form-select form-select-sm" style="width: auto;" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="verified" <?= $status_filter == 'verified' ? 'selected' : '' ?>>Verified</option>
                        <option value="unverified" <?= $status_filter == 'unverified' ? 'selected' : '' ?>>Unverified</option>
                    </select>
                    
                    <select name="per_page" class="form-select form-select-sm" style="width: auto;" id="perPageFilter">
                        <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10</option>
                        <option value="25" <?= $per_page == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                    
                    <a href="?" class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-times"></i>
                    </a>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($students)): ?>
            <div class="text-center py-5">
                <i class="fas fa-user-check text-muted" style="font-size: 4rem;"></i>
                <h4 class="text-muted mt-3">No Students Found</h4>
                <p class="text-muted"><?= $student_id ? 'Student not found.' : 'All students have been verified.' ?></p>
                <a href="manage" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Add Students
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover" id="studentsTable">
                    <thead>
                        <tr>
                            <?php if (!$student_id): ?>
                                <th>
                                    <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                                </th>
                            <?php endif; ?>
                            <th>Student</th>
                            <th>Program & Class</th>
                            <th>Current Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr data-program-id="<?= $student['program_id'] ?>" 
                                data-class-id="<?= $student['class_id'] ?>" 
                                data-verified="<?= $student['is_verified'] ?>">
                                <?php if (!$student_id): ?>
                                    <td>
                                        <input type="checkbox" class="student-checkbox" name="student_ids[]" 
                                               value="<?= $student['student_id'] ?>" onchange="updateBulkActions()">
                                    </td>
                                <?php endif; ?>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="student-avatar me-3">
                                            <i class="fas fa-<?= $student['gender'] === 'Male' ? 'male' : 'female' ?>"></i>
                                        </div>
                                        <div>
                                            <strong><?= sanitize($student['first_name']) ?> <?= sanitize($student['last_name']) ?></strong>
                                            <br><small class="text-muted">ID: <?= sanitize($student['student_number']) ?></small>
                                            <?php if ($student['phone']): ?>
                                                <br><small class="text-muted"><i class="fas fa-phone"></i> <?= sanitize($student['phone']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <strong><?= sanitize($student['program_name']) ?></strong>
                                        <br><small class="text-muted"><?= sanitize($student['level_name']) ?> - <?= sanitize($student['class_name']) ?></small>
                                    </div>
                                </td>
                                <td>
                                    <div>
                                        <?php if ($student['is_verified']): ?>
                                            <span class="badge bg-success mb-1">
                                                <i class="fas fa-check me-1"></i>Verified
                                            </span>
                                            <?php if ($student['verified_at']): ?>
                                                <br><small class="text-muted">
                                                    <?= date('M j, Y', strtotime($student['verified_at'])) ?>
                                                    <?php if ($student['verified_by_name']): ?>
                                                        by <?= sanitize($student['verified_by_name']) ?>
                                                    <?php endif; ?>
                                                </small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-warning">
                                                <i class="fas fa-clock me-1"></i>Pending
                                            </span>
                                        <?php endif; ?>
                                        
                                    </div>
                                </td>
                                <td>
                                    <small class="text-muted">
                                        <?= date('M j, Y', strtotime($student['created_at'])) ?>
                                        <?php if ($student['created_by_name']): ?>
                                            <br>by <?= sanitize($student['created_by_name']) ?>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($student['is_verified']): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="verify_student">
                                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                                <input type="hidden" name="verify_status" value="0">
                                                <input type="hidden" name="notes" value="">
                                                <button type="submit" class="btn btn-warning btn-sm" title="Unverify Student">
                                                    <i class="fas fa-times-circle"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="action" value="verify_student">
                                                <input type="hidden" name="student_id" value="<?= $student['student_id'] ?>">
                                                <input type="hidden" name="verify_status" value="1">
                                                <input type="hidden" name="notes" value="">
                                                <button type="submit" class="btn btn-success btn-sm" title="Verify Student">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                        
                                        <a href="manage?student_id=<?= $student['student_id'] ?>" class="btn btn-outline-primary btn-sm" title="Edit Student">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="../candidates/manage?student_number=<?= $student['student_number'] ?>" class="btn btn-outline-info btn-sm" title="Make Candidate">
                                            <i class="fas fa-user-plus"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if (!$student_id && $total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="text-muted small">
                        Showing <?= (($page - 1) * $per_page) + 1 ?> to <?= min($page * $per_page, $total_records) ?> of <?= number_format($total_records) ?> entries
                    </div>
                    
                    <nav aria-label="Students verification pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if ($start_page > 1) {
                                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a></li>';
                                if ($start_page > 2) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++) {
                                if ($i == $page) {
                                    echo '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
                                } else {
                                    echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $i])) . '">' . $i . '</a></li>';
                                }
                            }
                            
                            if ($end_page < $total_pages) {
                                if ($end_page < $total_pages - 1) {
                                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                }
                                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '">' . $total_pages . '</a></li>';
                            }
                            ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>


<style>
/* Verify Students Styles */

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

.student-avatar {
    width: 40px;
    height: 40px;
    background: #f8f9fa;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    color: #6c757d;
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
    
    .student-avatar {
        width: 35px;
        height: 35px;
        font-size: 1rem;
    }
}
</style>

<script>
// Verify Students JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    
    // Bulk actions functions
    window.showBulkActions = function() {
        document.getElementById('bulkActionsPanel').style.display = 'block';
        updateBulkActions();
    };
    
    window.hideBulkActions = function() {
        document.getElementById('bulkActionsPanel').style.display = 'none';
        document.getElementById('selectAll').checked = false;
        toggleSelectAll();
    };
    
    window.toggleSelectAll = function() {
        const selectAll = document.getElementById('selectAll');
        const checkboxes = document.querySelectorAll('.student-checkbox');
        
        checkboxes.forEach(checkbox => {
            if (checkbox.closest('tr').style.display !== 'none') {
                checkbox.checked = selectAll.checked;
            }
        });
        
        updateBulkActions();
    };
    
    window.updateBulkActions = function() {
        const selectedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
        const count = selectedCheckboxes.length;
        
        document.getElementById('selectedCount').textContent = count;
        document.getElementById('bulkVerifyBtn').disabled = count === 0;
        
        if (count > 0) {
            // Add selected student IDs to the form
            const form = document.getElementById('bulkVerifyForm');
            // Remove existing student_ids inputs
            form.querySelectorAll('input[name="student_ids[]"]').forEach(input => input.remove());
            
            // Add new inputs for selected students
            selectedCheckboxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'student_ids[]';
                input.value = checkbox.value;
                form.appendChild(input);
            });
        }
    };
    
    // Dynamic class filtering based on program selection
    const programFilter = document.getElementById('programFilter');
    const classFilter = document.getElementById('classFilter');
    
    if (programFilter && classFilter) {
        // Store all class options on page load
        const allClassOptions = [];
        classFilter.querySelectorAll('option').forEach(option => {
            allClassOptions.push({
                value: option.value,
                text: option.textContent,
                programId: option.dataset.programId
            });
        });
        
        let isInitialLoad = true;
        
        function handleProgramChange() {
            const selectedProgramId = programFilter.value;
            
            // Clear current options
            classFilter.innerHTML = '<option value="">All Classes</option>';
            
            // Add relevant class options
            allClassOptions.forEach(optionData => {
                if (optionData.value === '') return; // Skip the "All Classes" option from stored data
                
                if (!selectedProgramId || optionData.programId === selectedProgramId) {
                    const option = document.createElement('option');
                    option.value = optionData.value;
                    option.textContent = optionData.text;
                    option.dataset.programId = optionData.programId;
                    classFilter.appendChild(option);
                }
            });
            
            // Reset class filter value since options changed
            classFilter.value = '';
            
            // Auto-submit form only if not initial load
            if (!isInitialLoad) {
                document.getElementById('filterForm').submit();
            }
        }
        
        programFilter.addEventListener('change', handleProgramChange);
        
        // Initialize class filter on page load
        handleProgramChange();
        isInitialLoad = false;
    }
    
    // Auto-submit form when other filters change
    const autoSubmitFilters = ['classFilter', 'statusFilter', 'perPageFilter'];
    autoSubmitFilters.forEach(filterId => {
        const filterElement = document.getElementById(filterId);
        if (filterElement) {
            filterElement.addEventListener('change', function() {
                document.getElementById('filterForm').submit();
            });
        }
    });
    
    // Auto-submit form when search input changes (with debounce)
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 500); // 500ms delay
        });
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