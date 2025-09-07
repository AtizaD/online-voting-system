<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Manage Students';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Students', 'url' => './index'],
    ['title' => 'Manage Students']
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

// Get student ID if editing
$editing_student = null;
$student_id = intval($_GET['student_id'] ?? 0);

// Pagination and filtering
$page = intval($_GET['page'] ?? 1);
$per_page = intval($_GET['per_page'] ?? 25);
$search = sanitize($_GET['search'] ?? '');
$class_filter = intval($_GET['class_filter'] ?? 0);
$program_filter = intval($_GET['program_filter'] ?? 0);
$status_filter = sanitize($_GET['status_filter'] ?? '');
$gender_filter = sanitize($_GET['gender_filter'] ?? '');

$offset = ($page - 1) * $per_page;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'bulk_verify') {
            $student_ids = $_POST['student_ids'] ?? [];
            if (empty($student_ids)) {
                throw new Exception('No students selected');
            }
            
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
            $stmt = $db->prepare("UPDATE students SET is_verified = 1, verified_by = ?, verified_at = NOW() WHERE student_id IN ($placeholders)");
            $params = array_merge([$current_user['id']], $student_ids);
            $stmt->execute($params);
            
            $affected = $stmt->rowCount();
            logActivity('bulk_verify_students', "Bulk verified $affected students", $current_user['id']);
            $_SESSION['students_success'] = "$affected students verified successfully";
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
            exit;
            
        } elseif ($action === 'bulk_delete') {
            $student_ids = $_POST['student_ids'] ?? [];
            if (empty($student_ids)) {
                throw new Exception('No students selected');
            }
            
            // Check if any selected students are candidates
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
            $stmt = $db->prepare("SELECT COUNT(*) as candidate_count FROM candidates WHERE student_id IN ($placeholders)");
            $stmt->execute($student_ids);
            $candidate_count = $stmt->fetch()['candidate_count'];
            
            if ($candidate_count > 0) {
                throw new Exception('Cannot delete students who are candidates in elections');
            }
            
            $stmt = $db->prepare("DELETE FROM students WHERE student_id IN ($placeholders)");
            $stmt->execute($student_ids);
            
            $affected = $stmt->rowCount();
            logActivity('bulk_delete_students', "Bulk deleted $affected students", $current_user['id']);
            $_SESSION['students_success'] = "$affected students deleted successfully";
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
            exit;
            
        } elseif ($action === 'quick_verify') {
            $student_id = intval($_POST['student_id'] ?? 0);
            if (!$student_id) {
                throw new Exception('Invalid student ID');
            }
            
            $stmt = $db->prepare("UPDATE students SET is_verified = 1, verified_by = ?, verified_at = NOW() WHERE student_id = ?");
            $stmt->execute([$current_user['id'], $student_id]);
            
            // Get student name for logging
            $stmt = $db->prepare("SELECT first_name, last_name FROM students WHERE student_id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();
            
            logActivity('verify_student', "Verified student: {$student['first_name']} {$student['last_name']}", $current_user['id']);
            $_SESSION['students_success'] = 'Student verified successfully';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
            exit;
            
        } elseif ($action === 'quick_delete') {
            $student_id = intval($_POST['student_id'] ?? 0);
            if (!$student_id) {
                throw new Exception('Invalid student ID');
            }
            
            // Check if student is a candidate
            $stmt = $db->prepare("SELECT COUNT(*) as candidate_count FROM candidates WHERE student_id = ?");
            $stmt->execute([$student_id]);
            $candidate_count = $stmt->fetch()['candidate_count'];
            
            if ($candidate_count > 0) {
                throw new Exception('Cannot delete student who is a candidate in elections');
            }
            
            // Get student info for logging
            $stmt = $db->prepare("SELECT first_name, last_name, student_number FROM students WHERE student_id = ?");
            $stmt->execute([$student_id]);
            $student = $stmt->fetch();
            
            if (!$student) {
                throw new Exception('Student not found');
            }
            
            $stmt = $db->prepare("DELETE FROM students WHERE student_id = ?");
            $stmt->execute([$student_id]);
            
            logActivity('delete_student', "Deleted student: {$student['first_name']} {$student['last_name']} ({$student['student_number']})", $current_user['id']);
            $_SESSION['students_success'] = 'Student deleted successfully';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
            exit;
        }
        
    } catch (Exception $e) {
        $_SESSION['students_error'] = $e->getMessage();
        logError("Students management error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
        exit;
    }
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Build WHERE clause for filtering
    $where_conditions = ['1=1'];
    $params = [];
    
    if ($search) {
        $where_conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_number LIKE ?)";
        $search_term = "%{$search}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    if ($class_filter) {
        $where_conditions[] = "s.class_id = ?";
        $params[] = $class_filter;
    }
    
    if ($program_filter) {
        $where_conditions[] = "s.program_id = ?";
        $params[] = $program_filter;
    }
    
    if ($status_filter === 'verified') {
        $where_conditions[] = "s.is_verified = 1";
    } elseif ($status_filter === 'unverified') {
        $where_conditions[] = "s.is_verified = 0";
    } elseif ($status_filter === 'active') {
        $where_conditions[] = "s.is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $where_conditions[] = "s.is_active = 0";
    }
    
    if ($gender_filter) {
        $where_conditions[] = "s.gender = ?";
        $params[] = $gender_filter;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count for pagination
    $count_query = "
        SELECT COUNT(*) as total
        FROM students s
        JOIN programs p ON s.program_id = p.program_id
        JOIN classes c ON s.class_id = c.class_id
        WHERE {$where_clause}
    ";
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetch()['total'];
    
    $total_pages = ceil($total_records / $per_page);
    $start_record = ($page - 1) * $per_page + 1;
    $end_record = min($page * $per_page, $total_records);
    
    // Get students with pagination
    $students_query = "
        SELECT s.*, p.program_name, c.class_name, l.level_name,
               CASE WHEN s.is_verified = 1 THEN 'Verified' ELSE 'Unverified' END as verification_status,
               CASE WHEN s.is_active = 1 THEN 'Active' ELSE 'Inactive' END as activity_status
        FROM students s
        JOIN programs p ON s.program_id = p.program_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN levels l ON c.level_id = l.level_id
        WHERE {$where_clause}
        ORDER BY s.created_at DESC
        LIMIT {$per_page} OFFSET {$offset}
    ";
    $students_stmt = $db->prepare($students_query);
    $students_stmt->execute($params);
    $students = $students_stmt->fetchAll();
    
    // Get programs for filter dropdown
    $stmt = $db->prepare("SELECT * FROM programs WHERE is_active = 1 ORDER BY program_name");
    $stmt->execute();
    $programs = $stmt->fetchAll();
    
    // Get classes for filter dropdown
    $stmt = $db->prepare("
        SELECT c.*, p.program_name, l.level_name 
        FROM classes c 
        JOIN programs p ON c.program_id = p.program_id 
        JOIN levels l ON c.level_id = l.level_id 
        WHERE c.is_active = 1 
        ORDER BY p.program_name, l.level_name, c.class_name
    ");
    $stmt->execute();
    $classes = $stmt->fetchAll();
    
    // Get statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_students,
            COUNT(CASE WHEN is_verified = 1 THEN 1 END) as verified_students,
            COUNT(CASE WHEN is_verified = 0 THEN 1 END) as unverified_students,
            COUNT(CASE WHEN gender = 'Male' THEN 1 END) as male_students,
            COUNT(CASE WHEN gender = 'Female' THEN 1 END) as female_students
        FROM students
        WHERE is_active = 1
    ";
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch();
    
} catch (Exception $e) {
    logError("Students manage data fetch error: " . $e->getMessage());
    $_SESSION['students_error'] = "Unable to load student data";
    header('Location: index');
    exit;
}

include '../../includes/header.php';
?>

<!-- Manage Students Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-users-cog me-2"></i>Manage Students
        </h4>
        <small class="text-muted">Comprehensive student management interface</small>
    </div>
    <div class="d-flex gap-2">
        <a href="add" class="btn btn-success">
            <i class="fas fa-plus me-2"></i>Add New Student
        </a>
        <a href="import" class="btn btn-info">
            <i class="fas fa-upload me-2"></i>Import CSV
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

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-primary text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0"><?= number_format($stats['total_students']) ?></h5>
                        <small>Total Students</small>
                    </div>
                    <i class="fas fa-users fa-2x opacity-75"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0"><?= number_format($stats['verified_students']) ?></h5>
                        <small>Verified</small>
                    </div>
                    <i class="fas fa-user-check fa-2x opacity-75"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-warning text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0"><?= number_format($stats['unverified_students']) ?></h5>
                        <small>Unverified</small>
                    </div>
                    <i class="fas fa-user-clock fa-2x opacity-75"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-info text-white">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="card-title mb-0"><?= number_format($stats['male_students']) ?>M / <?= number_format($stats['female_students']) ?>F</h5>
                        <small>Gender Split</small>
                    </div>
                    <i class="fas fa-venus-mars fa-2x opacity-75"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters and Search -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0">
            <i class="fas fa-filter me-2"></i>Search & Filter Students
        </h5>
    </div>
    <div class="card-body">
        <form method="GET" class="row g-3" id="filterForm">
            <div class="col-md-3">
                <label for="search" class="form-label">Search</label>
                <input type="text" class="form-control" id="search" name="search" 
                       value="<?= sanitize($search) ?>" placeholder="Name or student number...">
            </div>
            <div class="col-md-2">
                <label for="program_filter" class="form-label">Program</label>
                <select class="form-select" id="programFilter" name="program_filter">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $program): ?>
                        <option value="<?= $program['program_id'] ?>" 
                                <?= $program_filter == $program['program_id'] ? 'selected' : '' ?>>
                            <?= sanitize($program['program_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="class_filter" class="form-label">Class</label>
                <select class="form-select" id="classFilter" name="class_filter">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= $class['class_id'] ?>" 
                                data-program-id="<?= $class['program_id'] ?>"
                                <?= $class_filter == $class['class_id'] ? 'selected' : '' ?>>
                            <?= sanitize($class['level_name']) ?> - <?= sanitize($class['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="status_filter" class="form-label">Status</label>
                <select class="form-select" id="statusFilter" name="status_filter">
                    <option value="">All Status</option>
                    <option value="verified" <?= $status_filter === 'verified' ? 'selected' : '' ?>>Verified</option>
                    <option value="unverified" <?= $status_filter === 'unverified' ? 'selected' : '' ?>>Unverified</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-2">
                <label for="gender_filter" class="form-label">Gender</label>
                <select class="form-select" id="genderFilter" name="gender_filter">
                    <option value="">All Genders</option>
                    <option value="Male" <?= $gender_filter === 'Male' ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= $gender_filter === 'Female' ? 'selected' : '' ?>>Female</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label">&nbsp;</label>
                <div class="d-flex gap-1">
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Actions -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            <i class="fas fa-tasks me-2"></i>Student List
            <small class="text-muted">
                (Showing <?= number_format($start_record) ?> to <?= number_format($end_record) ?> of <?= number_format($total_records) ?> students)
            </small>
        </h5>
        <div class="d-flex gap-2">
            <select class="form-select form-select-sm" id="per_page" onchange="changePerPage(this.value)">
                <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10 per page</option>
                <option value="25" <?= $per_page == 25 ? 'selected' : '' ?>>25 per page</option>
                <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50 per page</option>
                <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100 per page</option>
            </select>
        </div>
    </div>
    <div class="card-body">
        <!-- Bulk Actions Bar -->
        <div id="bulk-actions" class="d-none mb-3 p-3 bg-light rounded">
            <div class="d-flex justify-content-between align-items-center">
                <span id="selected-count">0 students selected</span>
                <div class="btn-group">
                    <button type="button" class="btn btn-success btn-sm" onclick="bulkAction('verify')">
                        <i class="fas fa-check me-2"></i>Verify Selected
                    </button>
                    <button type="button" class="btn btn-danger btn-sm" onclick="bulkAction('delete')">
                        <i class="fas fa-trash me-2"></i>Delete Selected
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="clearSelection()">
                        <i class="fas fa-times me-2"></i>Clear Selection
                    </button>
                </div>
            </div>
        </div>

        <!-- Students Table -->
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th width="40">
                            <input type="checkbox" id="select-all" onchange="toggleSelectAll()">
                        </th>
                        <th>Student Details</th>
                        <th>Class & Program</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th width="150">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($students)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                <i class="fas fa-users fa-3x mb-3"></i>
                                <br>No students found matching your criteria
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($students as $student): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="student-checkbox" value="<?= $student['student_id'] ?>">
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="avatar-circle me-3 bg-<?= $student['gender'] === 'Male' ? 'primary' : 'pink' ?> text-white">
                                            <i class="fas fa-<?= $student['gender'] === 'Male' ? 'male' : 'female' ?>"></i>
                                        </div>
                                        <div>
                                            <strong><?= sanitize($student['first_name'] . ' ' . $student['last_name']) ?></strong>
                                            <br>
                                            <small class="text-muted">ID: <?= sanitize($student['student_number']) ?></small>
                                            <br>
                                            <span class="badge bg-<?= $student['gender'] === 'Male' ? 'primary' : 'pink' ?> badge-sm">
                                                <?= $student['gender'] ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= sanitize($student['class_name']) ?></strong>
                                    <br>
                                    <small class="text-muted"><?= sanitize($student['program_name']) ?></small>
                                    <br>
                                    <small class="text-muted"><?= sanitize($student['level_name']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $student['is_verified'] ? 'success' : 'warning' ?> mb-1">
                                        <i class="fas fa-<?= $student['is_verified'] ? 'check' : 'clock' ?> me-1"></i>
                                        <?= $student['verification_status'] ?>
                                    </span>
                                    <br>
                                    <span class="badge bg-<?= $student['is_active'] ? 'primary' : 'secondary' ?>">
                                        <i class="fas fa-<?= $student['is_active'] ? 'user' : 'user-slash' ?> me-1"></i>
                                        <?= $student['activity_status'] ?>
                                    </span>
                                </td>
                                <td>
                                    <small><?= date('M j, Y', strtotime($student['created_at'])) ?></small>
                                    <br>
                                    <small class="text-muted"><?= date('g:i A', strtotime($student['created_at'])) ?></small>
                                </td>
                                <td>
                                    <div class="btn-group-vertical btn-group-sm d-grid gap-1">
                                        <a href="add?student_id=<?= $student['student_id'] ?>" 
                                           class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-edit me-1"></i>Edit
                                        </a>
                                        <?php if (!$student['is_verified']): ?>
                                            <button type="button" class="btn btn-outline-success btn-sm" 
                                                    onclick="quickVerify(<?= $student['student_id'] ?>)">
                                                <i class="fas fa-check me-1"></i>Verify
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm" 
                                                onclick="quickDelete(<?= $student['student_id'] ?>, '<?= addslashes($student['first_name'] . ' ' . $student['last_name']) ?>')">
                                            <i class="fas fa-trash me-1"></i>Delete
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav aria-label="Students pagination" class="mt-4">
                <ul class="pagination justify-content-center">
                    <!-- Previous Page -->
                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    
                    <!-- Page Numbers -->
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    if ($start_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                        </li>
                        <?php if ($start_page > 2): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif;
                    endif;
                    
                    for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor;
                    
                    if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>"><?= $total_pages ?></a>
                        </li>
                    <?php endif; ?>
                    
                    <!-- Next Page -->
                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden Forms for Actions -->
<form id="bulk-verify-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="bulk_verify">
    <div id="bulk-verify-students"></div>
</form>

<form id="bulk-delete-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="bulk_delete">
    <div id="bulk-delete-students"></div>
</form>

<form id="quick-verify-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="quick_verify">
    <input type="hidden" name="student_id" id="quick-verify-student-id">
</form>

<form id="quick-delete-form" method="POST" style="display: none;">
    <input type="hidden" name="action" value="quick_delete">
    <input type="hidden" name="student_id" id="quick-delete-student-id">
</form>

<style>
.avatar-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.bg-pink {
    background-color: #e91e63 !important;
}

.badge-sm {
    font-size: 0.65em;
}

.btn-group-vertical .btn {
    margin-bottom: 2px;
}

.table td {
    vertical-align: middle;
}
</style>

<script>
// Student management JavaScript
let selectedStudents = new Set();

function toggleSelectAll() {
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('.student-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
        if (selectAll.checked) {
            selectedStudents.add(checkbox.value);
        } else {
            selectedStudents.delete(checkbox.value);
        }
    });
    
    updateBulkActions();
}

function updateBulkActions() {
    const bulkActions = document.getElementById('bulk-actions');
    const selectedCount = document.getElementById('selected-count');
    
    if (selectedStudents.size > 0) {
        bulkActions.classList.remove('d-none');
        selectedCount.textContent = `${selectedStudents.size} student${selectedStudents.size > 1 ? 's' : ''} selected`;
    } else {
        bulkActions.classList.add('d-none');
    }
}

function clearSelection() {
    selectedStudents.clear();
    document.getElementById('select-all').checked = false;
    document.querySelectorAll('.student-checkbox').forEach(cb => cb.checked = false);
    updateBulkActions();
}

function bulkAction(action) {
    if (selectedStudents.size === 0) return;
    
    const studentIds = Array.from(selectedStudents);
    
    if (action === 'verify') {
        if (confirm(`Verify ${studentIds.length} selected student${studentIds.length > 1 ? 's' : ''}?`)) {
            const form = document.getElementById('bulk-verify-form');
            const container = document.getElementById('bulk-verify-students');
            container.innerHTML = '';
            
            studentIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'student_ids[]';
                input.value = id;
                container.appendChild(input);
            });
            
            form.submit();
        }
    } else if (action === 'delete') {
        if (confirm(`Delete ${studentIds.length} selected student${studentIds.length > 1 ? 's' : ''}? This action cannot be undone.`)) {
            const form = document.getElementById('bulk-delete-form');
            const container = document.getElementById('bulk-delete-students');
            container.innerHTML = '';
            
            studentIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'student_ids[]';
                input.value = id;
                container.appendChild(input);
            });
            
            form.submit();
        }
    }
}

function quickVerify(studentId) {
    if (confirm('Verify this student?')) {
        document.getElementById('quick-verify-student-id').value = studentId;
        document.getElementById('quick-verify-form').submit();
    }
}

function quickDelete(studentId, studentName) {
    if (confirm(`Delete "${studentName}"? This action cannot be undone.`)) {
        document.getElementById('quick-delete-student-id').value = studentId;
        document.getElementById('quick-delete-form').submit();
    }
}

function changePerPage(perPage) {
    const url = new URL(window.location);
    url.searchParams.set('per_page', perPage);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}

// Setup event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Handle individual checkbox changes
    document.querySelectorAll('.student-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                selectedStudents.add(this.value);
            } else {
                selectedStudents.delete(this.value);
            }
            updateBulkActions();
            
            // Update select-all checkbox
            const allCheckboxes = document.querySelectorAll('.student-checkbox');
            const checkedCheckboxes = document.querySelectorAll('.student-checkbox:checked');
            document.getElementById('select-all').checked = allCheckboxes.length === checkedCheckboxes.length;
        });
    });
    
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
    const autoSubmitFilters = ['classFilter', 'statusFilter', 'genderFilter'];
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
});
</script>

<?php include '../../includes/footer.php'; ?>