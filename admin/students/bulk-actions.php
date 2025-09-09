<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Bulk Student Operations';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Students', 'url' => './index'],
    ['title' => 'Bulk Actions']
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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db = Database::getInstance()->getConnection();
        
        if ($action === 'bulk_verify') {
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
            
        } elseif ($action === 'bulk_activate') {
            $student_ids = $_POST['student_ids'] ?? [];
            $active_status = intval($_POST['active_status'] ?? 1);
            
            if (empty($student_ids)) {
                throw new Exception('No students selected');
            }
            
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
            $stmt = $db->prepare("
                UPDATE students 
                SET is_active = ?, updated_at = NOW()
                WHERE student_id IN ($placeholders)
            ");
            $params = array_merge([$active_status], $student_ids);
            $stmt->execute($params);
            
            $action_text = $active_status ? 'activated' : 'deactivated';
            $count = count($student_ids);
            logActivity('students_bulk_activate', "Bulk {$action_text} {$count} students", $current_user['id']);
            $_SESSION['students_success'] = "{$count} students {$action_text} successfully";
            
        } elseif ($action === 'bulk_delete') {
            $student_ids = $_POST['student_ids'] ?? [];
            $confirm_delete = $_POST['confirm_delete'] ?? '';
            
            if (empty($student_ids)) {
                throw new Exception('No students selected');
            }
            
            if ($confirm_delete !== 'DELETE') {
                throw new Exception('Please type "DELETE" to confirm bulk deletion');
            }
            
            // Check if any selected students are candidates
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
            $stmt = $db->prepare("
                SELECT COUNT(*) as candidate_count 
                FROM candidates 
                WHERE student_id IN ($placeholders)
            ");
            $stmt->execute($student_ids);
            $candidates = $stmt->fetch();
            
            if ($candidates['candidate_count'] > 0) {
                throw new Exception('Cannot delete students who are candidates in elections');
            }
            
            // Get student names for logging
            $stmt = $db->prepare("
                SELECT first_name, last_name, student_number 
                FROM students 
                WHERE student_id IN ($placeholders)
            ");
            $stmt->execute($student_ids);
            $student_names = $stmt->fetchAll();
            
            // Delete students
            $stmt = $db->prepare("DELETE FROM students WHERE student_id IN ($placeholders)");
            $stmt->execute($student_ids);
            
            $count = count($student_ids);
            $names = array_map(function($s) { return "{$s['first_name']} {$s['last_name']} ({$s['student_number']})"; }, $student_names);
            logActivity('students_bulk_delete', "Bulk deleted {$count} students: " . implode(', ', $names), $current_user['id']);
            $_SESSION['students_success'] = "{$count} students deleted successfully";
            
        } elseif ($action === 'bulk_assign_program') {
            $student_ids = $_POST['student_ids'] ?? [];
            $program_id = intval($_POST['program_id'] ?? 0);
            $class_id = intval($_POST['class_id'] ?? 0);
            
            if (empty($student_ids)) {
                throw new Exception('No students selected');
            }
            
            if (!$program_id || !$class_id) {
                throw new Exception('Program and class must be selected');
            }
            
            // Verify program and class combination
            $stmt = $db->prepare("SELECT class_id FROM classes WHERE class_id = ? AND program_id = ?");
            $stmt->execute([$class_id, $program_id]);
            if (!$stmt->fetch()) {
                throw new Exception('Invalid program and class combination');
            }
            
            $placeholders = str_repeat('?,', count($student_ids) - 1) . '?';
            $stmt = $db->prepare("
                UPDATE students 
                SET program_id = ?, class_id = ?, updated_at = NOW()
                WHERE student_id IN ($placeholders)
            ");
            $params = array_merge([$program_id, $class_id], $student_ids);
            $stmt->execute($params);
            
            $count = count($student_ids);
            logActivity('students_bulk_program', "Bulk assigned {$count} students to program/class", $current_user['id']);
            $_SESSION['students_success'] = "{$count} students assigned to new program/class successfully";
        }
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
        
    } catch (Exception $e) {
        $_SESSION['students_error'] = $e->getMessage();
        logError("Bulk student operations error: " . $e->getMessage());
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get filter parameters
    $search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
    $program_filter = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
    $class_filter = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
    $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
    $gender_filter = isset($_GET['gender']) ? $_GET['gender'] : '';
    $per_page = isset($_GET['per_page']) ? max(10, min(100, (int)$_GET['per_page'])) : 25;
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $offset = ($page - 1) * $per_page;
    
    // Build WHERE conditions
    $where_conditions = [];
    $params = [];
    
    if (!empty($search)) {
        $where_conditions[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.student_number LIKE ? OR CONCAT(s.first_name, ' ', s.last_name) LIKE ?)";
        $search_term = "%{$search}%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    }
    
    if ($program_filter > 0) {
        $where_conditions[] = "s.program_id = ?";
        $params[] = $program_filter;
    }
    
    if ($class_filter > 0) {
        $where_conditions[] = "s.class_id = ?";
        $params[] = $class_filter;
    }
    
    if (!empty($status_filter)) {
        switch ($status_filter) {
            case 'verified':
                $where_conditions[] = "s.is_verified = 1";
                break;
            case 'unverified':
                $where_conditions[] = "s.is_verified = 0";
                break;
            case 'active':
                $where_conditions[] = "s.is_active = 1";
                break;
            case 'inactive':
                $where_conditions[] = "s.is_active = 0";
                break;
        }
    }
    
    if (!empty($gender_filter)) {
        $where_conditions[] = "s.gender = ?";
        $params[] = $gender_filter;
    }
    
    $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
    
    // Get programs
    $stmt = $db->prepare("SELECT * FROM programs WHERE is_active = 1 ORDER BY program_name");
    $stmt->execute();
    $programs = $stmt->fetchAll();
    
    // Get classes with program info
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
    
    // Count total filtered students
    $count_sql = "
        SELECT COUNT(DISTINCT s.student_id) as total
        FROM students s
        JOIN programs p ON s.program_id = p.program_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN levels l ON c.level_id = l.level_id
        $where_clause
    ";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetch()['total'];
    $total_pages = ceil($total_records / $per_page);
    
    // Get filtered and paginated students
    $students_sql = "
        SELECT s.*, p.program_name, c.class_name, l.level_name,
               COUNT(ca.candidate_id) as candidate_count,
               vu.first_name as verified_by_name, vu.last_name as verified_by_lastname
        FROM students s
        JOIN programs p ON s.program_id = p.program_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN levels l ON c.level_id = l.level_id
        LEFT JOIN candidates ca ON s.student_id = ca.student_id
        LEFT JOIN users vu ON s.verified_by = vu.user_id
        $where_clause
        GROUP BY s.student_id, s.student_number, s.first_name, s.last_name, s.phone, s.program_id, s.class_id, 
                 s.gender, s.photo_url, s.is_verified, s.verified_by, s.verified_at, s.is_active, s.created_at, 
                 s.updated_at, s.created_by, p.program_name, c.class_name, l.level_name, vu.first_name, vu.last_name
        ORDER BY s.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $students_params = array_merge($params, [$per_page, $offset]);
    $stmt = $db->prepare($students_sql);
    $stmt->execute($students_params);
    $students = $stmt->fetchAll();
    
    // Get student statistics (overall and filtered)
    $overall_stats_sql = "
        SELECT 
            COUNT(*) as total_students,
            COUNT(CASE WHEN s.is_verified = 1 THEN 1 END) as verified_students,
            COUNT(CASE WHEN s.is_active = 1 THEN 1 END) as active_students,
            COUNT(CASE WHEN s.is_verified = 0 THEN 1 END) as unverified_students,
            COUNT(CASE WHEN s.is_active = 0 THEN 1 END) as inactive_students
        FROM students s
        JOIN programs p ON s.program_id = p.program_id
        JOIN classes c ON s.class_id = c.class_id
        $where_clause
    ";
    $stmt = $db->prepare($overall_stats_sql);
    $stmt->execute($params);
    $stats = $stmt->fetch();
    
    // Get overall stats without filters for comparison
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_all,
            COUNT(CASE WHEN s.is_verified = 1 THEN 1 END) as verified_all,
            COUNT(CASE WHEN s.is_active = 1 THEN 1 END) as active_all
        FROM students s
    ");
    $stmt->execute();
    $overall_stats = $stmt->fetch();
    
} catch (Exception $e) {
    logError("Bulk actions data fetch error: " . $e->getMessage());
    $_SESSION['students_error'] = "Unable to load bulk actions data";
    header('Location: index');
    exit;
}

include '../../includes/header.php';
?>

<!-- Bulk Actions Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-tasks me-2"></i>Bulk Student Operations
        </h4>
        <small class="text-muted">Perform operations on multiple students at once</small>
    </div>
    <div class="d-flex gap-2">
        <a href="index" class="btn btn-outline-secondary">
            <i class="fas fa-arrow-left me-2"></i>Back to Overview
        </a>
        <a href="manage" class="btn btn-success">
            <i class="fas fa-user-plus me-2"></i>Add Student
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

<!-- Bulk Operations Overview -->
<div class="row mb-4">
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-primary">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-info">
                <h3><?= number_format($stats['total_students']) ?></h3>
                <p>Filtered Students</p>
                <?php if ($search || $program_filter || $class_filter || $status_filter || $gender_filter): ?>
                    <small class="text-muted">of <?= number_format($overall_stats['total_all']) ?> total</small>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
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
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-warning">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['unverified_students'] ?></h3>
                <p>Unverified</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-info">
                <i class="fas fa-user-check"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['active_students'] ?></h3>
                <p>Active</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-secondary">
                <i class="fas fa-user-times"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['inactive_students'] ?></h3>
                <p>Inactive</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-primary">
                <i class="fas fa-hand-pointer"></i>
            </div>
            <div class="stats-info">
                <h3 id="selectedCount">0</h3>
                <p>Selected</p>
            </div>
        </div>
    </div>
</div>

<!-- Bulk Action Panels -->
<div class="row mb-4">
    <!-- Verification Panel -->
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="fas fa-user-check me-2"></i>Verification
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" id="bulkVerifyForm">
                    <input type="hidden" name="action" value="bulk_verify">
                    <div class="mb-3">
                        <select class="form-select form-select-sm" name="verify_status" required>
                            <option value="1">Verify Students</option>
                            <option value="0">Unverify Students</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success btn-sm w-100" disabled id="verifyBtn">
                        <i class="fas fa-check me-1"></i>Apply (<span class="selected-count">0</span>)
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Activation Panel -->
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="fas fa-power-off me-2"></i>Activation
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" id="bulkActivateForm">
                    <input type="hidden" name="action" value="bulk_activate">
                    <div class="mb-3">
                        <select class="form-select form-select-sm" name="active_status" required>
                            <option value="1">Activate Students</option>
                            <option value="0">Deactivate Students</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-info btn-sm w-100" disabled id="activateBtn">
                        <i class="fas fa-power-off me-1"></i>Apply (<span class="selected-count">0</span>)
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Program Assignment Panel -->
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="fas fa-exchange-alt me-2"></i>Program Assignment
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" id="bulkProgramForm">
                    <input type="hidden" name="action" value="bulk_assign_program">
                    <div class="mb-2">
                        <select class="form-select form-select-sm" name="program_id" required onchange="loadBulkClasses(this.value)">
                            <option value="">Choose program...</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= $program['program_id'] ?>"><?= sanitize($program['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <select class="form-select form-select-sm" name="class_id" id="bulk_class_id" required disabled>
                            <option value="">First select program...</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-warning btn-sm w-100" disabled id="programBtn">
                        <i class="fas fa-exchange-alt me-1"></i>Assign (<span class="selected-count">0</span>)
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Deletion Panel -->
    <div class="col-lg-3 col-md-6 mb-3">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h6 class="card-title mb-0">
                    <i class="fas fa-trash me-2"></i>Deletion
                </h6>
            </div>
            <div class="card-body">
                <form method="POST" id="bulkDeleteForm">
                    <input type="hidden" name="action" value="bulk_delete">
                    <div class="mb-3">
                        <input type="text" class="form-control form-control-sm" name="confirm_delete" placeholder="Type 'DELETE' to confirm" required pattern="DELETE">
                    </div>
                    <button type="submit" class="btn btn-danger btn-sm w-100" disabled id="deleteBtn">
                        <i class="fas fa-trash me-1"></i>Delete (<span class="selected-count">0</span>)
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Students List -->
<div class="card">
    <div class="card-header">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="card-title mb-0">
                <i class="fas fa-list me-2"></i>Select Students
                <?php if ($search || $program_filter || $class_filter || $status_filter || $gender_filter): ?>
                    <span class="badge bg-info ms-2">
                        <?= number_format($total_records) ?> filtered
                    </span>
                <?php else: ?>
                    <span class="badge bg-secondary ms-2">
                        <?= number_format($total_records) ?> total
                    </span>
                <?php endif; ?>
            </h5>
            <div class="d-flex gap-2 align-items-center">
                <div class="d-flex align-items-center me-3">
                    <small class="text-muted me-2">Per page:</small>
                    <select class="form-select form-select-sm" style="width: auto;" onchange="changePerPage(this.value)">
                        <option value="10" <?= $per_page == 10 ? 'selected' : '' ?>>10</option>
                        <option value="25" <?= $per_page == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $per_page == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $per_page == 100 ? 'selected' : '' ?>>100</option>
                    </select>
                </div>
                <button class="btn btn-outline-secondary btn-sm" onclick="selectAllVisible()">
                    <i class="fas fa-check-square me-1"></i>Select Page
                </button>
                <button class="btn btn-outline-secondary btn-sm" onclick="selectNone()">
                    <i class="fas fa-square me-1"></i>Clear All
                </button>
            </div>
        </div>
        
        <!-- Search and Filter Controls -->
        <div class="border-top pt-3">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Search by name or student number...">
                    </div>
                </div>
                
                <div class="col-md-2">
                    <select class="form-select form-select-sm" name="program_id" onchange="loadFilterClasses(this.value)">
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
                    <select class="form-select form-select-sm" name="class_id" id="filter_class_id">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <?php if (!$program_filter || $class['program_id'] == $program_filter): ?>
                                <option value="<?= $class['class_id'] ?>" 
                                        data-program="<?= $class['program_id'] ?>"
                                        <?= $class_filter == $class['class_id'] ? 'selected' : '' ?>>
                                    <?= sanitize($class['level_name'] . ' - ' . $class['class_name']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <select class="form-select form-select-sm" name="status">
                        <option value="">All Status</option>
                        <option value="verified" <?= $status_filter === 'verified' ? 'selected' : '' ?>>Verified</option>
                        <option value="unverified" <?= $status_filter === 'unverified' ? 'selected' : '' ?>>Unverified</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="col-md-1">
                    <select class="form-select form-select-sm" name="gender">
                        <option value="">Gender</option>
                        <option value="Male" <?= $gender_filter === 'Male' ? 'selected' : '' ?>>Male</option>
                        <option value="Female" <?= $gender_filter === 'Female' ? 'selected' : '' ?>>Female</option>
                    </select>
                </div>
                
                <div class="col-md-2">
                    <div class="btn-group w-100">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fas fa-filter me-1"></i>Filter
                        </button>
                        <a href="bulk-actions" class="btn btn-outline-secondary btn-sm" title="Reset">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($students)): ?>
            <div class="text-center py-5">
                <i class="fas fa-users text-muted" style="font-size: 4rem;"></i>
                <h4 class="text-muted mt-3">No Students Found</h4>
                <p class="text-muted">Add students to the system first.</p>
                <a href="manage" class="btn btn-primary">
                    <i class="fas fa-user-plus me-2"></i>Add Students
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-sm" id="studentsTable">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAll" onchange="toggleSelectAll()">
                            </th>
                            <th>Student</th>
                            <th>Program & Class</th>
                            <th>Status</th>
                            <th>Verification</th>
                            <th>Candidate</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <tr data-program-id="<?= $student['program_id'] ?>" 
                                data-gender="<?= $student['gender'] ?>"
                                data-verified="<?= $student['is_verified'] ?>"
                                data-active="<?= $student['is_active'] ?>">
                                <td>
                                    <input type="checkbox" class="student-checkbox" 
                                           value="<?= $student['student_id'] ?>" 
                                           onchange="updateBulkActions()">
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="student-avatar me-2">
                                            <i class="fas fa-<?= $student['gender'] === 'Male' ? 'male' : 'female' ?>"></i>
                                        </div>
                                        <div>
                                            <strong><?= sanitize($student['first_name']) ?> <?= sanitize($student['last_name']) ?></strong>
                                            <br><small class="text-muted"><?= sanitize($student['student_number']) ?></small>
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
                                    <span class="badge bg-<?= $student['is_active'] ? 'info' : 'secondary' ?> badge-sm">
                                        <?= $student['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td>
                                    <div>
                                        <span class="badge bg-<?= $student['is_verified'] ? 'success' : 'warning' ?> badge-sm">
                                            <?= $student['is_verified'] ? 'Verified' : 'Unverified' ?>
                                        </span>
                                        <?php if ($student['is_verified'] && $student['verified_by_name']): ?>
                                            <br><small class="text-muted">
                                                by <?= sanitize($student['verified_by_name'] . ' ' . $student['verified_by_lastname']) ?>
                                                <?php if ($student['verified_at']): ?>
                                                    <br><?= date('M j, Y', strtotime($student['verified_at'])) ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <?php if ($student['candidate_count'] > 0): ?>
                                        <span class="badge bg-primary badge-sm"><?= $student['candidate_count'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="manage?student_id=<?= $student['student_id'] ?>" class="btn btn-outline-primary btn-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="verify?student_id=<?= $student['student_id'] ?>" class="btn btn-outline-success btn-sm" title="Verify">
                                            <i class="fas fa-user-check"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center mt-3">
                    <div class="pagination-info">
                        <small class="text-muted">
                            Showing <?= number_format(($page - 1) * $per_page + 1) ?> to 
                            <?= number_format(min($page * $per_page, $total_records)) ?> of 
                            <?= number_format($total_records) ?> entries
                        </small>
                    </div>
                    
                    <nav aria-label="Students pagination">
                        <ul class="pagination pagination-sm mb-0">
                            <!-- First Page -->
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= buildPaginationUrl(1) ?>">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                            </li>
                            
                            <!-- Previous Page -->
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= buildPaginationUrl($page - 1) ?>">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            </li>
                            
                            <!-- Page Numbers -->
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            if ($start_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= buildPaginationUrl(1) ?>">1</a>
                                </li>
                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= buildPaginationUrl($i) ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>
                                <li class="page-item">
                                    <a class="page-link" href="<?= buildPaginationUrl($total_pages) ?>"><?= $total_pages ?></a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Next Page -->
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= buildPaginationUrl($page + 1) ?>">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                            </li>
                            
                            <!-- Last Page -->
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= buildPaginationUrl($total_pages) ?>">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php
// Helper function to build pagination URLs
function buildPaginationUrl($page_num) {
    global $search, $program_filter, $class_filter, $status_filter, $gender_filter, $per_page;
    
    $params = ['page' => $page_num];
    
    if (!empty($search)) $params['search'] = $search;
    if ($program_filter) $params['program_id'] = $program_filter;
    if ($class_filter) $params['class_id'] = $class_filter;
    if (!empty($status_filter)) $params['status'] = $status_filter;
    if (!empty($gender_filter)) $params['gender'] = $gender_filter;
    if ($per_page !== 25) $params['per_page'] = $per_page;
    
    return 'bulk-actions?' . http_build_query($params);
}
?>

<style>
/* Bulk Actions Styles */

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
    width: 30px;
    height: 30px;
    background: #f8f9fa;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    color: #6c757d;
}

.badge-sm {
    font-size: 0.7rem;
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
        width: 25px;
        height: 25px;
        font-size: 0.875rem;
    }
}
</style>

<script>
// Bulk Actions JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
    // Classes data for dynamic loading
    const classesData = <?= json_encode($classes) ?>;
    
    // Load classes for filter
    window.loadFilterClasses = function(programId) {
        const classSelect = document.getElementById('filter_class_id');
        const currentClassId = '<?= $class_filter ?>';
        
        // Show all classes initially
        Array.from(classSelect.options).forEach(option => {
            if (option.value === '') return; // Keep "All Classes" option
            
            const optionProgramId = option.dataset.program;
            if (!programId || optionProgramId == programId) {
                option.style.display = '';
            } else {
                option.style.display = 'none';
                if (option.selected) option.selected = false;
            }
        });
        
        // If current class is not in selected program, reset to "All Classes"
        if (programId && currentClassId) {
            const currentOption = classSelect.querySelector(`option[value="${currentClassId}"]`);
            if (currentOption && currentOption.dataset.program != programId) {
                classSelect.value = '';
            }
        }
    };
    
    // Load classes for bulk program assignment
    window.loadBulkClasses = function(programId) {
        const classSelect = document.getElementById('bulk_class_id');
        classSelect.innerHTML = '<option value="">Choose class...</option>';
        
        if (!programId) {
            classSelect.disabled = true;
            return;
        }
        
        const programClasses = classesData.filter(c => c.program_id == programId);
        
        if (programClasses.length === 0) {
            classSelect.innerHTML = '<option value="">No classes available</option>';
            classSelect.disabled = true;
            return;
        }
        
        classSelect.disabled = false;
        programClasses.forEach(cls => {
            const option = document.createElement('option');
            option.value = cls.class_id;
            option.textContent = `${cls.level_name} - ${cls.class_name}`;
            classSelect.appendChild(option);
        });
    };
    
    // Per page change function
    window.changePerPage = function(newPerPage) {
        const url = new URL(window.location);
        url.searchParams.set('per_page', newPerPage);
        url.searchParams.set('page', '1'); // Reset to first page
        window.location.href = url.toString();
    };
    
    // Selection functions
    window.selectAllVisible = function() {
        const checkboxes = document.querySelectorAll('.student-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = true;
        });
        updateBulkActions();
    };
    
    window.selectNone = function() {
        const checkboxes = document.querySelectorAll('.student-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.checked = false;
        });
        updateBulkActions();
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
        
        // Update count displays
        document.getElementById('selectedCount').textContent = count;
        document.querySelectorAll('.selected-count').forEach(el => {
            el.textContent = count;
        });
        
        // Enable/disable buttons
        document.getElementById('verifyBtn').disabled = count === 0;
        document.getElementById('activateBtn').disabled = count === 0;
        document.getElementById('programBtn').disabled = count === 0;
        document.getElementById('deleteBtn').disabled = count === 0;
        
        // Update forms with selected student IDs
        const forms = ['bulkVerifyForm', 'bulkActivateForm', 'bulkProgramForm', 'bulkDeleteForm'];
        forms.forEach(formId => {
            const form = document.getElementById(formId);
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
        });
    };
    
    // Initialize filter on page load
    const currentProgramFilter = '<?= $program_filter ?>';
    if (currentProgramFilter) {
        loadFilterClasses(currentProgramFilter);
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