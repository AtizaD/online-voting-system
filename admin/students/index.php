<?php
require_once '../../config/config.php';
require_once '../../auth/session.php';
require_once '../../includes/logger.php';

// Check if user is logged in and is admin
requireAuth(['admin']);

// Get current user
$current_user = getCurrentUser();

$page_title = 'Students Overview';
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../index'],
    ['title' => 'Students Overview']
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

// Pagination and filtering parameters
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = max(10, min(100, intval($_GET['per_page'] ?? 25)));
$search = sanitize($_GET['search'] ?? '');
$program_filter = intval($_GET['program_filter'] ?? 0);
$class_filter = intval($_GET['class_filter'] ?? 0);
$status_filter = sanitize($_GET['status_filter'] ?? '');

$offset = ($page - 1) * $per_page;

// Get data for the page
try {
    $db = Database::getInstance()->getConnection();
    
    // Get programs
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
    
    // Build WHERE clause for filtering
    $where_conditions = [];
    $params = [];
    
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
    } elseif ($status_filter === 'active') {
        $where_conditions[] = "s.is_active = 1";
    } elseif ($status_filter === 'inactive') {
        $where_conditions[] = "s.is_active = 0";
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    // Get total count for pagination
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

    // Get students with pagination
    $students_query = "
        SELECT s.*, p.program_name, c.class_name, l.level_name,
               COUNT(ca.candidate_id) as candidate_count,
               u_created.first_name as created_by_name,
               u_verified.first_name as verified_by_name
        FROM students s
        JOIN programs p ON s.program_id = p.program_id
        JOIN classes c ON s.class_id = c.class_id
        JOIN levels l ON c.level_id = l.level_id
        LEFT JOIN candidates ca ON s.student_id = ca.student_id
        LEFT JOIN users u_created ON s.created_by = u_created.user_id
        LEFT JOIN users u_verified ON s.verified_by = u_verified.user_id
        $where_clause
        GROUP BY s.student_id, s.student_number, s.first_name, s.last_name, s.phone, s.program_id, s.class_id, 
                 s.gender, s.photo_url, s.is_verified, s.verified_by, s.verified_at, s.is_active, s.created_at, 
                 s.updated_at, s.created_by, p.program_name, c.class_name, l.level_name, u_created.first_name, u_verified.first_name
        ORDER BY s.created_at DESC
        LIMIT $per_page OFFSET $offset
    ";
    
    $students_params = array_merge($params);
    $students_stmt = $db->prepare($students_query);
    $students_stmt->execute($students_params);
    $students = $students_stmt->fetchAll();
    
    // Get student statistics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_students,
            COUNT(CASE WHEN is_verified = 1 THEN 1 END) as verified_students,
            COUNT(CASE WHEN is_active = 1 THEN 1 END) as active_students,
            COUNT(CASE WHEN gender = 'Male' THEN 1 END) as male_students,
            COUNT(CASE WHEN gender = 'Female' THEN 1 END) as female_students,
            COUNT(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as new_this_week
        FROM students
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
    // Get recent activity
    $stmt = $db->prepare("
        SELECT s.student_id, s.first_name, s.last_name, s.student_number, s.created_at, s.is_verified
        FROM students s
        ORDER BY s.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_students = $stmt->fetchAll();
    
} catch (Exception $e) {
    logError("Students data fetch error: " . $e->getMessage());
    $_SESSION['students_error'] = "Unable to load students data";
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

include '../../includes/header.php';
?>

<!-- Students Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1">
            <i class="fas fa-graduation-cap me-2"></i>Students Overview
        </h4>
        <small class="text-muted">Monitor and manage student records</small>
    </div>
    <div class="d-flex gap-2">
        <a href="manage" class="btn btn-primary">
            <i class="fas fa-user-plus me-2"></i>Manage Students
        </a>
        <a href="verify" class="btn btn-success">
            <i class="fas fa-user-check me-2"></i>Verify Students
        </a>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                <i class="fas fa-cogs me-2"></i>More Actions
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="bulk-actions"><i class="fas fa-tasks me-2"></i>Bulk Actions</a></li>
                <li><a class="dropdown-item" href="import"><i class="fas fa-upload me-2"></i>Import Students</a></li>
                <li><a class="dropdown-item" href="export"><i class="fas fa-download me-2"></i>Export Data</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="../candidates/"><i class="fas fa-users me-2"></i>Manage Candidates</a></li>
            </ul>
        </div>
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
            <div class="stats-icon bg-primary">
                <i class="fas fa-users"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['total_students'] ?></h3>
                <p>Total Students</p>
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
            <div class="stats-icon bg-warning">
                <i class="fas fa-male"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['male_students'] ?></h3>
                <p>Male</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-danger">
                <i class="fas fa-female"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['female_students'] ?></h3>
                <p>Female</p>
            </div>
        </div>
    </div>
    <div class="col-lg-2 col-md-4 col-sm-6">
        <div class="stats-card">
            <div class="stats-icon bg-secondary">
                <i class="fas fa-plus-circle"></i>
            </div>
            <div class="stats-info">
                <h3><?= $stats['new_this_week'] ?></h3>
                <p>This Week</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Quick Actions Panel -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-tachometer-alt me-2"></i>Quick Actions
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="manage" class="btn btn-outline-primary">
                        <i class="fas fa-user-plus me-2"></i>Add New Student
                    </a>
                    <a href="verify" class="btn btn-outline-success">
                        <i class="fas fa-user-check me-2"></i>Verify Students
                    </a>
                    <a href="bulk-actions" class="btn btn-outline-info">
                        <i class="fas fa-tasks me-2"></i>Bulk Operations
                    </a>
                    <a href="import" class="btn btn-outline-warning">
                        <i class="fas fa-upload me-2"></i>Import CSV
                    </a>
                    <a href="export" class="btn btn-outline-secondary">
                        <i class="fas fa-download me-2"></i>Export Data
                    </a>
                </div>
                
                <hr class="my-3">
                
                <h6 class="fw-bold mb-2">Quick Stats</h6>
                <div class="small">
                    <div class="d-flex justify-content-between py-1">
                        <span>Verification Rate:</span>
                        <strong><?= $stats['total_students'] > 0 ? round(($stats['verified_students'] / $stats['total_students']) * 100) : 0 ?>%</strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span>Pending Verification:</span>
                        <strong><?= $stats['total_students'] - $stats['verified_students'] ?></strong>
                    </div>
                    <div class="d-flex justify-content-between py-1">
                        <span>Gender Ratio (M:F):</span>
                        <strong><?= $stats['male_students'] ?>:<?= $stats['female_students'] ?></strong>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Activity -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-clock me-2"></i>Recent Students
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($recent_students)): ?>
                    <p class="text-muted text-center">No recent students</p>
                <?php else: ?>
                    <?php foreach ($recent_students as $student): ?>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <div>
                                <strong><?= sanitize($student['first_name']) ?> <?= sanitize($student['last_name']) ?></strong>
                                <br><small class="text-muted"><?= sanitize($student['student_number']) ?></small>
                            </div>
                            <div class="text-end">
                                <?php if ($student['is_verified']): ?>
                                    <span class="badge bg-success">Verified</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Pending</span>
                                <?php endif; ?>
                                <br><small class="text-muted"><?= date('M j', strtotime($student['created_at'])) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="text-center mt-3">
                        <a href="manage" class="btn btn-sm btn-outline-primary">View All →</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Students List -->
    <div class="col-lg-8 mb-4">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-list me-2"></i>All Students
                        <?php if ($total_records > 0): ?>
                            <span class="badge bg-secondary"><?= number_format($total_records) ?></span>
                        <?php endif; ?>
                    </h5>
                    
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
                            <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $status_filter == 'inactive' ? 'selected' : '' ?>>Inactive</option>
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
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($students)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-graduation-cap text-muted" style="font-size: 4rem;"></i>
                        <h4 class="text-muted mt-3">No Students Found</h4>
                        <p class="text-muted">Add students to the system to get started.</p>
                        <a href="manage" class="btn btn-primary">
                            <i class="fas fa-user-plus me-2"></i>Add First Student
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="studentsTable">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Program & Class</th>
                                    <th>Status</th>
                                    <th>Candidacy</th>
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
                                                    <span class="badge bg-success mb-1">Verified</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning mb-1">Unverified</span>
                                                <?php endif; ?>
                                                <br>
                                                <?php if ($student['is_active']): ?>
                                                    <span class="badge bg-info">Active</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Inactive</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($student['candidate_count'] > 0): ?>
                                                <span class="badge bg-primary"><?= $student['candidate_count'] ?> positions</span>
                                            <?php else: ?>
                                                <span class="text-muted">Not a candidate</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="manage?student_id=<?= $student['student_id'] ?>" class="btn btn-outline-primary" title="Edit Student">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="verify?student_id=<?= $student['student_id'] ?>" class="btn btn-outline-success" title="Verify Student">
                                                    <i class="fas fa-user-check"></i>
                                                </a>
                                                <?php if ($student['candidate_count'] == 0): ?>
                                                    <a href="../candidates/manage?student_number=<?= $student['student_number'] ?>" class="btn btn-outline-info" title="Make Candidate">
                                                        <i class="fas fa-user-plus"></i>
                                                    </a>
                                                <?php else: ?>
                                                    <a href="../candidates/" class="btn btn-outline-secondary" title="View as Candidate">
                                                        <i class="fas fa-users"></i>
                                                    </a>
                                                <?php endif; ?>
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
                            <div class="text-muted small">
                                Showing <?= (($page - 1) * $per_page) + 1 ?> to <?= min($page * $per_page, $total_records) ?> of <?= number_format($total_records) ?> entries
                            </div>
                            
                            <nav aria-label="Students pagination">
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
    </div>
</div>

<style>
/* Students Overview Styles */

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
// Students Overview JavaScript
document.addEventListener('DOMContentLoaded', function() {
    
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